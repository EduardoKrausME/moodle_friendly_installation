<?php

use app\Auth;
use app\JobManager;
use app\MoodlePluginManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
require_once __DIR__ . "/app/MoodlePluginManager.php";

Auth::requireLogin();

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? trim($_GET["domain"]) : "";
$site = SiteManager::get($domain);
if ($site === null) {
    http_response_code(404);
    render_header(t("moodle_plugins.title"));
    echo '<div class="alert danger">' . htmlspecialchars(t("moodle_plugins.not_found"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . '</div>';
    render_footer();
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();
    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "";

    try {
        if (in_array($action, ["install_plugin", "update_plugin", "request_git_link", "confirm_git_link"], true)
            && JobManager::activeJob("plugin_upgrade", $domain) !== null) {
            throw new RuntimeException(t("moodle_plugins.upgrade_busy"));
        }
        if ($action === "install_plugin" || $action === "request_git_link") {
            $giturl = isset($_POST["git_url"]) && is_string($_POST["git_url"]) ? trim($_POST["git_url"]) : "";
            if ($giturl === "") {
                throw new RuntimeException(t("moodle_plugins.git_url_required"));
            }
            $remote = MoodlePluginManager::inspectRemoteVersion($giturl);
            $existing = MoodlePluginManager::localInstallation($site, $remote["component"]);
            if ($existing !== null) {
                // Confirmation is server-side and scoped to this exact Moodle site and repository.
                $_SESSION["plugin_git_link_request"] = [
                    "domain" => $domain,
                    "component" => $remote["component"],
                    "git_url" => $giturl,
                    "path" => $existing["path"],
                    "nonce" => bin2hex(random_bytes(16)),
                ];
                $_SESSION["flash"] = t("moodle_plugins.link_existing_found", ["component" => $remote["component"]]);
            } else if ($action === "request_git_link") {
                throw new RuntimeException(t("moodle_plugins.link_existing_missing"));
            } else {
                $result = MoodlePluginManager::install($site, $giturl);
                JobManager::createPluginUpgradeJob($site, $result["component"]);
                $_SESSION["flash"] = t("moodle_plugins.install_success", ["component" => $result["component"]]);
            }
        } else if ($action === "confirm_git_link") {
            $pending = $_SESSION["plugin_git_link_request"] ?? null;
            $nonce = isset($_POST["link_nonce"]) && is_string($_POST["link_nonce"]) ? $_POST["link_nonce"] : "";
            if (!is_array($pending) || ($pending["domain"] ?? "") !== $domain
                || !is_string($pending["nonce"] ?? null)
                || !hash_equals($pending["nonce"], $nonce)) {
                throw new RuntimeException(t("moodle_plugins.link_confirmation_expired"));
            }
            $current = MoodlePluginManager::localInstallation($site, (string)$pending["component"]);
            if ($current === null || $current["path"] !== $pending["path"]) {
                throw new RuntimeException(t("moodle_plugins.link_confirmation_expired"));
            }
            $result = MoodlePluginManager::linkExisting($site, (string)$pending["git_url"], (string)$pending["component"]);
            unset($_SESSION["plugin_git_link_request"]);
            $_SESSION["flash"] = t("moodle_plugins.link_success", ["component" => $result["component"]])
                . (!empty($result["tracked_changes"]) ? " " . t("moodle_plugins.link_local_changes") : "");
        } else if ($action === "cancel_git_link") {
            $pending = $_SESSION["plugin_git_link_request"] ?? null;
            if (is_array($pending) && ($pending["domain"] ?? "") === $domain) {
                unset($_SESSION["plugin_git_link_request"]);
            }
            $_SESSION["flash"] = t("moodle_plugins.link_cancelled");
        } else if ($action === "update_plugin") {
            $component = isset($_POST["component"]) && is_string($_POST["component"]) ? $_POST["component"] : "";
            $result = MoodlePluginManager::update($site, $component);
            JobManager::createPluginUpgradeJob($site, $result["component"]);
            $_SESSION["flash"] = t("moodle_plugins.update_success", ["component" => $result["component"]]);
        } else if ($action === "run_plugin_upgrade") {
            JobManager::createPluginUpgradeJob($site, "core");
            $_SESSION["flash"] = t("moodle_plugins.upgrade_queued");
        }
    } catch (Throwable $e) {
        $_SESSION["flash"] = t("moodle_plugins.error_prefix") . $e->getMessage();
        $_SESSION["plugin_action_error"] = true;
    }

    redirect_to("/moodle_plugins.php?domain=" . rawurlencode($domain));
}

$flash = flash_message();
$actionerror = !empty($_SESSION["plugin_action_error"]);
unset($_SESSION["plugin_action_error"]);
// Legacy session state from the former manual Site administration flow.
unset($_SESSION["plugin_install_component"]);
$upgradejob = JobManager::latestPluginUpgradeJob($domain);
$upgradebusy = JobManager::activeJob("plugin_upgrade", $domain) !== null;
$upgradestatus = (string)($upgradejob["status"] ?? "");
$upgradestatuskey = in_array($upgradestatus, ["pending", "running", "done", "failed"], true)
    ? $upgradestatus : "pending";

$linkpending = $_SESSION["plugin_git_link_request"] ?? null;
if (!is_array($linkpending) || ($linkpending["domain"] ?? "") !== $domain) {
    $linkpending = null;
}
$forcerefresh = isset($_GET["refresh"]) && $_GET["refresh"] === "1";
$plugins = MoodlePluginManager::installed($site, true, $forcerefresh);
$updatecount = 0;
foreach ($plugins as $index => &$plugin) {
    $plugin["row_id"] = "plugin-details-" . $index;
    $plugin["type"] = strstr($plugin["component"], "_", true) ?: "";
    $plugin["local_version"] = $plugin["version"] !== "" ? $plugin["version"] : "-";
    $plugin["remote_version_display"] = $plugin["remote_version"] !== "" ? $plugin["remote_version"] : "-";
    $plugin["local_release"] = $plugin["release"] !== "" ? $plugin["release"] : "-";
    $plugin["remote_release_display"] = $plugin["remote_release"] !== "" ? $plugin["remote_release"] : "-";
    $plugin["short_local_commit"] = $plugin["local_commit"] !== "" ? substr($plugin["local_commit"], 0, 10) : "-";
    $plugin["short_remote_commit"] = $plugin["remote_commit"] !== "" ? substr($plugin["remote_commit"], 0, 10) : "-";
    $plugin["has_check_error"] = $plugin["check_error"] !== "";
    if (!empty($plugin["update_available"])) {
        $updatecount++;
        $plugin["status_badge_html"] = status_badge("warning", t("moodle_plugins.status_version_different"));
        $plugin["update_button_label"] = t("moodle_plugins.action_update_now");
        $plugin["can_update"] = !$upgradebusy;
    } else if ($plugin["check_error"] !== "") {
        $plugin["status_badge_html"] = status_badge("muted", t("moodle_plugins.status_check_failed"));
        $plugin["update_button_label"] = t("moodle_plugins.action_unavailable");
        $plugin["can_update"] = false;
    } else {
        $plugin["status_badge_html"] = status_badge("ok", t("moodle_plugins.status_same_version"));
        $plugin["update_button_label"] = t("moodle_plugins.action_updated");
        $plugin["can_update"] = false;
    }
    $plugin["csrf_token"] = csrf_token();
}
unset($plugin);

$installedcomponents = [];
foreach ($plugins as $plugin) {
    $installedcomponents[$plugin["component"]] = true;
}

$marketplacetypeicons = [
    "mod" => "icon-activity",
    "local" => "icon-settings",
    "theme" => "icon-dashboard",
    "block" => "icon-layers",
    "tool" => "icon-settings",
    "report" => "icon-activity",
    "qtype" => "icon-check",
    "qbank" => "icon-check",
    "filter" => "icon-layers",
    "auth" => "icon-users",
    "enrol" => "icon-users",
    "repository" => "icon-database",
    "media" => "icon-mobile",
    "profilefield" => "icon-users",
    "message" => "icon-logs",
    "h5plib" => "icon-layers",
];
$marketplacetypeorder = array_flip([
    "mod", "local", "theme", "block", "tool", "report", "qtype", "qbank",
    "filter", "auth", "enrol", "repository", "media", "profilefield", "message", "h5plib",
]);
$marketplacetypecounts = [];
$marketplaceplugins = [];
$unmanagedplugins = [];
$marketplacerepositories = [];
$marketplaceerror = "";
try {
    $marketplaceplugins = MoodlePluginManager::marketplaceCatalog();
    foreach ($marketplaceplugins as &$marketplaceplugin) {
        $marketplacetype = strstr($marketplaceplugin["component"], "_", true) ?: "";
        $marketplaceplugin["type"] = $marketplacetype;
        $marketplaceplugin["type_display"] = strtoupper($marketplacetype);
        if ($marketplacetype !== "") {
            $marketplacetypecounts[$marketplacetype] = ($marketplacetypecounts[$marketplacetype] ?? 0) + 1;
        }
        $marketplaceplugin["is_installed"] = isset($installedcomponents[$marketplaceplugin["component"]]);
        $marketplacerepositories[$marketplaceplugin["component"]] = $marketplaceplugin["repository_url"];
        $local = null;
        try {
            $local = MoodlePluginManager::localInstallation($site, $marketplaceplugin["component"]);
        } catch (Throwable $e) {
            $marketplaceplugin["local_error"] = $e->getMessage();
        }
        $marketplaceplugin["is_local_without_git"] = $local !== null && !$marketplaceplugin["is_installed"];
        $marketplaceplugin["can_install"] = !$marketplaceplugin["is_installed"] && !$upgradebusy;
        $marketplaceplugin["install_button_label"] = $marketplaceplugin["is_installed"]
            ? t("moodle_plugins.marketplace_installed")
            : ($marketplaceplugin["is_local_without_git"]
                ? t("moodle_plugins.link_action") : t("moodle_plugins.marketplace_install"));
        if ($marketplaceplugin["is_local_without_git"]) {
            $unmanagedplugins[] = [
                "component" => $local["component"],
                "version" => $local["version"] !== "" ? $local["version"] : "-",
                "relative_path" => $local["relative_path"],
                "git_url" => $marketplaceplugin["repository_url"],
                "csrf_token" => csrf_token(),
            ];
        }
        $marketplaceplugin["category_display"] = $marketplaceplugin["category"] !== ""
            ? ucfirst($marketplaceplugin["category"])
            : t("moodle_plugins.marketplace_category_plugin");
        $marketplaceplugin["has_moodle"] = $marketplaceplugin["moodle"] !== "";
        $marketplaceplugin["csrf_token"] = csrf_token();
    }
    unset($marketplaceplugin);
} catch (Throwable $e) {
    $marketplaceerror = $e->getMessage();
}
foreach ($plugins as &$plugin) {
    if (!empty($plugin["check_error"])) {
        $plugin["git_link_url"] = $marketplacerepositories[$plugin["component"]] ?? "";
    }
}
unset($plugin);

$marketplacetypefilters = [];
if (!empty($marketplaceplugins)) {
    $marketplacetypefilters[] = [
        "type" => "",
        "label" => t("moodle_plugins.marketplace_filter_all"),
        "count" => count($marketplaceplugins),
        "icon" => "icon-dashboard",
        "is_active" => true,
        "aria_pressed" => "true",
    ];

    $marketplacetypes = array_keys($marketplacetypecounts);
    usort($marketplacetypes, static function (string $a, string $b) use ($marketplacetypeorder): int {
        $positiona = $marketplacetypeorder[$a] ?? PHP_INT_MAX;
        $positionb = $marketplacetypeorder[$b] ?? PHP_INT_MAX;
        return $positiona === $positionb ? strcasecmp($a, $b) : $positiona <=> $positionb;
    });

    foreach ($marketplacetypes as $marketplacetype) {
        $marketplacetypefilters[] = [
            "type" => $marketplacetype,
            "label" => strtoupper($marketplacetype),
            "count" => $marketplacetypecounts[$marketplacetype],
            "icon" => $marketplacetypeicons[$marketplacetype] ?? "icon-layers",
            "is_active" => false,
            "aria_pressed" => "false",
        ];
    }
}

$context = [
    "domain" => $domain,
    "details_url" => "/details.php?domain=" . rawurlencode($domain),
    "csrf_token" => csrf_token(),
    "has_flash" => $flash !== null && $flash !== "",
    "flash" => $flash,
    "flash_class" => $actionerror ? "danger" : "ok",
    "has_plugins" => !empty($plugins),
    "plugins" => $plugins,
    "has_unmanaged_plugins" => !empty($unmanagedplugins),
    "unmanaged_plugins" => $unmanagedplugins,
    "show_git_link_confirmation" => $linkpending !== null,
    "git_link_component" => $linkpending["component"] ?? "",
    "git_link_path" => $linkpending["path"] ?? "",
    "git_link_url" => $linkpending["git_url"] ?? "",
    "git_link_nonce" => $linkpending["nonce"] ?? "",
    "marketplace_plugins" => $marketplaceplugins,
    "marketplace_type_filters" => $marketplacetypefilters,
    "marketplace_count" => count($marketplaceplugins),
    "has_marketplace" => !empty($marketplaceplugins),
    "has_marketplace_error" => $marketplaceerror !== "",
    "marketplace_error" => $marketplaceerror,
    "has_updates" => $updatecount > 0,
    "update_count" => $updatecount,
    "has_upgrade_job" => $upgradejob !== null,
    "upgrade_busy" => $upgradebusy,
    "can_queue_upgrade" => !$upgradebusy,
    "upgrade_job_url" => $upgradejob !== null ? "/jobs.php?job=" . rawurlencode((string)$upgradejob["id"]) : "",
    "upgrade_status" => $upgradejob !== null ? t("moodle_plugins.upgrade_status_" . $upgradestatuskey) : "",
    "upgrade_status_error" => (string)($upgradejob["error"] ?? ""),
    "has_upgrade_error" => $upgradestatus === "failed",
];

render_header(t("moodle_plugins.title"));
echo render_app_template("page/moodle-plugins", $context);
render_footer();

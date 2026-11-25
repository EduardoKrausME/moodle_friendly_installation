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
        if (in_array($action, ["install_plugin", "update_plugin", "request_git_link", "confirm_git_link", "confirm_plugin_update", "confirm_plugin_update_without_backup"], true)
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
        } else if (in_array($action, ["update_plugin", "confirm_plugin_update", "confirm_plugin_update_without_backup"], true)) {
            $confirmation = null;
            $withoutbackup = $action === "confirm_plugin_update_without_backup";
            if ($action !== "update_plugin") {
                $pending = $_SESSION["plugin_update_request"] ?? null;
                $nonce = $_POST["update_nonce"] ?? "";
                if (!is_array($pending) || ($pending["domain"] ?? "") !== $domain ||
                    !is_string($nonce) || !hash_equals($pending["nonce"], $nonce) ||
                    ($pending["expires"] ?? 0) < time()) {
                    throw new RuntimeException(t("moodle_plugins.update_confirmation_expired"));
                }
                $component = $pending["component"];
                $confirmation = $pending["fingerprint"];
            } else {
                $component = isset($_POST["component"]) && is_string($_POST["component"]) ? $_POST["component"] : "";
            }
            unset($_SESSION["plugin_update_request"]);
            $result = MoodlePluginManager::update($site, $component, $confirmation, $withoutbackup);
            if ($result["confirmation_required"]) {
                $_SESSION["plugin_update_request"] = [
                    "domain" => $domain, "component" => $component,
                    "fingerprint" => $result["fingerprint"], "changes" => $result["changes"],
                    "preserved" => $result["preserved"], "nonce" => bin2hex(random_bytes(16)),
                    "expires" => time() + 900,
                ];
                if ($result["stale"]) {
                    $_SESSION["flash"] = t("moodle_plugins.update_confirmation_expired");
                    $_SESSION["plugin_action_error"] = true;
                }
            } else {
                JobManager::createPluginUpgradeJob($site, $result["component"]);
                $_SESSION["flash"] = t("moodle_plugins.update_success", ["component" => $result["component"]]);
                if ($result["backup"] !== "") {
                    $_SESSION["flash"] .= " " . t("moodle_plugins.update_backup_saved", ["path" => $result["backup"]]);
                } else if ($withoutbackup) {
                    $_SESSION["flash"] .= " " . t("moodle_plugins.update_without_backup_success");
                }
            }
        } else if ($action === "cancel_plugin_update") {
            $pending = $_SESSION["plugin_update_request"] ?? null;
            if (is_array($pending) && ($pending["domain"] ?? "") === $domain) {
                unset($_SESSION["plugin_update_request"]);
            }
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
$activeupgradejob = JobManager::activeJob("plugin_upgrade", $domain);
$upgradebusy = $activeupgradejob !== null;
// When blocked, show the same active job that is preventing operations.
$upgradejob = $activeupgradejob ?? JobManager::latestPluginUpgradeJob($domain);
$upgradestatus = (string)($upgradejob["status"] ?? "");
$upgradestatuskey = in_array($upgradestatus, ["pending", "running", "done", "failed"], true)
    ? $upgradestatus : "pending";

$linkpending = $_SESSION["plugin_git_link_request"] ?? null;
if (!is_array($linkpending) || ($linkpending["domain"] ?? "") !== $domain) {
    $linkpending = null;
}
$updatepending = $_SESSION["plugin_update_request"] ?? null;
if (!is_array($updatepending) || ($updatepending["domain"] ?? "") !== $domain ||
    ($updatepending["expires"] ?? 0) < time()) {
    $updatepending = null;
}
$forcerefresh = isset($_GET["refresh"]) && $_GET["refresh"] === "1";
$marketplaceplugins = [];
$marketplaceerror = "";
try {
    $marketplaceplugins = MoodlePluginManager::marketplaceCatalog();
} catch (Throwable $e) {
    $marketplaceerror = $e->getMessage();
}
$plugins = MoodlePluginManager::installed($site, true, $forcerefresh, $marketplaceplugins);
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
    $plugin["has_git"] = !empty($plugin["has_git"]);
    $plugin["git_link_url"] = "";
    if (!empty($plugin["update_available"])) {
        $updatecount++;
        $plugin["status_badge_html"] = status_badge("warning", t("moodle_plugins.status_version_different"));
        $plugin["update_button_label"] = t($upgradebusy ? "moodle_plugins.upgrade_wait_button" : "moodle_plugins.action_update_now");
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
$marketplacerepositories = [];
try {
    foreach ($marketplaceplugins as &$marketplaceplugin) {
        $marketplacetype = strstr($marketplaceplugin["component"], "_", true) ?: "";
        $marketplaceplugin["type"] = $marketplacetype;
        $marketplaceplugin["type_display"] = strtoupper($marketplacetype);
        if ($marketplacetype !== "") {
            $marketplacetypecounts[$marketplacetype] = ($marketplacetypecounts[$marketplacetype] ?? 0) + 1;
        }
        $marketplaceplugin["is_installed"] = isset($installedcomponents[$marketplaceplugin["component"]]);
        $marketplacerepositories[$marketplaceplugin["component"]] = $marketplaceplugin["repository_url"];
        $marketplaceplugin["can_install"] = !$marketplaceplugin["is_installed"] && !$upgradebusy;
        $marketplaceplugin["install_button_label"] = $marketplaceplugin["is_installed"]
            ? t("moodle_plugins.marketplace_installed")
            : t("moodle_plugins.marketplace_install");
        if (!$marketplaceplugin["is_installed"] && $upgradebusy) {
            $marketplaceplugin["install_button_label"] = t("moodle_plugins.upgrade_wait_button");
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
    "flash_class" => $actionerror ? "alert-danger" : "ok",
    "show_update_confirmation" => $updatepending !== null,
    "update_component" => $updatepending["component"] ?? "",
    "update_nonce" => $updatepending["nonce"] ?? "",
    "has_update_changes" => !empty($updatepending["changes"]),
    "update_change_count" => count($updatepending["changes"] ?? []),
    "update_changes" => array_map(static fn(string $entry): array => [
        "status" => trim(substr($entry, 0, 2)),
        "path" => substr($entry, 3),
    ], $updatepending["changes"] ?? []),
    "update_preserved_summary" => t("moodle_plugins.update_preserved", [
        "count" => (string)count($updatepending["preserved"] ?? []),
    ]),
    "has_plugins" => !empty($plugins),
    "plugins" => $plugins,
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
    "upgrade_busy_message" => $upgradebusy ? t("moodle_plugins.upgrade_blocked_" . $upgradestatuskey) : "",
    "upgrade_refresh_url" => "/moodle_plugins.php?domain=" . rawurlencode($domain),
    "can_queue_upgrade" => !$upgradebusy,
    "upgrade_job_url" => $upgradejob !== null ? "/jobs.php?job=" . rawurlencode((string)$upgradejob["id"]) : "",
    "upgrade_status" => $upgradejob !== null ? t("moodle_plugins.upgrade_status_" . $upgradestatuskey) : "",
    "upgrade_status_error" => (string)($upgradejob["error"] ?? ""),
    "has_upgrade_error" => $upgradestatus === "failed",
];

render_header(t("moodle_plugins.title"));
echo render_app_template("page/moodle-plugins", $context);
render_footer();

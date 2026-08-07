<?php

use app\Auth;
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
        if ($action === "install_plugin") {
            $giturl = isset($_POST["git_url"]) && is_string($_POST["git_url"]) ? trim($_POST["git_url"]) : "";
            if ($giturl === "") {
                throw new RuntimeException(t("moodle_plugins.git_url_required"));
            }
            $result = MoodlePluginManager::install($site, $giturl);
            $_SESSION["flash"] = t("moodle_plugins.install_success", ["component" => $result["component"]]);
            $_SESSION["plugin_install_component"] = $result["component"];
        } else if ($action === "update_plugin") {
            $component = isset($_POST["component"]) && is_string($_POST["component"]) ? $_POST["component"] : "";
            $result = MoodlePluginManager::update($site, $component);
            $_SESSION["flash"] = t("moodle_plugins.update_success", ["component" => $result["component"]]);
            $_SESSION["plugin_install_component"] = $result["component"];
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
$installedcomponent = isset($_SESSION["plugin_install_component"]) && is_string($_SESSION["plugin_install_component"])
    ? $_SESSION["plugin_install_component"] : "";
unset($_SESSION["plugin_install_component"]);

$plugins = MoodlePluginManager::installed($site, true);
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
        $plugin["can_update"] = true;
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

$context = [
    "domain" => $domain,
    "details_url" => "/details.php?domain=" . rawurlencode($domain),
    "csrf_token" => csrf_token(),
    "has_flash" => $flash !== null && $flash !== "",
    "flash" => $flash,
    "flash_class" => $actionerror ? "danger" : "ok",
    "has_plugins" => !empty($plugins),
    "plugins" => $plugins,
    "has_updates" => $updatecount > 0,
    "update_count" => $updatecount,
    "show_finish_install" => $installedcomponent !== "",
    "admin_url" => MoodlePluginManager::siteAdminUrl($site),
    "installed_component" => $installedcomponent,
];

render_header(t("moodle_plugins.title"));
echo render_app_template("page/moodle-plugins", $context);
render_footer();

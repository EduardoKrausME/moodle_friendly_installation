<?php

use app\Auth;
use app\PanelConfigManager;
use app\PanelDomainManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$flash = flash_message();
$savedconfig = PanelConfigManager::savedConfig();
$currentbaseurl = (string) ($config["base_url"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();

    try {
        $newconfig = PanelConfigManager::normalizePost($_POST, $savedconfig);
        $postedbaseurl = PanelConfigManager::normalizeBaseUrl((string) ($newconfig["base_url"] ?? ""));
        $newhost = PanelConfigManager::baseUrlHost($postedbaseurl);
        $oldhost = PanelConfigManager::baseUrlHost($currentbaseurl);

        if (PanelConfigManager::isIpBaseUrl($postedbaseurl)) {
            $ipauthority = str_contains($newhost, ":") ? "[{$newhost}]" : $newhost;
            $newconfig["base_url"] = "http://{$ipauthority}";
        } else if ($newhost !== $oldhost) {
            $jobid = trim((string) ($_POST["panel_domain_job_id"] ?? ""));
            if (!PanelDomainManager::isCompletedFor($jobid, $postedbaseurl)) {
                throw new RuntimeException(PanelDomainManager::message("not_ready"));
            }
            $newconfig["base_url"] = PanelDomainManager::canonicalDomainUrl($postedbaseurl);
        } else {
            $newconfig["base_url"] = $postedbaseurl;
        }

        PanelConfigManager::save($newconfig);
        $_SESSION["flash"] = t("configuration.saved");
        redirect_to("/configuration.php");
    } catch (Throwable $e) {
        $errors[] = ["message" => $e->getMessage()];
    }
}

render_header(t("configuration.title"));
echo render_app_template("page/configuration", [
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "has_errors" => !empty($errors),
    "errors" => $errors,
    "fields" => PanelConfigManager::fieldsForForm($config),
    "csrf_token" => csrf_token(),
    "config_file" => PanelConfigManager::baseConfigPath(),
    "json_file" => PanelConfigManager::jsonPath(),
    "current_base_url" => $currentbaseurl,
    "panel_domain_intro" => PanelDomainManager::message("automatic_intro"),
    "panel_domain_ip_note" => PanelDomainManager::message("ip_access_note"),
    "panel_domain_checklist_title" => PanelDomainManager::message("checklist_title"),
    "panel_domain_check_request_received" => PanelDomainManager::message("check_request_received"),
    "panel_domain_check_public_ip" => PanelDomainManager::message("check_public_ip"),
    "panel_domain_check_dns" => PanelDomainManager::message("check_dns"),
    "panel_domain_check_webserver_config" => PanelDomainManager::message("check_webserver_config"),
    "panel_domain_check_webserver_test" => PanelDomainManager::message("check_webserver_test"),
    "panel_domain_check_certificate" => PanelDomainManager::message("check_certificate"),
    "panel_domain_check_activation" => PanelDomainManager::message("check_activation"),
]);
render_footer();

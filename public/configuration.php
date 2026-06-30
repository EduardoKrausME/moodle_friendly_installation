<?php

use app\Auth;
use app\PanelConfigManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$flash = flash_message();
$savedconfig = PanelConfigManager::savedConfig();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();

    try {
        $newconfig = PanelConfigManager::normalizePost($_POST, $savedconfig);
        PanelConfigManager::save($newconfig);
        $_SESSION["flash"] = t("configuration.saved");
        redirect_to("/configuration.php");
    } catch (Throwable $e) {
        $errors[] = ["message" => $e->getMessage()];
    }
}

$currentconfig = PanelConfigManager::effectiveConfig(PanelConfigManager::baseConfig());

render_header(t("configuration.title"));
echo render_app_template("page/configuration", [
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "has_errors" => !empty($errors),
    "errors" => $errors,
    "fields" => PanelConfigManager::fieldsForForm($currentconfig),
    "csrf_token" => csrf_token(),
    "config_file" => PanelConfigManager::baseConfigPath(),
    "json_file" => PanelConfigManager::jsonPath(),
]);
render_footer();

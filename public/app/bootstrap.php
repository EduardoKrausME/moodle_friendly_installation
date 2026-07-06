<?php

use app\Auth;
use app\I18n;
use app\PanelConfigManager;

error_reporting(E_ALL);
ini_set("display_errors", "1");
ini_set("display_startup_errors", "1");

require_once "vendor/autoload.php";

require_once __DIR__ . "/JsonStorage.php";
require_once __DIR__ . "/PanelConfigManager.php";

$config = PanelConfigManager::effectiveConfig();

require_once __DIR__ . "/I18n.php";
require_once __DIR__ . "/UserManager.php";
require_once __DIR__ . "/Auth.php";
require_once __DIR__ . "/Validator.php";
require_once __DIR__ . "/JobManager.php";
require_once __DIR__ . "/SiteManager.php";
require_once __DIR__ . "/AppManager.php";
require_once __DIR__ . "/render.php";
require_once __DIR__ . "/MoodleBranchProvider.php";

if (PHP_SAPI != "cli") {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_secure", (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "off") ? "1" : "0");
    ini_set("session.use_strict_mode", "1");
    session_name("MOODLEFRIENDLYSESSID");
    session_start();
}

I18n::init();

/**
 * Function t
 *
 * @param string $key
 * @param array $params
 * @return string
 */
function t(string $key, array $params = []): string {
    return I18n::get($key, $params);
}

/**
 * Function app_config
 *
 * @param string|null $key
 * @return mixed
 */
function app_config(?string $key = null): mixed {
    global $config;
    return $key == null ? $config : ($config[$key] ?? null);
}

/**
 * Function app_config_path
 *
 * @param string $path
 * @return string
 */
function app_config_path(string $path): string {
    return app_config("base_dir") . $path;
}

/**
 * Function redirect_to
 *
 * @param string $path
 * @return never
 */
function redirect_to(string $path): never {
    header("Location: {$path}");
    exit;
}

/**
 * Function csrf_token
 *
 * @return string
 * @throws \Random\RandomException
 */
function csrf_token(): string {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

/**
 * Function validate_csrf
 *
 * @return void
 */
function validate_csrf(): void {
    $token = $_POST["csrf_token"] ?? "";
    if (!is_string($token) || empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        http_response_code(400);
        exit("CSRF token invalid.");
    }
}

/**
 * Function now_iso
 *
 * @return string
 * @throws \DateMalformedStringException
 */
function now_iso(): string {
    return (new DateTimeImmutable("now", new DateTimeZone("America/Sao_Paulo")))->format(DateTimeInterface::ATOM);
}

if (PHP_SAPI != "cli" && Auth::check()) {
    $scriptname = basename((string) ($_SERVER["SCRIPT_NAME"] ?? ""));
    if (Auth::requiresPasswordChange() && $scriptname != "profile.php") {
        redirect_to("/profile.php?force=1");
    }
}

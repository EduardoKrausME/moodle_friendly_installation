<?php

error_reporting(E_ALL);
ini_set("display_errors", "1");
ini_set("display_startup_errors", "1");

require_once "vendor/autoload.php";
$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/JsonStorage.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/SiteManager.php';
require_once __DIR__ . '/AppManager.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/MoodleBranchProvider.php';

date_default_timezone_set('America/Sao_Paulo');

if (PHP_SAPI !== 'cli') {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    session_name('MYLEARNADMINSESSID');
    session_start();
}

\app\I18n::init();

function t(string $key, array $params = []): string {
    return \app\I18n::get($key, $params);
}

function app_config(?string $key = null): mixed {
    global $config;
    return $key === null ? $config : ($config[$key] ?? null);
}

function app_config_path(string $path): mixed {
    return app_config("base_dir") . $path;
}

function redirect_to(string $path): never {
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        exit('CSRF token invalid.');
    }
}

function now_iso(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DateTimeInterface::ATOM);
}

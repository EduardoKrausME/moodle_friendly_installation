<?php

use app\AppManager;
use app\Auth;
use app\SiteManager;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? $_GET["domain"] : '';
$file = isset($_GET["file"]) && is_string($_GET["file"]) ? $_GET["file"] : '';
$site = SiteManager::get($domain);

if ($site == null) {
    http_response_code(404);
    exit(t('download.moodle_not_found'));
}

$file = basename($file);
if (!preg_match('/^[a-z0-9_.-]+\.(apk|aab)$/i', $file)) {
    http_response_code(400);
    exit(t('download.invalid_file'));
}

$path = AppManager::storageDir(($site["domain"] ?? $domain)) . '/' . $file;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit(t('download.file_not_found'));
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $ext == 'apk' ? 'application/vnd.android.package-archive' : 'application/octet-stream';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . addslashes(basename($path)) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

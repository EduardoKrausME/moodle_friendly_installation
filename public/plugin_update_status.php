<?php

use app\Auth;
use app\MoodlePluginManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
require_once __DIR__ . "/app/MoodlePluginManager.php";

Auth::requireLogin();
header("Content-Type: application/json; charset=utf-8");

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? trim($_GET["domain"]) : "";
if ($domain === "" || SiteManager::get($domain) === null) {
    http_response_code(404);
    echo json_encode(["ok" => false, "updates" => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$state = MoodlePluginManager::cachedSiteState($domain);
echo json_encode([
    "ok" => true,
    "updates" => (int) ($state["updates"] ?? 0),
    "checked_at" => $state["checked_at"] ?? "",
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

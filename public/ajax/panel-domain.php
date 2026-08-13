<?php

use app\Auth;
use app\PanelConfigManager;
use app\PanelDomainManager;

require_once dirname(__DIR__) . "/app/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$initialsetup = PanelConfigManager::requiresInitialSetup() && Auth::hasInitialAdminCredentials();
if (!$initialsetup) {
    Auth::requireLogin();
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new RuntimeException("POST required.");
    }

    validate_csrf();
    $action = trim((string) ($_POST["action"] ?? ""));

    if ($action === "request") {
        $baseurl = trim((string) ($_POST["base_url"] ?? ""));
        $source = trim((string) ($_POST["source"] ?? "configuration"));
        if ($initialsetup) {
            $source = "onboarding";
        }

        $job = PanelDomainManager::request($baseurl, $source);
        echo json_encode(["success" => true, "job" => $job], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === "status") {
        $id = trim((string) ($_POST["id"] ?? ""));
        $job = PanelDomainManager::status($id);
        echo json_encode(["success" => true, "job" => $job], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException("Invalid action.");
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

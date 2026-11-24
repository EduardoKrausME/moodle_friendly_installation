<?php
// Regression coverage for job operation labels and both queue views.
declare(strict_types=1);

use app\I18n;
use app\JobManager;

require_once __DIR__ . "/../public/app/I18n.php";
require_once __DIR__ . "/../public/app/JobManager.php";

function checkJobLabel(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    [["type" => "install_moodle"], "operation_install", []],
    [["type" => "install_moodle", "moodle_branch" => "MOODLE_501_STABLE"], "operation_install_branch", ["branch" => "MOODLE_501_STABLE"]],
    [["type" => "restore_moodle", "kopere_backup_zip" => "/private/secret.zip"], "operation_restore", []],
    [["type" => "app_build"], "operation_app", []],
    [["type" => "app_build", "package_name" => "Example"], "operation_app_package", ["package" => "Example"]],
    [["type" => "resource_usage"], "operation_resources", []],
    [["type" => "server_control", "control" => "cache", "enabled" => true], "operation_cache_enable", []],
    [["type" => "server_control", "control" => "cache", "enabled" => false], "operation_cache_disable", []],
    [["type" => "server_control"], "operation_server", []],
    [["type" => "plugin_upgrade", "component" => "mod_supervideo"], "operation_upgrade_component", ["component" => "mod_supervideo"]],
    [["type" => "plugin_upgrade", "component" => "core"], "operation_upgrade", []],
    [["type" => "plugin_upgrade"], "operation_upgrade", []],
    [["type" => "cleanup_failed_installation"], "operation_cleanup", []],
    [["type" => "legacy_unknown"], "operation_unknown", []],
    [[], "operation_unknown", []],
];

foreach (["pt_br", "en", "es"] as $language) {
    $_SESSION["lang"] = $language;
    I18n::init();
    $strings = require __DIR__ . "/../public/app/lang/{$language}.php";
    checkJobLabel(isset($strings["jobs"]["operation"]), "Missing operation heading: {$language}");
    foreach ($cases as [$job, $key, $params]) {
        checkJobLabel(isset($strings["jobs"][$key]), "Missing translation: {$language}/{$key}");
        foreach (["pending", "running", "waiting_dns", "done", "failed", "canceled"] as $status) {
            $job["status"] = $status;
            $job["admin_pass"] = "must-not-be-displayed";
            $label = JobManager::operationLabel($job);
            checkJobLabel($label === I18n::get("jobs." . $key, $params), "Incorrect job label: {$language}/{$key}/{$status}");
            checkJobLabel(!str_contains($label, "must-not-be-displayed") && !str_contains($label, "/private/"), "Sensitive data in job label");
        }
    }
}

$controller = file_get_contents(__DIR__ . "/../public/jobs.php");
$template = file_get_contents(__DIR__ . "/../public/app/templates/page/jobs.mustache");
checkJobLabel(str_contains($controller, '"operation" => JobManager::operationLabel($job)'), "Missing operation in view context");
checkJobLabel(substr_count($template, "{{operation}}") === 2 && !str_contains($template, "{{{operation}}}"), "Both views must render escaped operation labels");

echo "Job operation labels OK\n";

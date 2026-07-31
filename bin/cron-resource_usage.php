<?php

use app\JobManager;
use app\ResourceUsageManager;

if (!isset($job) || !is_array($job) || ($job["type"] ?? "") !== "resource_usage") {
    throw new RuntimeException("Invalid resource usage job.");
}

$job = JobManager::markRunning((string) $job["id"]);
if ($job === null) {
    echo "Resource usage job is no longer pending.\n";
    return;
}

resourceUsageLog($job, "Starting background resource collection.");
$snapshot = ResourceUsageManager::collect($job);
resourceUsageLog($job, "Collection completed. Total: " . ResourceUsageManager::formatBytes((int) $snapshot["total_bytes"]));
JobManager::markDone((string) $job["id"], ["resource_collected_at" => $snapshot["collected_at"]]);
echo "Resource collection completed: {$job["id"]}\n";

/**
 * Appends a resource collection message to the job log.
 *
 * @param array $job
 * @param string $message
 * @return void
 */
function resourceUsageLog(array $job, string $message): void {
    $logfile = (string) ($job["log_file"] ?? "");
    if ($logfile === "") {
        return;
    }
    if (!is_dir(dirname($logfile))) {
        mkdir(dirname($logfile), 0750, true);
    }
    file_put_contents($logfile, "[" . date("Y-m-d H:i:s") . "] {$message}\n", FILE_APPEND | LOCK_EX);
    JobManager::fixLogPermissions($logfile);
}

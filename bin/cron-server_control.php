<?php

use app\JobManager;
use app\ServerControlManager;

if (!isset($job) || !is_array($job) || ($job["type"] ?? "") !== "server_control") {
    throw new RuntimeException("Invalid server control job.");
}

$job = JobManager::markRunning((string) $job["id"]);
if ($job === null) {
    echo "Server control job is no longer pending.\n";
    return;
}

serverControlLog($job, "Applying {$job["control"]} state: " . (!empty($job["enabled"]) ? "enabled" : "disabled") . ".");
$result = ServerControlManager::apply($job);
serverControlLog($job, (string) ($result["message"] ?? "Server control completed."));
JobManager::markDone((string) $job["id"], ["server_control_message" => $result["message"] ?? ""]);
echo "Server control completed: {$job["id"]}\n";

/**
 * Appends a server control message to the job log.
 *
 * @param array $job
 * @param string $message
 * @return void
 */
function serverControlLog(array $job, string $message): void {
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

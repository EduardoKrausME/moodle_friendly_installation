<?php

use app\Auth;
use app\JobManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$selectedjobid = trim($_GET["job"] ?? $_GET["id"] ?? "");
$jobs = [];
$selectedjob = null;
$shouldrefresh = false;

foreach (JobManager::all() as $job) {
    $status = $job["status"] ?? "pending";
    $statusclass = preg_replace('/[^a-z0-9_-]/', "-", strtolower($status));
    $createdat = "";
    $log = "";
    $haslog = !empty($job["log_file"]) && is_readable($job["log_file"]);
    $jobid = (string) ($job["id"] ?? "");

    if (!empty($job["created_at"])) {
        $timestamp = strtotime($job["created_at"]);
        if ($timestamp) {
            $createdat = date("d/m/Y H:i", $timestamp);
        }
    }

    if ($selectedjobid !== "" && $jobid === $selectedjobid && $haslog) {
        $log = file_get_contents($job["log_file"]);
    }

    if (in_array($status, ["running", "pending", "waiting_dns"], true)) {
        $shouldrefresh = true;
    }

    $viewjob = [
        "id" => $jobid,
        "domain" => $job["domain"] ?? "",
        "status_class" => $statusclass,
        "status_badge" => status_badge($status),
        "status" => $status,
        "created_at" => $createdat,
        "has_error" => !empty($job["error"]),
        "error" => $job["error"] ?? "",
        "has_log" => $haslog,
        "log" => $log,
        "url" => "/jobs.php?job=" . rawurlencode($jobid),
    ];

    if ($selectedjobid !== "" && $jobid === $selectedjobid) {
        $selectedjob = $viewjob;
    }

    $jobs[] = $viewjob;
}

$flash = flash_message();

render_header(t("jobs.title"));
echo render_app_template("page/jobs", [
    "has_flash" => !empty($flash),
    "flash" => $flash,
    "has_jobs" => !empty($jobs),
    "jobs" => $jobs,
    "has_selected_job" => !empty($selectedjob),
    "selected_job" => $selectedjob,
    "selected_job_id" => $selectedjobid,
    "selected_job_not_found" => $selectedjobid !== "" && empty($selectedjob),
    "should_refresh" => $shouldrefresh,
]);
render_footer();

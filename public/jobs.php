<?php

use app\Auth;
use app\JobManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();
    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "";
    $jobid = isset($_POST["job_id"]) && is_string($_POST["job_id"]) ? trim($_POST["job_id"]) : "";

    if ($action === "cancel_job") {
        $username = (string) (Auth::user()["username"] ?? "system");
        $result = JobManager::cancelJob($jobid, $username);
        if ($result["cancelled"]) {
            $_SESSION["flash"] = t("jobs.cancelled", ["id" => $jobid]);
            $_SESSION["flash_status"] = "ok";
        } else if ($result["job"] === null) {
            $_SESSION["flash"] = t("jobs.not_found");
            $_SESSION["flash_status"] = "danger";
        } else {
            $_SESSION["flash"] = t("jobs.cancel_not_allowed");
            $_SESSION["flash_status"] = "danger";
        }
        redirect_to($jobid === "" ? "/jobs.php" : "/jobs.php?job=" . rawurlencode($jobid));
    }

    if ($action === "delete_failed_installation_files") {
        try {
            $username = (string) (Auth::user()["username"] ?? "system");
            $cleanupjob = JobManager::createFailedInstallationCleanupJob($jobid, $username);
            $_SESSION["flash"] = t("jobs.delete_files_queued", ["id" => ($cleanupjob["id"] ?? "")]);
            $_SESSION["flash_status"] = "ok";
            redirect_to("/jobs.php?job=" . rawurlencode((string) ($cleanupjob["id"] ?? $jobid)));
        } catch (Throwable $e) {
            $_SESSION["flash"] = $e->getMessage();
            $_SESSION["flash_status"] = "danger";
            redirect_to($jobid === "" ? "/jobs.php" : "/jobs.php?job=" . rawurlencode($jobid));
        }
    }
}

$selectedjobid = isset($_GET["job"]) && is_string($_GET["job"])
    ? trim($_GET["job"])
    : (isset($_GET["id"]) && is_string($_GET["id"]) ? trim($_GET["id"]) : "");
$jobs = [];
$selectedjob = null;
$shouldrefresh = false;
$alljobs = JobManager::all();
$jobsbyid = [];
$latestinstallationbydomain = [];
foreach ($alljobs as $storedjob) {
    if (!empty($storedjob["id"])) {
        $jobsbyid[(string) $storedjob["id"]] = $storedjob;
    }
    $storeddomain = $storedjob["domain"];
    if ($storeddomain !== ""
        && !isset($latestinstallationbydomain[$storeddomain])
        && in_array(($storedjob["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
    ) {
        $latestinstallationbydomain[$storeddomain] = $storedjob["id"];
    }
}

foreach ($alljobs as $job) {
    $status = $job["status"] ?? "pending";
    $statusclass = preg_replace('/[^a-z0-9_-]/', "-", strtolower($status));
    $createdat = "";
    $log = "";
    $haslog = !empty($job["log_file"]) && is_readable($job["log_file"]);
    $jobid = $job["id"];
    $jobtype = $job["type"];
    $cleanupjobid = $job["cleanup_job_id"];
    $cleanupjob = $cleanupjobid !== "" && isset($jobsbyid[$cleanupjobid]) ? $jobsbyid[$cleanupjobid] : null;
    $cleanupactive = is_array($cleanupjob)
        && in_array(($cleanupjob["status"] ?? ""), ["pending", "running"], true);
    $failedinstallation = $status === "failed"
        && in_array($jobtype, ["install_moodle", "restore_moodle"], true)
        && ($latestinstallationbydomain[$job["domain"]] ?? "") === $jobid;
    $filesdeleted = !empty($job["files_deleted"]);

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
        "can_cancel" => in_array($status, ["pending", "waiting_dns"], true),
        "can_delete_files" => $failedinstallation && !$filesdeleted && !$cleanupactive,
        "delete_files_confirm" => t("jobs.delete_files_confirm", ["path" => "/home/" . ($job["domain"] ?? "")]),
        "cleanup_active" => $cleanupactive,
        "cleanup_url" => $cleanupactive ? "/jobs.php?job=" . rawurlencode($cleanupjobid) : "",
        "files_deleted" => $filesdeleted,
        "csrf_token" => csrf_token(),
    ];

    if ($selectedjobid !== "" && $jobid === $selectedjobid) {
        $selectedjob = $viewjob;
    }

    $jobs[] = $viewjob;
}

$flashclass = isset($_SESSION["flash_status"]) && $_SESSION["flash_status"] === "danger" ? "danger" : "ok";
unset($_SESSION["flash_status"]);
$flash = flash_message();

render_header(t("jobs.title"));
echo render_app_template("page/jobs", [
    "has_flash" => !empty($flash),
    "flash" => $flash,
    "flash_class" => $flashclass,
    "has_jobs" => !empty($jobs),
    "jobs" => $jobs,
    "has_selected_job" => !empty($selectedjob),
    "selected_job" => $selectedjob,
    "selected_job_id" => $selectedjobid,
    "selected_job_not_found" => $selectedjobid !== "" && empty($selectedjob),
    "should_refresh" => $shouldrefresh,
]);
render_footer();

#!/usr/bin/env php
<?php

error_reporting(E_ALL);
ini_set("display_errors", "On");

// Root cron runner. It executes one pending job per run.

use app\JobManager;

require_once __DIR__ . "/../public/app/bootstrap.php";

if (function_exists("posix_geteuid") && posix_geteuid() !== 0) {
    fwrite(STDERR, "This runner must be executed as root.\n");
    exit(1);
}

$lockpath = app_config_path("/runtime/runner.lock");
$lock = fopen($lockpath, "c");
if (!$lock) {
    fwrite(STDERR, "Cannot open runner lock.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another runner is already active.\n";
    exit(0);
}

try {
    $job = JobManager::nextPendingJob();
    if (!$job) {
        echo "No pending jobs.\n";
        exit(0);
    }

    require_once "cron-{$job["type"]}.php";

} catch (Throwable $e) {
    if (!empty($job["id"])) {
        JobManager::markFailed($job["id"], $e->getMessage());
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}


#!/usr/bin/env php
<?php

error_reporting(E_ALL);
ini_set("display_errors", "On");

// Root cron runner. It executes requested app updates, one pending job per run, and checks app updates once a day.

use app\AppUpdater;
use app\JobManager;
use app\JsonStorage;
use app\PanelConfigManager;

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

$job = null;

try {
    if (AppUpdater::isInstallRequested()) {
        runRequestedSelfUpdate();
        exit(0);
    }

    $job = JobManager::nextPendingJob();
    if ($job) {
        require_once "cron-{$job["type"]}.php";
    } else {
        echo "No pending jobs.\n";
        runDailySelfUpdateCheck();
    }
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

/**
 * Installs an update requested through the web panel.
 *
 * @return void
 * @throws \DateMalformedStringException
 * @throws \Random\RandomException
 */
function runRequestedSelfUpdate(): void {
    $statefile = PanelConfigManager::projectRoot() . "/data/update/cron-self-update.json";
    $state = JsonStorage::read($statefile);
    if (!is_array($state)) {
        $state = [];
    }

    $state["last_started_at"] = now_iso();
    $state["last_status"] = "installing_requested_update";
    $state["last_message"] = t("updater.update_installing");
    JsonStorage::write($statefile, $state);

    try {
        $result = AppUpdater::installRequested();
        $state["last_finished_at"] = now_iso();
        $state["last_status"] = empty($result["updated"]) ? "checked" : "updated";
        $state["last_message"] = (string) ($result["message"] ?? "OK");
        $state["latest_tag"] = (string) ($result["state"]["latest_tag"] ?? "");
        $state["installed_tag"] = (string) ($result["state"]["installed_tag"] ?? "");
        JsonStorage::write($statefile, $state);
        echo "Self-update: {$state["last_message"]}\n";
    } catch (Throwable $e) {
        $state["last_finished_at"] = now_iso();
        $state["last_status"] = "failed";
        $state["last_message"] = $e->getMessage();
        JsonStorage::write($statefile, $state);
        fwrite(STDERR, "Self-update failed: {$e->getMessage()}\n");
    }

    runSelfUpdatepermissons($statefile);
}

/**
 * Checks GitHub releases and records available updates at most once per day.
 *
 * The root runner can execute every minute, so this method stores a daily marker
 * in data/update/cron-self-update.json to avoid querying GitHub repeatedly.
 * Update errors are recorded, but they do not stop Moodle install/build jobs.
 *
 * @return void
 * @throws \DateMalformedStringException
 * @throws \Random\RandomException
 */
function runDailySelfUpdateCheck(): void {
    $statefile = PanelConfigManager::projectRoot() . "/data/update/cron-self-update.json";
    $today = (new DateTimeImmutable("now", new DateTimeZone("America/Sao_Paulo")))->format("Y-m-d");
    $state = JsonStorage::read($statefile);
    if (!is_array($state)) {
        $state = [];
    }

    if (($state["last_run_date"] ?? "") === $today) {
        return;
    }

    // Save the marker before the network call. If GitHub is offline, the cron
    // will not try again every minute and will check normally tomorrow.
    $state["last_run_date"] = $today;
    $state["last_started_at"] = now_iso();
    $state["last_status"] = "running";
    $state["last_message"] = "Checking GitHub release.";
    JsonStorage::write($statefile, $state);

    try {
        $result = AppUpdater::check();
        $state["last_finished_at"] = now_iso();
        $state["last_status"] = empty($result["update_available"]) ? "checked" : "update_available";
        $state["last_message"] = empty($result["update_available"]) ? t("updater.no_update_available") : t("updater.update_available");
        $state["latest_tag"] = (string) ($result["state"]["latest_tag"] ?? "");
        $state["installed_tag"] = (string) ($result["state"]["installed_tag"] ?? "");
        JsonStorage::write($statefile, $state);
        echo "Self-update check: {$state["last_message"]}\n";
    } catch (Throwable $e) {
        $state["last_finished_at"] = now_iso();
        $state["last_status"] = "failed";
        $state["last_message"] = $e->getMessage();
        JsonStorage::write($statefile, $state);
        fwrite(STDERR, "Self-update check failed: {$e->getMessage()}\n");
    }

    runSelfUpdatepermissons($statefile);
}

function runSelfUpdatepermissons($statefile) {
    $dir = pathinfo($statefile, PATHINFO_DIRNAME);
    echo $dir;
    shell_exec("chmod -R 777 {$dir}");
}

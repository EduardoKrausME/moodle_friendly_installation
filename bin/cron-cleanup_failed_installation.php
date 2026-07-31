<?php

use app\JobManager;

if (!isset($job) || !is_array($job)) {
    die("\$job empty");
}

if (($job["type"] ?? "") === "cleanup_failed_installation") {
    runFailedInstallationCleanupJob($job);
}

/**
 * Removes files created by a failed Moodle installation or restore.
 *
 * @param array $job
 * @return void
 * @throws Throwable
 */
function runFailedInstallationCleanupJob(array $job): void {
    $jobid = $job["id"];
    $sourcejobid = $job["source_job_id"];
    $domain = $job["domain"];

    $job = JobManager::markRunning($jobid);
    if ($job === null) {
        echo "Cleanup job is no longer pending: {$jobid}\n";
        return;
    }

    $sourcejob = cleanupFindJob($sourcejobid);
    cleanupValidateSourceJob($sourcejob, $domain);
    JobManager::updateFailedInstallationCleanup($sourcejobid, $jobid, "running");

    try {
        $base = cleanupInstallationPath($domain, $job["base_dir"]);
        cleanupAppendLog($job, "Removing incomplete installation files from {$base}.");

        $deleted = 0;
        if (is_link($base)) {
            cleanupUnlink($base);
            $deleted++;
        } else if (is_dir($base)) {
            $rootstat = lstat($base);
            if (!is_array($rootstat)) {
                throw new RuntimeException("Cannot inspect cleanup directory: {$base}");
            }
            cleanupAssertSingleFilesystem($base, (int) $rootstat["dev"]);
            cleanupDeleteTree($base, $deleted);
        } else if (file_exists($base)) {
            cleanupUnlink($base);
            $deleted++;
        }

        foreach (cleanupInstallationFiles($domain, $sourcejob) as $file) {
            if (!file_exists($file) && !is_link($file)) {
                continue;
            }
            if (is_dir($file) && !is_link($file)) {
                throw new RuntimeException("Expected a file but found a directory: {$file}");
            }
            cleanupUnlink($file);
            $deleted++;
        }

        cleanupReloadWebServers($job);
        cleanupAppendLog($job, "Cleanup completed. Removed items: {$deleted}.");

        JobManager::markDone($jobid, [
            "deleted_path" => $base,
            "deleted_items" => $deleted,
            "source_job_id" => $sourcejobid,
        ]);
        JobManager::updateFailedInstallationCleanup($sourcejobid, $jobid, "done", [
            "files_deleted" => true,
            "files_deleted_at" => now_iso(),
            "deleted_path" => $base,
            "deleted_items" => $deleted,
        ]);
        echo "Failed installation files removed: {$base}\n";
    } catch (Throwable $e) {
        JobManager::updateFailedInstallationCleanup($sourcejobid, $jobid, "failed", [
            "cleanup_error" => $e->getMessage(),
        ]);
        cleanupAppendLog($job, "Cleanup failed: {$e->getMessage()}");
        throw $e;
    }
}

/**
 * Finds a job by its identifier.
 *
 * @param string $jobid
 * @return array
 */
function cleanupFindJob(string $jobid): array {
    foreach (JobManager::all() as $storedjob) {
        if (($storedjob["id"] ?? "") === $jobid) {
            return $storedjob;
        }
    }
    throw new RuntimeException("Source installation job not found.");
}

/**
 * Checks that the cleanup still targets the original failed job.
 *
 * @param array $sourcejob
 * @param string $domain
 * @return void
 */
function cleanupValidateSourceJob(array $sourcejob, string $domain): void {
    $latestjob = JobManager::latestInstallationJob($domain);
    if (($sourcejob["status"] ?? "") !== "failed"
        || !in_array(($sourcejob["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
        || ($sourcejob["domain"] ?? "") !== $domain
        || !empty($sourcejob["files_deleted"])
        || ($latestjob["id"] ?? "") !== ($sourcejob["id"] ?? "")
    ) {
        throw new RuntimeException("The source job no longer permits file cleanup.");
    }
}

/**
 * Resolves and validates the only directory that this job may remove.
 *
 * @param string $domain
 * @param string $requestedpath
 * @return string
 */
function cleanupInstallationPath(string $domain, string $requestedpath): string {
    if (preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
        throw new RuntimeException("Invalid cleanup domain.");
    }

    $expected = "/home/{$domain}";
    if ($requestedpath !== $expected || dirname($expected) !== "/home" || basename($expected) !== $domain) {
        throw new RuntimeException("Invalid cleanup path: {$requestedpath}");
    }
    return $expected;
}

/**
 * Returns installation-related files outside the domain home directory.
 *
 * @param string $domain
 * @param array $sourcejob
 * @return array
 */
function cleanupInstallationFiles(string $domain, array $sourcejob): array {
    $files = [
        "/etc/nginx/sites-enabled/{$domain}.conf",
        "/etc/apache2/sites-enabled/{$domain}.conf",
        "/etc/httpd/sites-enabled/{$domain}.conf",
        "/etc/cron.d/moodle-{$domain}",
        "/etc/logrotate.d/moodle-friendly-{$domain}",
    ];

    $sourcejobid = $sourcejob["id"];
    if (preg_match('/^job_[a-zA-Z0-9._-]+$/', $sourcejobid) === 1) {
        $files[] = app_config_path("/data/runtime/scripts/install-{$domain}-{$sourcejobid}.sh");
    }

    $backup = $sourcejob["kopere_backup_zip"];
    $uploadroot = realpath(app_config_path("/data/restore-uploads"));
    $backupreal = $backup !== "" ? realpath($backup) : false;
    if (is_string($uploadroot) && is_string($backupreal)
        && str_starts_with($backupreal, rtrim($uploadroot, "/") . "/")
    ) {
        $files[] = $backupreal;
    }

    return array_values(array_unique($files));
}

/**
 * Refuses to traverse a nested mount point during recursive removal.
 *
 * @param string $path
 * @param int $rootdevice
 * @return void
 */
function cleanupAssertSingleFilesystem(string $path, int $rootdevice): void {
    if (is_link($path)) {
        return;
    }
    $stat = lstat($path);
    if (!is_array($stat) || (int) $stat["dev"] !== $rootdevice) {
        throw new RuntimeException("Cleanup refused because a nested filesystem was found: {$path}");
    }
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException("Cannot read cleanup directory: {$path}");
    }
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        cleanupAssertSingleFilesystem("{$path}/{$item}", $rootdevice);
    }
}

/**
 * Removes a directory tree without following symbolic links.
 *
 * @param string $path
 * @param int $deleted
 * @return void
 */
function cleanupDeleteTree(string $path, int &$deleted): void {
    if (is_link($path) || !is_dir($path)) {
        cleanupUnlink($path);
        $deleted++;
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException("Cannot read cleanup directory: {$path}");
    }
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        cleanupDeleteTree("{$path}/{$item}", $deleted);
    }
    if (!rmdir($path)) {
        throw new RuntimeException("Cannot remove cleanup directory: {$path}");
    }
    $deleted++;
}

/**
 * Removes one file or symbolic link.
 *
 * @param string $file
 * @return void
 */
function cleanupUnlink(string $file): void {
    if (!unlink($file)) {
        throw new RuntimeException("Cannot remove file: {$file}");
    }
}

/**
 * Reloads valid web server configurations after their domain files are removed.
 *
 * @param array $job
 * @return void
 */
function cleanupReloadWebServers(array $job): void {
    $commands = [];
    if (is_executable("/usr/sbin/nginx")) {
        $commands[] = ["/usr/sbin/nginx -t", "nginx"];
    }
    if (is_executable("/usr/sbin/apache2ctl")) {
        $commands[] = ["/usr/sbin/apache2ctl configtest", "apache2"];
    } else if (is_executable("/usr/sbin/httpd")) {
        $commands[] = ["/usr/sbin/httpd -t", "httpd"];
    }

    foreach ($commands as [$testcommand, $service]) {
        $output = [];
        $exitcode = 0;
        exec("{$testcommand} 2>&1", $output, $exitcode);
        if ($exitcode !== 0) {
            cleanupAppendLog($job, "Could not reload {$service}: " . trim(implode(" ", $output)));
            continue;
        }
        exec("/usr/bin/systemctl reload " . escapeshellarg($service) . " 2>&1", $output, $exitcode);
        if ($exitcode !== 0) {
            cleanupAppendLog($job, "Could not reload {$service} after cleanup.");
        }
    }
}

/**
 * Appends one line to the cleanup job log.
 *
 * @param array $job
 * @param string $message
 * @return void
 */
function cleanupAppendLog(array $job, string $message): void {
    $logfile = $job["log_file"];
    if ($logfile === "") {
        return;
    }
    if (!is_dir(dirname($logfile))) {
        mkdir(dirname($logfile), 0770, true);
    }
    file_put_contents($logfile, "[" . date("Y-m-d H:i:s") . "] {$message}\n", FILE_APPEND | LOCK_EX);
    JobManager::fixLogPermissions($logfile);
}

<?php

use app\JobManager;
use app\MoodlePluginUpgrade;
use app\SiteManager;

require_once __DIR__ . '/../public/app/MoodlePluginUpgrade.php';

if (!isset($job) || !is_array($job) || ($job['type'] ?? '') !== 'plugin_upgrade') {
    throw new RuntimeException('Invalid Moodle plugin upgrade job.');
}
$job = JobManager::markRunning((string)$job['id']);
if ($job === null) {
    echo "Moodle plugin upgrade job is no longer pending.\n";
    return;
}
$logfile = (string)$job['log_file'];
if (!is_dir(dirname($logfile)) && !mkdir(dirname($logfile), 0750, true)) {
    throw new RuntimeException('Could not create Moodle upgrade log directory.');
}
file_put_contents($logfile, '[' . date('c') . '] Starting Moodle CLI upgrade for '
    . $job['domain'] . ' (' . $job['component'] . ").\n", FILE_APPEND | LOCK_EX);
try {
    $site = SiteManager::get((string)$job['domain']);
    if ($site === null) {
        throw new RuntimeException('Moodle installation not found: ' . $job['domain']);
    }
    MoodlePluginUpgrade::run($site, $logfile);
    file_put_contents($logfile, '[' . date('c') . "] Moodle CLI upgrade completed.\n", FILE_APPEND | LOCK_EX);
    JobManager::markDone((string)$job['id'], ['upgrade_message' => 'Moodle CLI upgrade completed.']);
    echo 'Moodle CLI upgrade completed: ' . $job['domain'] . "\n";
} catch (Throwable $error) {
    file_put_contents($logfile, '[' . date('c') . '] ERROR: ' . $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    JobManager::markFailed((string)$job['id'], $error->getMessage());
    fwrite(STDERR, 'Moodle CLI upgrade failed: ' . $error->getMessage() . "\n");
} finally {
    JobManager::fixLogPermissions($logfile);
}

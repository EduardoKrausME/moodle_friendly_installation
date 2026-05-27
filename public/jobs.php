<?php

use app\Auth;
use app\JobManager;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$jobs = [];
$shouldrefresh = false;

foreach (JobManager::all() as $job) {
    $status = (string) ($job['status'] ?? 'pending');
    $statusclass = preg_replace('/[^a-z0-9_-]/', '-', $status);
    $createdat = '';
    $log = '';

    if (!empty($job['created_at'])) {
        $timestamp = strtotime((string) $job['created_at']);
        if ($timestamp !== false) {
            $createdat = date('d/m/Y H:i', $timestamp);
        }
    }

    if (!empty($job['log_file']) && is_readable((string) $job['log_file'])) {
        $log = (string) file_get_contents((string) $job['log_file']);
    }

    if (in_array($status, ['running', 'pending', 'waiting_dns'], true)) {
        $shouldrefresh = true;
    }

    $jobs[] = [
        'id' => (string) ($job['id'] ?? ''),
        'domain' => (string) ($job['domain'] ?? ''),
        'status_class' => $statusclass,
        'status_badge' => status_badge($status),
        'created_at' => $createdat,
        'has_error' => !empty($job['error']),
        'error' => (string) ($job['error'] ?? ''),
        'has_log' => $log !== '',
        'log' => $log,
    ];
}

$flash = flash_message();

render_header(t('jobs.title'));
echo render_app_template('page/jobs', [
    'has_flash' => !empty($flash),
    'flash' => (string) $flash,
    'has_jobs' => !empty($jobs),
    'jobs' => $jobs,
    'should_refresh' => $shouldrefresh,
]);
render_footer();

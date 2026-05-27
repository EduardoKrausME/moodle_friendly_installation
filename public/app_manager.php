<?php

use app\AppManager;
use app\Auth;
use app\JobManager;
use app\SiteManager;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? $_GET['domain'] : '';
$site = SiteManager::details($domain);

if ($site === null) {
    http_response_code(404);
    render_header('Moodle não encontrado');
    echo render_app_template('page/details-not-found');
    render_footer();
    exit;
}

$domain = (string) ($site['domain'] ?? $domain);
$settings = AppManager::getSettings($site);
$errors = [];
$values = $settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save_app';
    $settingsvalidation = AppManager::validateSettings($_POST, $domain);
    $values = array_merge($values, $settingsvalidation['data']);
    $errors = $settingsvalidation['errors'];

    $uploadedicon = $_FILES['icon'] ?? null;
    $needsicon = empty($settings['has_icon']);
    $iconvalidation = AppManager::validateIconUpload(is_array($uploadedicon) ? $uploadedicon : null, $needsicon);
    if (!$iconvalidation['valid']) {
        $errors['icon'] = $iconvalidation['error'];
    }

    if (empty($errors)) {
        $settings = AppManager::saveSettings(
            $domain,
            $settingsvalidation['data'],
            is_array($uploadedicon) ? $uploadedicon : null
        );
        $values = $settings;

        if ($action === 'build_app') {
            if (AppManager::hasActiveBuildJob($domain)) {
                $_SESSION['flash'] = 'Já existe um build do APP pendente ou em execução para este Moodle.';
                redirect_to('/app_manager.php?domain=' . urlencode($domain));
            }

            $job = JobManager::createAppBuildJob([
                'domain' => $domain,
                'package_uid' => $settings['package_uid'],
                'package_name' => $settings['package_name'],
                'statusbarbackgroundcolor' => $settings['statusbarbackgroundcolor'],
                'icon_path' => $settings['icon_path'],
                'app_version' => AppManager::appVersion(),
            ]);

            $_SESSION['flash'] = 'Build do APP enfileirado. O CRON root vai gerar APK e AAB no job ' . $job['id'] . '.';
            redirect_to('/app_manager.php?domain=' . urlencode($domain));
        }

        $_SESSION['flash'] = 'Configuração do APP salva.';
        redirect_to('/app_manager.php?domain=' . urlencode($domain));
    }
}

$flash = flash_message();
$buildfiles = AppManager::buildFiles($domain);
$latestjob = AppManager::latestJob($domain);
$latestjobcontext = null;
$shouldrefresh = false;

if ($latestjob !== null) {
    $status = (string) ($latestjob['status'] ?? 'pending');
    $shouldrefresh = in_array($status, ['pending', 'running'], true);
    $createdat = '';
    if (!empty($latestjob['created_at'])) {
        $timestamp = strtotime((string) $latestjob['created_at']);
        if ($timestamp !== false) {
            $createdat = date('d/m/Y H:i', $timestamp);
        }
    }

    $latestjobcontext = [
        'id' => (string) ($latestjob['id'] ?? ''),
        'status_badge' => status_badge($status),
        'created_at' => $createdat,
        'has_error' => !empty($latestjob['error']),
        'error' => (string) ($latestjob['error'] ?? ''),
    ];
}

render_header('Gerenciar APP');
echo render_app_template('page/app-manager', [
    'domain' => $domain,
    'moodle_url' => (string) ($site['url'] ?? ''),
    'back_url' => '/details.php?domain=' . urlencode($domain),
    'csrf_token' => csrf_token(),
    'has_flash' => $flash !== null && $flash !== '',
    'flash' => (string) $flash,
    'values' => [
        'package_uid' => (string) ($values['package_uid'] ?? AppManager::defaultPackageUid($domain)),
        'package_name' => (string) ($values['package_name'] ?? ''),
        'statusbarbackgroundcolor' => (string) ($values['statusbarbackgroundcolor'] ?? '#08422a'),
        'has_icon' => !empty($values['has_icon']),
        'icon_path' => (string) ($values['icon_path'] ?? ''),
    ],
    'app_version' => AppManager::appVersion(),
    'errors' => [
        'package_uid' => (string) ($errors['package_uid'] ?? ''),
        'package_name' => (string) ($errors['package_name'] ?? ''),
        'statusbarbackgroundcolor' => (string) ($errors['statusbarbackgroundcolor'] ?? ''),
        'icon' => (string) ($errors['icon'] ?? ''),
    ],
    'has_errors' => !empty($errors),
    'build_files' => $buildfiles,
    'has_build_files' => !empty($buildfiles),
    'latest_job' => $latestjobcontext,
    'has_latest_job' => $latestjobcontext !== null,
    'should_refresh' => $shouldrefresh,
]);
render_footer();

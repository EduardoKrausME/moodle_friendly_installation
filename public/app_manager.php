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
    render_header(t('details.not_found_title'));
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
    $settingsvalidation = AppManager::validateSettings($_POST, $domain, $settings);
    $values = array_merge($values, $settingsvalidation['data']);
    $errors = $settingsvalidation['errors'];

    $packageuid = (string) ($settingsvalidation['data']['package_uid'] ?? $values['package_uid'] ?? AppManager::defaultPackageUid($domain));
    $uploadedicon = $_FILES['icon'] ?? null;
    $needsicon = !AppManager::hasIcon($packageuid);
    $iconvalidation = AppManager::validateIconUpload(is_array($uploadedicon) ? $uploadedicon : null, $needsicon);
    if (!$iconvalidation['valid']) {
        $errors['icon'] = $iconvalidation['error'];
    }

    $needskeystorepassword = !AppManager::hasAndroidKeystorePassword($packageuid);
    $passwordvalidation = AppManager::validateKeystorePassword(
        isset($_POST['keystore_password']) && is_string($_POST['keystore_password']) ? $_POST['keystore_password'] : '',
        $needskeystorepassword
    );
    if (!$passwordvalidation['valid']) {
        $errors['keystore_password'] = $passwordvalidation['error'];
    }

    if (empty($errors)) {
        try {
            $settings = AppManager::saveSettings(
                $domain,
                $settingsvalidation['data'],
                is_array($uploadedicon) ? $uploadedicon : null
            );

            if (!AppManager::hasAndroidKeyFiles((string) $settings['package_uid'])) {
                AppManager::ensureAndroidKeyFiles(
                    (string) $settings['package_uid'],
                    $passwordvalidation['password'] !== '' ? (string) $passwordvalidation['password'] : null
                );
            }

            $settings = AppManager::getSettings($site);
            $values = $settings;
            $readiness = AppManager::buildReadiness($settings, $domain);

            if ($action === 'build_app') {
                if (!$readiness['valid']) {
                    $errors['build'] = t('app_manager.not_ready');
                } else {
                    $job = JobManager::createAppBuildJob([
                        'domain' => $domain,
                        'package_uid' => $settings['package_uid'],
                        'package_name' => $settings['package_name'],
                        'statusbarbackgroundcolor' => $settings['statusbarbackgroundcolor'],
                        'icon_path' => $settings['icon_path'],
                        'app_version' => AppManager::appVersion(),
                    ]);

                    $_SESSION['flash'] = t('app_manager.build_queued', ['id' => $job['id']]);
                    redirect_to('/app_manager.php?domain=' . urlencode($domain));
                }
            } else {
                $_SESSION['flash'] = t('app_manager.saved');
                redirect_to('/app_manager.php?domain=' . urlencode($domain));
            }
        } catch (Throwable $e) {
            $errors['general'] = $e->getMessage();
            $values = array_merge($values, $settingsvalidation['data']);
        }
    }
}

$flash = flash_message();
$settings = AppManager::getSettings($site);
$values = array_merge($settings, $values);
$packageuid = (string) ($values['package_uid'] ?? AppManager::defaultPackageUid($domain));
$values['resource_dir'] = AppManager::resourceDir($packageuid);
$values['icon_path'] = AppManager::iconPath($packageuid);
$values['has_icon'] = AppManager::hasIcon($packageuid);
$values['has_keystore_password'] = AppManager::hasAndroidKeystorePassword($packageuid);
$values['has_android_key_files'] = AppManager::hasAndroidKeyFiles($packageuid);
$buildreadiness = AppManager::buildReadiness($values, $domain);
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

$packageuid = (string) ($values['package_uid'] ?? AppManager::defaultPackageUid($domain));
$showkeystorepassword = !AppManager::hasAndroidKeystorePassword($packageuid);

render_header(t('app_manager.title'));
echo render_app_template('page/app-manager', [
    'domain' => $domain,
    'moodle_url' => (string) ($site['url'] ?? ''),
    'back_url' => '/details.php?domain=' . urlencode($domain),
    'csrf_token' => csrf_token(),
    'has_flash' => $flash !== null && $flash !== '',
    'flash' => (string) $flash,
    'values' => [
        'package_uid' => $packageuid,
        'package_uid_locked' => !empty($values['package_uid_locked']),
        'package_name' => (string) ($values['package_name'] ?? ''),
        'statusbarbackgroundcolor' => (string) ($values['statusbarbackgroundcolor'] ?? '#08422a'),
        'has_icon' => !empty($values['has_icon']),
        'icon_path' => (string) ($values['icon_path'] ?? ''),
        'resource_dir' => (string) ($values['resource_dir'] ?? AppManager::resourceDir($packageuid)),
        'has_android_key_files' => AppManager::hasAndroidKeyFiles($packageuid),
    ],
    'app_version' => AppManager::appVersion(),
    'errors' => [
        'general' => (string) ($errors['general'] ?? ''),
        'package_uid' => (string) ($errors['package_uid'] ?? ''),
        'package_name' => (string) ($errors['package_name'] ?? ''),
        'statusbarbackgroundcolor' => (string) ($errors['statusbarbackgroundcolor'] ?? ''),
        'icon' => (string) ($errors['icon'] ?? ''),
        'keystore_password' => (string) ($errors['keystore_password'] ?? ''),
        'build' => (string) ($errors['build'] ?? ''),
    ],
    'has_errors' => !empty($errors),
    'show_keystore_password' => $showkeystorepassword,
    'build_ready' => empty($errors) && !empty($buildreadiness['valid']),
    'build_missing' => $buildreadiness['missing'],
    'has_build_missing' => !empty($buildreadiness['missing']),
    'build_files' => $buildfiles,
    'has_build_files' => !empty($buildfiles),
    'latest_job' => $latestjobcontext,
    'has_latest_job' => $latestjobcontext !== null,
    'should_refresh' => $shouldrefresh,
]);
render_footer();

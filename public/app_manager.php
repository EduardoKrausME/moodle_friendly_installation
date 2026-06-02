<?php

use app\AppManager;
use app\Auth;
use app\JobManager;
use app\SiteManager;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';

if ($domain == '') {
    $apps = array_map(static function(array $site): array {
        $domain = $site['domain'] ?? '';
        $settings = AppManager::getSettings($site);
        $configured = is_file(AppManager::settingsFile($domain));
        $buildfiles = AppManager::buildFiles($domain);
        $latestjob = AppManager::latestJob($domain);
        $lateststatus = is_array($latestjob) ? ($latestjob['status'] ?? '') : '';

        return [
            'domain' => $domain,
            'webroot' => $site['webroot'] ?? '',
            'moodle_branch' => $site['moodle_branch'] ?? '',
            'package_uid' => $settings['package_uid'] ?? '',
            'package_name' => $settings['package_name'] ?? '',
            'manage_url' => '/app_manager.php?domain=' . rawurlencode($domain),
            'configured' => $configured,
            'status_badge' => $configured
                ? status_badge('ok', t('app_manager.configured'))
                : status_badge('warning', t('app_manager.not_configured')),
            'has_build_files' => !empty($buildfiles),
            'build_files_count' => (string) count($buildfiles),
            'has_latest_job' => $lateststatus != '',
            'latest_job_status_badge' => $lateststatus != '' ? status_badge($lateststatus) : '',
        ];
    }, SiteManager::all());

    $flash = flash_message();

    render_header(t('app_manager.list_title'));
    echo render_app_template('page/app-manager-list', [
        'flash' => $flash,
        'has_flash' => $flash != null && $flash != '',
        'has_apps' => !empty($apps),
        'apps' => $apps,
    ]);
    render_footer();
    exit;
}

$site = SiteManager::details($domain);

if ($site == null) {
    http_response_code(404);
    render_header(t('details.not_found_title'));
    echo render_app_template('page/details-not-found');
    render_footer();
    exit;
}

$domain = $site['domain'] ?? $domain;
$settings = AppManager::getSettings($site);
$errors = [];
$values = $settings;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    validate_csrf();

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'save_app';
    $settingsvalidation = AppManager::validateSettings($_POST, $domain, $settings);
    $values = array_merge($values, $settingsvalidation['data']);
    $errors = $settingsvalidation['errors'];

    $packageuid = $settingsvalidation['data']['package_uid'] ?? $values['package_uid'] ?? AppManager::defaultPackageUid($domain);
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

            if (!AppManager::hasAndroidKeyFiles($settings['package_uid'])) {
                AppManager::ensureAndroidKeyFiles(
                   $settings['package_uid'],
                    $passwordvalidation['password'] != '' ?$passwordvalidation['password'] : null
                );
            }

            $settings = AppManager::getSettings($site);
            $values = $settings;
            $readiness = AppManager::buildReadiness($settings, $domain);
            $moodleconfigtest = AppManager::moodleConfigTest($domain);
            $readiness = AppManager::applyMoodleConfigTestToReadiness($readiness, $moodleconfigtest);

            if ($action == 'build_app') {
                if (!$readiness['valid']) {
                    $errors['build'] = t('app_manager.not_ready');
                } else {
                    $job = JobManager::createAppBuildJob([
                        'domain' => $domain,
                        'moodle_url' => $site['url'] ?? ('https://' . $domain),
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
$packageuid = $values['package_uid'] ?? AppManager::defaultPackageUid($domain);
$values['resource_dir'] = AppManager::resourceDir($packageuid);
$values['icon_path'] = AppManager::iconPath($packageuid);
$values['has_icon'] = AppManager::hasIcon($packageuid);
$values['has_keystore_password'] = AppManager::hasAndroidKeystorePassword($packageuid);
$values['has_android_key_files'] = AppManager::hasAndroidKeyFiles($packageuid);
$buildreadiness = AppManager::buildReadiness($values, $domain);
$moodleconfigtest = AppManager::moodleConfigTest($domain);
$buildreadiness = AppManager::applyMoodleConfigTestToReadiness($buildreadiness, $moodleconfigtest);
$buildfiles = AppManager::buildFiles($domain);
$latestjob = AppManager::latestJob($domain);
$latestjobcontext = null;
$shouldrefresh = false;

if ($latestjob != null) {
    $status = $latestjob['status'] ?? 'pending';
    $shouldrefresh = in_array($status, ['pending', 'running'], true);
    $createdat = '';
    if (!empty($latestjob['created_at'])) {
        $timestamp = strtotime($latestjob['created_at']);
        if ($timestamp != false) {
            $createdat = date('d/m/Y H:i', $timestamp);
        }
    }

    $latestjobcontext = [
        'id' => $latestjob['id'] ?? '',
        'status_badge' => status_badge($status),
        'created_at' => $createdat,
        'has_error' => !empty($latestjob['error']),
        'error' => $latestjob['error'] ?? '',
    ];
}

$packageuid = $values['package_uid'] ?? AppManager::defaultPackageUid($domain);
$showkeystorepassword = !AppManager::hasAndroidKeystorePassword($packageuid);

render_header(t('app_manager.title'));
echo render_app_template('page/app-manager', [
    'domain' => $domain,
    'moodle_url' => $site['url'] ?? '',
    'back_url' => '/details.php?domain=' . urlencode($domain),
    'csrf_token' => csrf_token(),
    'has_flash' => $flash != null && $flash != '',
    'flash' =>$flash,
    'values' => [
        'package_uid' => $packageuid,
        'package_uid_locked' => !empty($values['package_uid_locked']),
        'package_name' => $values['package_name'] ?? '',
        'statusbarbackgroundcolor' => $values['statusbarbackgroundcolor'] ?? '#08422a',
        'has_icon' => !empty($values['has_icon']),
        'icon_path' => $values['icon_path'] ?? '',
        'resource_dir' => $values['resource_dir'] ?? AppManager::resourceDir($packageuid),
        'has_android_key_files' => AppManager::hasAndroidKeyFiles($packageuid),
    ],
    'app_version' => AppManager::appVersion(),
    'moodle_config_test' => $moodleconfigtest,
    'has_moodle_config_test_error' => !empty($moodleconfigtest['has_error']),
    'has_moodle_config_test_warnings' => !empty($moodleconfigtest['has_warnings']),
    'moodle_config_test_valid' => !empty($moodleconfigtest['valid']),
    'errors' => [
        'general' => $errors['general'] ?? '',
        'package_uid' => $errors['package_uid'] ?? '',
        'package_name' => $errors['package_name'] ?? '',
        'statusbarbackgroundcolor' => $errors['statusbarbackgroundcolor'] ?? '',
        'icon' => $errors['icon'] ?? '',
        'keystore_password' => $errors['keystore_password'] ?? '',
        'build' => $errors['build'] ?? '',
    ],
    'has_errors' => !empty($errors),
    'show_keystore_password' => $showkeystorepassword,
    'build_ready' => empty($errors) && !empty($buildreadiness['valid']),
    'build_missing' => $buildreadiness['missing'],
    'has_build_missing' => !empty($buildreadiness['missing']),
    'build_files' => $buildfiles,
    'has_build_files' => !empty($buildfiles),
    'latest_job' => $latestjobcontext,
    'has_latest_job' => $latestjobcontext != null,
    'should_refresh' => $shouldrefresh,
]);
render_footer();

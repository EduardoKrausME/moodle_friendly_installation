<?php

use app\AppManager;
use app\Auth;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'toggle_flag') {
        $flag = isset($_POST['flag']) && is_string($_POST['flag']) ? $_POST['flag'] : '';
        $enabled = !empty($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $value = isset($_POST['value']) && is_string($_POST['value']) ? $_POST['value'] : null;
        $result = SiteManager::setFeatureFlag($site, $flag, $enabled, $value);
        $_SESSION['flash'] = $result['message'] ?? t('details.action_done');
        redirect_to('/details.php?domain=' . urlencode($domain));
    }

    // Compatibility with the previous debug form/action names.
    if ($action === 'enable_debug' || $action === 'disable_debug') {
        $result = SiteManager::setDebugMode($site, $action === 'enable_debug');
        $_SESSION['flash'] = $result['message'] ?? t('details.action_done');
        redirect_to('/details.php?domain=' . urlencode($domain));
    }
}

$config = $site['moodle_config'] ?? [];
$diagnostics = $site['diagnostics'] ?? [];
$stats = $site['database_stats'] ?? ['connected' => false, 'items' => [], 'error' => ''];
$featureflags = $diagnostics['feature_flags'] ?? [];
$flash = flash_message();

render_header(t('details.title'));
echo render_app_template('page/details', details_page_context($site, $config, $diagnostics, $stats, $featureflags, $flash));
render_footer();

function details_page_context(
    array $site,
    array $config,
    array $diagnostics,
    array $stats,
    array $featureflags,
    ?string $flash
): array {
    $statsconnected = !empty($stats['connected']);
    $statsitems = is_array($stats['items'] ?? null) ? $stats['items'] : [];

    $domain = (string) ($site['domain'] ?? '');
    $appsettings = AppManager::getSettings($site);
    $appfiles = AppManager::buildFiles($domain);

    return [
        'domain' => $domain,
        'moodle_branch' => (string) ($site['moodle_branch'] ?? ''),
        'url' => (string) ($site['url'] ?? ''),
        'sso_url' => (string) ($site['sso_url'] ?? ''),
        'app_exist' => file_exists("../app-MoodleMobile-V2/config.xml"),
        'app_manage_url' => '/app_manager.php?domain=' . urlencode($domain),
        'app_package_uid' => (string) ($appsettings['package_uid'] ?? ''),
        'app_package_name' => (string) ($appsettings['package_name'] ?? ''),
        'app_has_files' => !empty($appfiles),
        'app_files' => $appfiles,
        'has_flash' => $flash !== null && $flash !== '',
        'flash' => (string) $flash,
        'stats_warning' => !$statsconnected,
        'stats_error' => (string) ($stats['error'] ?? t('details.unknown_error')),
        'stat_boxes' => [
            [
                'label' => t('details.users'),
                'value' => $statsconnected ? details_format_count($statsitems['users'] ?? 0) : '-',
                'description' => t('details.users_description'),
            ],
            [
                'label' => t('details.courses'),
                'value' => $statsconnected ? details_format_count($statsitems['courses'] ?? 0) : '-',
                'description' => t('details.courses_description'),
            ],
            [
                'label' => t('details.enrolments'),
                'value' => $statsconnected ? details_format_count($statsitems['enrolments'] ?? 0) : '-',
                'description' => t('details.enrolments_description'),
            ],
            [
                'label' => t('details.active_enrolments'),
                'value' => $statsconnected ? details_format_count($statsitems['active_enrolments'] ?? 0) : '-',
                'description' => t('details.active_enrolments_description'),
            ],
        ],
        'diagnostic_rows' => [
            details_diagnostic_row('NGINX', $diagnostics['nginx'] ?? []),
            details_diagnostic_row('HTTPD / Apache', $diagnostics['httpd'] ?? []),
            details_diagnostic_row('DNS', $diagnostics['dns'] ?? []),
            details_diagnostic_row('SSL', $diagnostics['ssl'] ?? []),
        ],
        'feature_flags' => details_feature_flags($featureflags),
        'has_feature_flags' => !empty($featureflags),
        'file_rows' => [
            details_info_row('Base', $site['base_dir'] ?? ''),
            details_info_row('Moodle', $site['moodle_dir'] ?? ''),
            details_info_row('Webroot', $site['webroot'] ?? ''),
            details_info_row('Moodledata', $site['dataroot'] ?? ''),
            details_info_row('config.php', $site['config_file'] ?? ''),
        ],
        'config_rows' => [
            details_info_row('wwwroot', $config['wwwroot'] ?? ''),
            details_info_row('dbtype', $config['dbtype'] ?? ''),
            details_info_row('dbhost', $config['dbhost'] ?? ''),
            details_info_row('dbname', $config['dbname'] ?? ''),
            details_info_row('dbuser', $config['dbuser'] ?? ''),
            details_info_row('prefix', $config['prefix'] ?? 'mdl_'),
            details_info_row('dbcollation', $config['dbcollation'] ?? ''),
            details_info_row('sslproxy', $config['sslproxy'] ?? '', false),
        ],
    ];
}

function details_info_row(string $label, mixed $value, bool $code = true): array {
    return [
        'label' => $label,
        'value' => details_value($value),
        'is_code' => $code,
        'is_plain' => !$code,
    ];
}

function details_diagnostic_row(string $label, array $item): array {
    $resolvedips = !empty($item['resolved_ips']) && is_array($item['resolved_ips'])
        ? implode(', ', $item['resolved_ips'])
        : '';
    $serverips = !empty($item['server_ips']) && is_array($item['server_ips'])
        ? implode(', ', $item['server_ips'])
        : '';

    return [
        'label' => $label,
        'badge_html' => status_badge((string) ($item['status'] ?? 'muted'), (string) ($item['label'] ?? '-')),
        'message' => (string) ($item['message'] ?? ''),
        'has_path' => !empty($item['path']),
        'path' => (string) ($item['path'] ?? ''),
        'has_resolved_ips' => $resolvedips !== '',
        'resolved_ips' => $resolvedips,
        'has_server_ips' => $serverips !== '',
        'server_ips' => $serverips,
        'has_valid_to' => !empty($item['valid_to']),
        'valid_to' => (string) ($item['valid_to'] ?? ''),
        'has_issuer' => !empty($item['issuer']),
        'issuer' => (string) ($item['issuer'] ?? ''),
    ];
}

function details_feature_flags(array $featureflags): array {
    $items = [];

    foreach ($featureflags as $flag => $item) {
        if (!is_array($item)) {
            continue;
        }

        $enabled = !empty($item['enabled']);
        $needsvalue = !empty($item['value_type']);
        $buttonclass = $enabled ? 'button secondary' : (!empty($item['dangerous']) ? 'button warning' : 'button');
        $buttonlabel = $enabled ? t('actions.disable') : t('actions.enable');

        if ($needsvalue && $enabled) {
            $buttonclass = 'button';
            $buttonlabel = t('actions.save');
        }

        $items[] = [
            'flag' => (string) $flag,
            'label' => (string) ($item['label'] ?? $flag),
            'control_class' => $enabled ? 'flag-control alert alert-danger' : 'flag-control',
            'status_badge_html' => status_badge((string) ($item['status'] ?? 'muted'), (string) ($item['status_label'] ?? '-')),
            'description' => (string) ($item['description'] ?? ''),
            'has_path' => !empty($item['path']),
            'path' => (string) ($item['path'] ?? ''),
            'needs_value' => $needsvalue,
            'no_value' => !$needsvalue,
            'value' => (string) ($item['value'] ?? ''),
            'enabled_value' => $enabled ? '0' : '1',
            'show_disable_when_value_enabled' => $needsvalue && $enabled,
            'button_class' => $buttonclass,
            'button_label' => $buttonlabel,
            'csrf_token' => csrf_token(),
        ];
    }

    return $items;
}

function details_value(mixed $value): string {
    if (is_bool($value)) {
        return $value ? t('details.yes') : t('details.no');
    }
    if ($value === null || $value === '') {
        return '-';
    }
    return (string) $value;
}

function details_format_count(mixed $value): string {
    return number_format((int) $value, 0, ',', '.');
}

<?php

use app\Auth;
use app\SiteManager;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$sites = array_map(static function (array $site): array {
    $domain = (string)($site['domain'] ?? '');

    return [
        'domain' => $domain,
        'webroot' => (string)($site['webroot'] ?? ''),
        'moodle_branch' => (string)($site['moodle_branch'] ?? ''),
        'details_url' => '/details.php?domain=' . rawurlencode($domain),
        'status_badge' => status_badge('active'),
    ];
}, SiteManager::all());

$flash = flash_message();

render_header('Sites');
echo render_app_template('page/index', [
    'flash' => $flash,
    'has_flash' => $flash !== null && $flash !== '',
    'has_sites' => !empty($sites),
    'sites' => $sites,
]);
render_footer();
<?php

use app\Auth;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$sites = array_map(static function(array $site): array {
    $domain = $site["domain"] ?? "";

    return [
        "domain" => $domain,
        "webroot" => $site["webroot"] ?? "",
        "moodle_branch" => $site["moodle_branch"] ?? "",
        "details_url" => "/details.php?domain=" . rawurlencode($domain),
        "status_badge" => status_badge("active"),
    ];
}, SiteManager::all());

$flash = flash_message();

render_header(t("index.title"));
echo render_app_template("page/index", [
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "has_sites" => !empty($sites),
    "sites" => $sites,
]);
render_footer();
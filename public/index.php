<?php

use app\Auth;
use app\ResourceUsageManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$sites = array_map(static function(array $site): array {
    $domain = $site["domain"] ?? "";
    $resources = ResourceUsageManager::snapshot($domain);
    $identity = SiteManager::courseIdentity($site);

    return [
        "domain" => $domain,
        "has_site_identity" => !empty($identity["available"]),
        "site_fullname" => $identity["fullname"] ?? "",
        "site_shortname" => $identity["shortname"] ?? "",
        "webroot" => $site["webroot"] ?? "",
        "moodle_branch" => $site["moodle_branch"] ?? "",
        "details_url" => "/details.php?domain=" . rawurlencode($domain),
        "status_badge" => status_badge((string) ($site["status"] ?? "active")),
        "has_resource_usage" => !empty($resources),
        "resource_usage" => !empty($resources)
            ? ResourceUsageManager::formatBytes((int) ($resources["total_bytes"] ?? 0))
            : "",
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

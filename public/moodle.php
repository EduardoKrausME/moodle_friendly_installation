<?php

use app\Auth;
use app\ResourceUsageManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$sites = array_map(static function(array $site): array {
    $domain = $site["domain"] ?? "";
    $installationcomplete = !empty($site["installation_complete"]);
    $resources = $installationcomplete ? ResourceUsageManager::snapshot($domain) : [];
    $identity = $installationcomplete
        ? SiteManager::courseIdentity($site)
        : ["fullname" => "", "shortname" => "", "available" => false];

    return [
        "domain" => $domain,
        "has_site_identity" => !empty($identity["available"]),
        "site_fullname" => $identity["fullname"] ?? "",
        "site_shortname" => $identity["shortname"] ?? "",
        "webroot" => $site["webroot"] ?? "",
        "moodle_branch" => $site["moodle_branch"] ?? "",
        "details_url" => $installationcomplete
            ? "/details.php?domain=" . rawurlencode($domain)
            : ($site["installation_job_url"] ?? "/jobs.php"),
        "details_label" => t($installationcomplete ? "actions.view_details" : "jobs.open_job"),
        "status_badge" => status_badge((string) ($site["status"] ?? "active")),
        "has_resource_usage" => !empty($resources),
        "resource_usage" => !empty($resources)
            ? ResourceUsageManager::formatBytes((int) ($resources["total_bytes"] ?? 0))
            : "",
    ];
}, SiteManager::all());

$flash = flash_message();

render_header(t("moodle.title"));
echo render_app_template("page/moodle", [
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "has_sites" => !empty($sites),
    "sites" => $sites,
    "csrf_token" => csrf_token(),
]);
render_footer();

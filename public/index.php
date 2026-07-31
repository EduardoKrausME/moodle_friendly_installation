<?php

use app\Auth;
use app\ResourceUsageManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$formatpercentage = static function(float $value): string {
    return number_format($value, 1, t("decimal_separator"), t("thousands_separator")) . "%";
};

$diskpath = app_config("base_dir");
if (!is_string($diskpath) || !is_dir($diskpath)) {
    $diskpath = __DIR__;
}

$disktotal = @disk_total_space($diskpath);
$diskfree = @disk_free_space($diskpath);
$hasdiskdata = is_float($disktotal) && $disktotal > 0 && is_float($diskfree);
$diskused = $hasdiskdata ? max(0, $disktotal - $diskfree) : 0;
$diskpercent = $hasdiskdata ? min(100, max(0, ($diskused / $disktotal) * 100)) : 0;

$memoryvalues = [];
if (is_readable("/proc/meminfo")) {
    $memorycontents = file_get_contents("/proc/meminfo");
    if (is_string($memorycontents)) {
        foreach (preg_split('/\R/', $memorycontents) as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                $memoryvalues[$matches[1]] = (int) $matches[2] * 1024;
            }
        }
    }
}

$memorytotal = (int) ($memoryvalues["MemTotal"] ?? 0);
$memoryavailable = (int) ($memoryvalues["MemAvailable"]
    ?? (($memoryvalues["MemFree"] ?? 0) + ($memoryvalues["Buffers"] ?? 0) + ($memoryvalues["Cached"] ?? 0)));
$hasmemorydata = $memorytotal > 0;
$memoryused = $hasmemorydata ? max(0, $memorytotal - $memoryavailable) : 0;
$memorypercent = $hasmemorydata ? min(100, max(0, ($memoryused / $memorytotal) * 100)) : 0;

$loadaverages = function_exists("sys_getloadavg") ? sys_getloadavg() : false;
$hasloaddata = is_array($loadaverages) && count($loadaverages) >= 3;
$cpucores = 0;
if (is_readable("/proc/cpuinfo")) {
    $cpuinfo = file_get_contents("/proc/cpuinfo");
    if (is_string($cpuinfo)) {
        $cpucores = preg_match_all('/^processor\s*:/m', $cpuinfo);
    }
}
if ($cpucores < 1) {
    $environmentcores = getenv("NUMBER_OF_PROCESSORS");
    $cpucores = is_string($environmentcores) && ctype_digit($environmentcores)
        ? (int) $environmentcores
        : 0;
}

$uptime = t("server.unavailable");
if (is_readable("/proc/uptime")) {
    $uptimecontents = file_get_contents("/proc/uptime");
    if (is_string($uptimecontents)) {
        $uptimeseconds = (int) floor((float) strtok($uptimecontents, " "));
        $uptimedays = intdiv($uptimeseconds, 86400);
        $uptimehours = intdiv($uptimeseconds % 86400, 3600);
        $uptimeminutes = intdiv($uptimeseconds % 3600, 60);
        $uptime = $uptimedays > 0
            ? "{$uptimedays}d {$uptimehours}h"
            : ($uptimehours > 0 ? "{$uptimehours}h {$uptimeminutes}min" : "{$uptimeminutes}min");
    }
}

$unknownvalue = t("server.unavailable");
$serveroverview = [
    "disk" => [
        "has_data" => $hasdiskdata,
        "used" => $hasdiskdata ? ResourceUsageManager::formatBytes((int) $diskused) : $unknownvalue,
        "total" => $hasdiskdata ? ResourceUsageManager::formatBytes((int) $disktotal) : $unknownvalue,
        "free" => $hasdiskdata ? ResourceUsageManager::formatBytes((int) $diskfree) : $unknownvalue,
        "percent" => $hasdiskdata ? $formatpercentage($diskpercent) : $unknownvalue,
        "percent_raw" => round($diskpercent, 1),
    ],
    "memory" => [
        "has_data" => $hasmemorydata,
        "used" => $hasmemorydata ? ResourceUsageManager::formatBytes($memoryused) : $unknownvalue,
        "total" => $hasmemorydata ? ResourceUsageManager::formatBytes($memorytotal) : $unknownvalue,
        "available" => $hasmemorydata ? ResourceUsageManager::formatBytes($memoryavailable) : $unknownvalue,
        "percent" => $hasmemorydata ? $formatpercentage($memorypercent) : $unknownvalue,
        "percent_raw" => round($memorypercent, 1),
    ],
    "load" => [
        "has_data" => $hasloaddata,
        "one" => $hasloaddata ? number_format((float) $loadaverages[0], 2, t("decimal_separator"), "") : $unknownvalue,
        "five" => $hasloaddata ? number_format((float) $loadaverages[1], 2, t("decimal_separator"), "") : $unknownvalue,
        "fifteen" => $hasloaddata ? number_format((float) $loadaverages[2], 2, t("decimal_separator"), "") : $unknownvalue,
        "cpu_cores" => $cpucores > 0 ? (string) $cpucores : $unknownvalue,
    ],
    "environment" => [
        "php_version" => PHP_VERSION,
        "php_sapi" => PHP_SAPI,
        "operating_system" => trim(php_uname("s") . " " . php_uname("r")),
        "uptime" => $uptime,
    ],
];

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

render_header(t("index.title"));
echo render_app_template("page/index", [
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "server" => $serveroverview,
    "has_sites" => !empty($sites),
    "sites" => $sites,
]);
render_footer();

<?php

use app\AppManager;
use app\Auth;
use app\JobManager;
use app\ResourceUsageManager;
use app\ServerControlManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? $_GET["domain"] : "";
$site = SiteManager::details($domain);

if ($site == null) {
    http_response_code(404);
    render_header(t("details.not_found_title"));
    echo render_app_template("page/details-not-found");
    render_footer();
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();
    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "";

    if ($action == "toggle_flag") {
        $flag = isset($_POST["flag"]) && is_string($_POST["flag"]) ? $_POST["flag"] : "";
        $enabled = !empty($_POST["enabled"]) && $_POST["enabled"] == "1";
        $value = isset($_POST["value"]) && is_string($_POST["value"]) ? $_POST["value"] : null;
        $result = SiteManager::setFeatureFlag($site, $flag, $enabled, $value);
        $_SESSION["flash"] = $result["message"] ?? t("details.action_done");
        redirect_to("/details.php?domain=" . urlencode($domain));
    }

    if ($action == "toggle_moodle") {
        $enabled = isset($_POST["enabled"]) && $_POST["enabled"] === "1";
        $message = isset($_POST["message"]) && is_string($_POST["message"]) ? $_POST["message"] : null;
        $result = SiteManager::setMoodleEnabled($site, $enabled, $message);
        $_SESSION["flash"] = $result["message"] ?? t("details.action_done");
        redirect_to("/details.php?domain=" . urlencode($domain));
    }

    if ($action == "refresh_resources") {
        $job = JobManager::createResourceUsageJob($site);
        $_SESSION["flash"] = t("resources.queued", ["id" => $job["id"]]);
        redirect_to("/details.php?domain=" . urlencode($domain));
    }

    if ($action == "server_control") {
        $control = isset($_POST["control"]) && is_string($_POST["control"]) ? $_POST["control"] : "";
        $enabled = isset($_POST["enabled"]) && $_POST["enabled"] === "1";
        if (!in_array($control, ["cache"], true)) {
            $_SESSION["flash"] = t("server_controls.invalid");
        } else {
            $job = JobManager::createServerControlJob($site, $control, $enabled);
            $_SESSION["flash"] = t("server_controls.queued", ["id" => $job["id"]]);
        }
        redirect_to("/details.php?domain=" . urlencode($domain));
    }

    // Compatibility with the previous debug form/action names.
    if ($action == "enable_debug" || $action == "disable_debug") {
        $result = SiteManager::setDebugMode($site, $action == "enable_debug");
        $_SESSION["flash"] = $result["message"] ?? t("details.action_done");
        redirect_to("/details.php?domain=" . urlencode($domain));
    }
}

$config += $site["moodle_config"] ?? [];
$diagnostics = $site["diagnostics"] ?? [];
$stats = $site["database_stats"] ?? ["connected" => false, "items" => [], "error" => ""];
$featureflags = $diagnostics["feature_flags"] ?? [];
$resources = ResourceUsageManager::snapshot($domain);
$resourcejob = JobManager::activeJob("resource_usage", $domain);
$servercontrols = ServerControlManager::status($site);
$site["course_identity"] = SiteManager::courseIdentity($site);
$flash = flash_message();

render_header($site["course_identity"]["fullname"]);
echo render_app_template(
    "page/details",
    details_page_context($site, $config, $diagnostics, $stats, $featureflags, $resources, $resourcejob, $servercontrols, $flash)
);
render_footer();

/**
 * Function details_page_context
 *
 * @param array $site
 * @param array $config
 * @param array $diagnostics
 * @param array $stats
 * @param array $featureflags
 * @param array $resources
 * @param array|null $resourcejob
 * @param array $servercontrols
 * @param string|null $flash
 * @return array
 * @throws \Random\RandomException
 */
function details_page_context(
    array $site,
    array $config,
    array $diagnostics,
    array $stats,
    array $featureflags,
    array $resources,
    ?array $resourcejob,
    array $servercontrols,
    ?string $flash
): array {
    $statsconnected = !empty($stats["connected"]);
    $statsitems = is_array($stats["items"] ?? null) ? $stats["items"] : [];

    $domain = $site["domain"] ?? "";
    $siteidentity = is_array($site["course_identity"] ?? null) ? $site["course_identity"] : [];
    $appsettings = AppManager::getSettings($site);
    $appfiles = AppManager::buildFiles($domain);

    return [
        "domain" => $domain,
        "has_site_identity" => !empty($siteidentity["available"]),
        "site_fullname" => $siteidentity["fullname"] ?? "",
        "site_shortname" => $siteidentity["shortname"] ?? "",
        "moodle_branch" => $site["moodle_branch"] ?? "",
        "moodle_status_badge" => status_badge((string) ($site["status"] ?? "active")),
        "moodle_is_enabled" => !empty($site["moodle_enabled"]),
        "moodle_is_disabled" => empty($site["moodle_enabled"]),
        "moodle_disabled_message" => $site["moodle_disabled_message"] ?? "",
        "moodle_status_csrf_token" => csrf_token(),
        "url" => $site["url"] ?? "",
        "sso_url" => $site["sso_url"] ?? "",
        "moodle_users_url" => "/moodle_users.php?domain=" . rawurlencode($domain),
        "moodle_courses_url" => "/moodle_courses.php?domain=" . rawurlencode($domain),
        "app_exist" => file_exists("../app-MoodleMobile-V2/config.xml"),
        "app_manage_url" => "/app_manager.php?domain=" . urlencode($domain),
        "app_preview_url" => "/app-preview/preview.php?package_name=" . urlencode($appsettings["package_name"]) .
            "&wwwroot=" . urlencode($config["wwwroot"]) .
            "&domain=" . urlencode($domain),
        "app_package_uid" => $appsettings["package_uid"] ?? "",
        "app_package_name" => $appsettings["package_name"] ?? "",
        "app_has_files" => !empty($appfiles),
        "app_files" => $appfiles,
        "has_flash" => $flash != null && $flash != "",
        "flash" => $flash,
        "stats_warning" => !$statsconnected,
        "stats_error" => $stats["error"] ?? t("details.unknown_error"),
        "csrf_token" => csrf_token(),
        "stat_boxes" => [
            [
                "label" => t("details.users"),
                "value" => $statsconnected ? details_format_count($statsitems["users"] ?? 0) : "-",
                "description" => t("details.users_description"),
            ],
            [
                "label" => t("details.courses"),
                "value" => $statsconnected ? details_format_count($statsitems["courses"] ?? 0) : "-",
                "description" => t("details.courses_description"),
            ],
            [
                "label" => t("details.enrolments"),
                "value" => $statsconnected ? details_format_count($statsitems["enrolments"] ?? 0) : "-",
                "description" => t("details.enrolments_description"),
            ],
            [
                "label" => t("details.active_enrolments"),
                "value" => $statsconnected ? details_format_count($statsitems["active_enrolments"] ?? 0) : "-",
                "description" => t("details.active_enrolments_description"),
            ],
        ],
        "diagnostic_rows" => [
            details_diagnostic_row("NGINX", $diagnostics["nginx"] ?? []),
            details_diagnostic_row("HTTPD / Apache", $diagnostics["httpd"] ?? []),
            details_diagnostic_row("DNS", $diagnostics["dns"] ?? []),
            details_diagnostic_row("SSL", $diagnostics["ssl"] ?? []),
        ],
        "feature_flags" => details_feature_flags($featureflags),
        "has_feature_flags" => !empty($featureflags),
        "resources" => details_resource_usage($resources, $resourcejob),
        "server_controls" => details_server_controls($domain, $servercontrols),
        "should_refresh" => $resourcejob !== null || details_has_active_server_control($domain),
        "file_rows" => [
            details_info_row("Base", $site["base_dir"] ?? ""),
            details_info_row("Moodle", $site["moodle_dir"] ?? ""),
            details_info_row("Webroot", $site["webroot"] ?? ""),
            details_info_row("Moodledata", $site["dataroot"] ?? ""),
            details_info_row("config.php", $site["config_file"] ?? ""),
        ],
        "config_rows" => [
            details_info_row("wwwroot", $config["wwwroot"] ?? ""),
            details_info_row("dbtype", $config["dbtype"] ?? ""),
            details_info_row("dbhost", $config["dbhost"] ?? ""),
            details_info_row("dbname", $config["dbname"] ?? ""),
            details_info_row("dbuser", $config["dbuser"] ?? ""),
            details_info_row("prefix", $config["prefix"] ?? "mdl_"),
            details_info_row("dbcollation", $config["dbcollation"] ?? ""),
            details_info_row("sslproxy", $config["sslproxy"] ?? "", false),
        ],
    ];
}

/**
 * Builds the resource usage section.
 *
 * @param array $snapshot
 * @param array|null $activejob
 * @return array
 */
function details_resource_usage(array $snapshot, ?array $activejob): array {
    $hassnapshot = !empty($snapshot["collected_at"]);
    $collectedat = "";
    if ($hassnapshot) {
        $timestamp = strtotime((string) $snapshot["collected_at"]);
        $collectedat = $timestamp ? date("d/m/Y H:i:s", $timestamp) : (string) $snapshot["collected_at"];
    }

    $items = [
        [
            "label" => t("resources.moodle_code"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["moodle_code_bytes"] ?? 0)),
        ],
        [
            "label" => t("resources.moodledata"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["moodledata_bytes"] ?? 0)),
        ],
        [
            "label" => t("resources.database"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["database_bytes"] ?? 0)),
        ],
        [
            "label" => t("resources.site_total"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["total_bytes"] ?? 0)),
        ],
        [
            "label" => t("resources.server_used"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["filesystem_used_bytes"] ?? 0)),
        ],
        [
            "label" => t("resources.server_free"), "value" =>
            ResourceUsageManager::formatBytes((int) ($snapshot["filesystem_free_bytes"] ?? 0)),
        ],
    ];

    return [
        "has_snapshot" => $hassnapshot,
        "has_active_job" => $activejob !== null,
        "active_job_id" => $activejob["id"] ?? "",
        "active_job_url" => !empty($activejob["id"]) ? "/jobs.php?job=" . rawurlencode($activejob["id"]) : "",
        "is_stale" => $hassnapshot && ResourceUsageManager::isStale($snapshot),
        "collected_at" => $collectedat,
        "duration" => number_format(
            (float) ($snapshot["duration_seconds"] ?? 0), 2, t("decimal_separator"), t("thousands_separator")
        ),
        "items" => $items,
        "server_percent" => (string) ($snapshot["filesystem_used_percent"] ?? 0),
        "server_percent_style" => "width: " . min(100, max(0, (float) ($snapshot["filesystem_used_percent"] ?? 0))) . "%",
        "database_tables" => (int) ($snapshot["database_tables"] ?? 0),
        "has_database_error" => !empty($snapshot["database_error"]),
        "database_error" => $snapshot["database_error"] ?? "",
        "csrf_token" => csrf_token(),
    ];
}

/**
 * Builds the queued web server controls.
 *
 * @param string $domain
 * @param array $controls
 * @return array
 */
function details_server_controls(string $domain, array $controls): array {
    $items = [];
    foreach (["cache"] as $key) {
        $control = is_array($controls[$key] ?? null) ? $controls[$key] : [];
        $activejob = null;
        foreach (JobManager::all() as $job) {
            if (($job["type"] ?? "") === "server_control"
                && ($job["domain"] ?? "") === $domain
                && ($job["control"] ?? "") === $key
                && in_array(($job["status"] ?? ""), ["pending", "running"], true)
            ) {
                $activejob = $job;
                break;
            }
        }

        $enabled = ($control["enabled"] ?? null) === true;
        $targetenabled = !$enabled;
        $items[] = [
            "key" => $key,
            "label" => $control["label"] ?? $key,
            "message" => $control["message"] ?? "",
            "path" => $control["path"] ?? "",
            "has_path" => !empty($control["path"]),
            "supported" => !empty($control["supported"]),
            "has_active_job" => $activejob !== null,
            "active_job_url" => !empty($activejob["id"]) ? "/jobs.php?job=" . rawurlencode($activejob["id"]) : "",
            "badge_html" => $activejob !== null
                ? status_badge((string) ($activejob["status"] ?? "pending"))
                : status_badge((string) ($control["status"] ?? "muted"), (string) ($control["status_label"] ?? "-")),
            "enabled_value" => $targetenabled ? "1" : "0",
            "button_label" => t($targetenabled ? "actions.enable" : "actions.disable"),
            "button_class" => $targetenabled ? "button" : "button warning",
            "csrf_token" => csrf_token(),
        ];
    }
    return $items;
}

/**
 * Checks if a server control job should refresh this page.
 *
 * @param string $domain
 * @return bool
 */
function details_has_active_server_control(string $domain): bool {
    foreach (JobManager::all() as $job) {
        if (($job["type"] ?? "") === "server_control"
            && ($job["domain"] ?? "") === $domain
            && in_array(($job["status"] ?? ""), ["pending", "running"], true)
        ) {
            return true;
        }
    }
    return false;
}

/**
 * Function details_info_row
 *
 * @param string $label
 * @param mixed $value
 * @param bool $code
 * @return array
 */
function details_info_row(string $label, mixed $value, bool $code = true): array {
    return [
        "label" => $label,
        "value" => details_value($value),
        "is_code" => $code,
        "is_plain" => !$code,
    ];
}

/**
 * Function details_diagnostic_row
 *
 * @param string $label
 * @param array $item
 * @return array
 */
function details_diagnostic_row(string $label, array $item): array {
    $resolvedips = !empty($item["resolved_ips"]) && is_array($item["resolved_ips"])
        ? implode(", ", $item["resolved_ips"])
        : "";
    $serverips = !empty($item["server_ips"]) && is_array($item["server_ips"])
        ? implode(", ", $item["server_ips"])
        : "";

    return [
        "label" => $label,
        "badge_html" => status_badge(($item["status"] ?? "muted"), ($item["label"] ?? "-")),
        "message" => $item["message"] ?? "",
        "has_path" => !empty($item["path"]),
        "path" => $item["path"] ?? "",
        "has_resolved_ips" => $resolvedips != "",
        "resolved_ips" => $resolvedips,
        "has_server_ips" => $serverips != "",
        "server_ips" => $serverips,
        "has_valid_to" => !empty($item["valid_to"]),
        "valid_to" => $item["valid_to"] ?? "",
        "has_issuer" => !empty($item["issuer"]),
        "issuer" => $item["issuer"] ?? "",
    ];
}

/**
 * Function details_feature_flags
 *
 * @param array $featureflags
 * @return array
 * @throws \Random\RandomException
 */
function details_feature_flags(array $featureflags): array {
    $items = [];

    foreach ($featureflags as $flag => $item) {
        if (!is_array($item)) {
            continue;
        }

        $enabled = !empty($item["enabled"]);
        $needsvalue = !empty($item["value_type"]);
        $buttonclass = $enabled ? "button secondary" : (!empty($item["dangerous"]) ? "button warning" : "button");
        $buttonlabel = $enabled ? t("actions.disable") : t("actions.enable");

        if ($needsvalue && $enabled) {
            $buttonclass = "button";
            $buttonlabel = t("actions.save");
        }

        $items[] = [
            "flag" => $flag,
            "label" => $item["label"] ?? $flag,
            "control_class" => "flag-control {$item["class"]}",
            "status_badge_html" => status_badge(($item["status"] ?? "muted"), ($item["status_label"] ?? "-")),
            "description" => $item["description"] ?? "",
            "has_path" => !empty($item["path"]),
            "path" => $item["path"] ?? "",
            "needs_value" => $needsvalue,
            "no_value" => !$needsvalue,
            "value" => $item["value"] ?? "",
            "enabled_value" => $enabled ? "0" : "1",
            "show_disable_when_value_enabled" => $needsvalue && $enabled,
            "button_class" => $buttonclass,
            "button_label" => $buttonlabel,
            "csrf_token" => csrf_token(),
        ];
    }

    return $items;
}

/**
 * Function details_value
 *
 * @param mixed $value
 * @return string
 */
function details_value(mixed $value): string {
    if (is_bool($value)) {
        return $value ? t("details.yes") : t("details.no");
    }
    if ($value == null || $value == "") {
        return "-";
    }
    return $value;
}

/**
 * Function details_format_count
 *
 * @param mixed $value
 * @return string
 */
function details_format_count(mixed $value): string {
    return number_format($value, 0, t("decimal_separator"), t("thousands_separator"));
}

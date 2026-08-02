<?php

namespace app;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Builds the consolidated dashboard from every managed Moodle database.
 */
class DashboardManager {
    private const int ONLINE_WINDOW = 300;
    private const int ACTIVITY_DAYS = 30;
    private const int CACHE_SECONDS = 45;

    /**
     * Collects dashboard data. Short session caching keeps the home responsive
     * while the explicit refresh action still provides near-real-time values.
     *
     * @param array $sites
     * @param bool $refresh
     * @return array
     */
    public static function collect(array $sites, bool $refresh = false): array {
        $cache = $_SESSION["dashboard_overview"] ?? [];
        if (!$refresh
            && is_array($cache)
            && (int) ($cache["created_at"] ?? 0) > time() - self::CACHE_SECONDS
            && is_array($cache["data"] ?? null)
        ) {
            return $cache["data"];
        }

        $activity = self::emptyActivity();
        $locationbuckets = [];
        $siterows = [];
        $totalusers = 0;
        $activeusers = 0;
        $onlineusers = 0;
        $onlineenvironments = 0;
        $availableenvironments = 0;
        $maintenanceenvironments = 0;
        $databaseerrors = 0;
        $missingkoperebi = 0;
        $delayedcron = 0;
        $certificatewarning = null;

        foreach ($sites as $site) {
            $metrics = self::siteMetrics($site);
            $domain = (string) ($site["domain"] ?? "");
            $installationcomplete = !empty($site["installation_complete"]);
            $maintenance = is_file(rtrim((string) ($site["base_dir"] ?? ""), "/") . "/maintenance.enable");
            $enabled = !empty($site["moodle_enabled"]);

            if ($installationcomplete && $enabled && !$maintenance && $metrics["connected"]) {
                $availableenvironments++;
            }
            if ($maintenance) {
                $maintenanceenvironments++;
            }
            if (!$metrics["connected"] && $installationcomplete) {
                $databaseerrors++;
            }
            if ($metrics["connected"] && !$metrics["has_kopere_bi"]) {
                $missingkoperebi++;
            }

            $totalusers += $metrics["users"];
            $activeusers += $metrics["active_users"];
            $onlineusers += $metrics["online_users"];
            if ($metrics["online_users"] > 0) {
                $onlineenvironments++;
            }

            foreach ($metrics["activity"] as $day => $values) {
                if (!isset($activity[$day])) {
                    continue;
                }
                $activity[$day]["active"] += (int) ($values["active"] ?? 0);
                $activity[$day]["accesses"] += (int) ($values["accesses"] ?? 0);
            }

            foreach ($metrics["locations"] as $location) {
                $key = implode("|", [
                    strtolower(trim((string) ($location["city"] ?? ""))),
                    strtolower(trim((string) ($location["country"] ?? ""))),
                    number_format((float) ($location["latitude"] ?? 0), 2, ".", ""),
                    number_format((float) ($location["longitude"] ?? 0), 2, ".", ""),
                ]);
                if (!isset($locationbuckets[$key])) {
                    $locationbuckets[$key] = $location;
                    $locationbuckets[$key]["count"] = 0;
                }
                $locationbuckets[$key]["count"] += (int) ($location["count"] ?? 0);
            }

            $resource = $installationcomplete ? ResourceUsageManager::snapshot($domain) : [];
            $health = self::siteHealth($site, $metrics, $maintenance);
            $siterows[] = [
                "domain" => $domain,
                "name" => $metrics["site_name"] !== "" ? $metrics["site_name"] : $domain,
                "version" => (string) ($site["moodle_release"] ?? $site["moodle_branch"] ?? "-"),
                "online" => self::formatNumber($metrics["online_users"]),
                "active" => self::formatNumber($metrics["active_users"]),
                "users" => self::formatNumber($metrics["users"]),
                "courses" => self::formatNumber($metrics["courses"]),
                "storage" => !empty($resource)
                    ? ResourceUsageManager::formatBytes((int) ($resource["total_bytes"] ?? 0))
                    : t("dashboard.unavailable"),
                "health_status" => $health["status"],
                "health_label" => $health["label"],
                "details_url" => $installationcomplete
                    ? "/details.php?domain=" . rawurlencode($domain)
                    : ($site["installation_job_url"] ?? "/jobs.php"),
            ];

            $sitecertificate = self::certificateWarning($domain);
            if ($sitecertificate !== null
                && ($certificatewarning === null || $sitecertificate["days"] < $certificatewarning["days"])
            ) {
                $certificatewarning = $sitecertificate;
            }
        }

        usort($siterows, static function(array $a, array $b): int {
            $priority = ["danger" => 0, "warning" => 1, "running" => 2, "ok" => 3];
            $statuscomparison = ($priority[$a["health_status"]] ?? 4) <=> ($priority[$b["health_status"]] ?? 4);
            return $statuscomparison !== 0 ? $statuscomparison : strcasecmp($a["name"], $b["name"]);
        });

        $jobs = JobManager::all();
        $activejobs = count(array_filter($jobs, static function(array $job): bool {
            return in_array(($job["status"] ?? ""), ["pending", "running"], true);
        }));
        $failedjobs = count(array_filter($jobs, static function(array $job): bool {
            return in_array(($job["status"] ?? ""), ["failed", "error"], true);
        }));

        $alerts = self::alerts([
            "database_errors" => $databaseerrors,
            "missing_kopere_bi" => $missingkoperebi,
            "delayed_cron" => $delayedcron,
            "maintenance" => $maintenanceenvironments,
            "failed_jobs" => $failedjobs,
            "certificate" => $certificatewarning,
        ]);

        $data = [
            "updated_at" => date("H:i"),
            "totals" => [
                "sites" => self::formatNumber(count($sites)),
                "available_sites" => self::formatNumber($availableenvironments),
                "maintenance_sites" => self::formatNumber($maintenanceenvironments),
                "users" => self::formatNumber($totalusers),
                "active_users" => self::formatNumber($activeusers),
                "active_rate" => $totalusers > 0 ? self::formatPercentage(($activeusers / $totalusers) * 100) : "0%",
                "online_users" => self::formatNumber($onlineusers),
                "online_environments" => self::formatNumber($onlineenvironments),
            ],
            "activity" => self::chart($activity),
            "locations" => self::locations($locationbuckets, $onlineusers),
            "alerts" => $alerts,
            "has_alerts" => !empty($alerts),
            "sites" => $siterows,
            "has_sites" => !empty($siterows),
            "site_count" => count($siterows),
        ];

        $_SESSION["dashboard_overview"] = [
            "created_at" => time(),
            "data" => $data,
        ];
        return $data;
    }

    /**
     * Reads the metrics required from a single Moodle database.
     *
     * @param array $site
     * @return array
     */
    private static function siteMetrics(array $site): array {
        $result = [
            "connected" => false,
            "has_kopere_bi" => false,
            "site_name" => "",
            "users" => 0,
            "active_users" => 0,
            "online_users" => 0,
            "courses" => 0,
            "locations" => [],
            "activity" => [],
        ];

        $config = self::readDatabaseConfig((string) ($site["config_file"] ?? ""));
        if (($config["dbname"] ?? "") === "") {
            return $result;
        }

        try {
            $pdo = self::connect($config);
            $prefix = self::safePrefix((string) ($config["prefix"] ?? "mdl_"));
            $dbname = (string) $config["dbname"];
            $usertable = self::table($prefix, "user");
            $coursetable = self::table($prefix, "course");
            $configtable = self::table($prefix, "config");

            $result["users"] = (int) $pdo->query("SELECT COUNT(*) FROM {$usertable} WHERE deleted = 0 AND id > 1")->fetchColumn();

            $sql = "SELECT COUNT(*) FROM {$usertable}
                     WHERE deleted = 0 AND suspended = 0 AND id > 1 AND lastaccess >= :threshold";
            $activestatement = $pdo->prepare($sql);
            $activestatement->bindValue("threshold", time() - 30 * 86400, PDO::PARAM_INT);
            $activestatement->execute();
            $result["active_users"] = (int) $activestatement->fetchColumn();
            $result["courses"] = (int) $pdo->query("SELECT COUNT(*) FROM {$coursetable} WHERE id > 1")->fetchColumn();
            $result["site_name"] = trim((string) $pdo->query("SELECT fullname FROM {$coursetable} WHERE id = 1")->fetchColumn());

            $onlinename = $prefix . "local_kopere_bi_online";
            $result["has_kopere_bi"] = self::tableExists($pdo, $dbname, $onlinename);
            if ($result["has_kopere_bi"]) {
                $onlinetable = self::table($prefix, "local_kopere_bi_online");
                $sql = "SELECT userid, city_name, country_name, country_code, latitude, longitude, currenttime
                          FROM {$onlinetable}
                         WHERE currenttime > :threshold AND userid > 1
                      ORDER BY currenttime DESC";
                $statement = $pdo->prepare($sql);
                $statement->bindValue("threshold", time() - self::ONLINE_WINDOW, PDO::PARAM_INT);
                $statement->execute();
                $users = [];
                $locations = [];
                foreach ($statement->fetchAll() as $row) {
                    $userid = (int) ($row["userid"] ?? 0);
                    if ($userid < 2 || isset($users[$userid])) {
                        continue;
                    }
                    $users[$userid] = true;
                    $latitude = filter_var($row["latitude"] ?? null, FILTER_VALIDATE_FLOAT);
                    $longitude = filter_var($row["longitude"] ?? null, FILTER_VALIDATE_FLOAT);
                    $locationkey = implode("|", [
                        strtolower(trim((string) ($row["city_name"] ?? ""))),
                        strtolower(trim((string) ($row["country_name"] ?? ""))),
                        $latitude !== false ? number_format((float) $latitude, 2, ".", "") : "",
                        $longitude !== false ? number_format((float) $longitude, 2, ".", "") : "",
                    ]);
                    if (!isset($locations[$locationkey])) {
                        $locations[$locationkey] = [
                            "city" => trim((string) ($row["city_name"] ?? "")),
                            "country" => trim((string) ($row["country_name"] ?? "")),
                            "country_code" => strtoupper(trim((string) ($row["country_code"] ?? ""))),
                            "latitude" => $latitude !== false ? (float) $latitude : null,
                            "longitude" => $longitude !== false ? (float) $longitude : null,
                            "count" => 0,
                        ];
                    }
                    $locations[$locationkey]["count"]++;
                }
                $result["online_users"] = count($users);
                $result["locations"] = array_values($locations);
            }

            $trackingname = $prefix . "local_kopere_bi_track_log";
            if (self::tableExists($pdo, $dbname, $trackingname)) {
                $trackingtable = self::table($prefix, "local_kopere_bi_track_log");
                $sql = "SELECT DATE(FROM_UNIXTIME(timepoint)) AS activity_day,
                               COUNT(DISTINCT userid) AS active_users,
                               COALESCE(SUM(visits), 0) AS accesses
                          FROM {$trackingtable}
                         WHERE timepoint >= :threshold
                      GROUP BY DATE(FROM_UNIXTIME(timepoint))";
                $statement = $pdo->prepare($sql);
                $statement->bindValue("threshold", strtotime("-29 days midnight"), PDO::PARAM_INT);
                $statement->execute();
                foreach ($statement->fetchAll() as $row) {
                    $day = (string) ($row["activity_day"] ?? "");
                    if ($day !== "") {
                        $result["activity"][$day] = [
                            "active" => (int) ($row["active_users"] ?? 0),
                            "accesses" => (int) ($row["accesses"] ?? 0),
                        ];
                    }
                }
            }

            $result["connected"] = true;
        } catch (Throwable) {
            return $result;
        }

        return $result;
    }

    /**
     * @param array $config
     * @return PDO
     */
    private static function connect(array $config): PDO {
        $host = (string) (app_config("mysql_admin_host") ?: ($config["dbhost"] ?? "localhost"));
        $port = (int) (app_config("mysql_admin_port") ?: ($config["dbport"] ?? 3306));
        $user = (string) (app_config("mysql_admin_user") ?: ($config["dbuser"] ?? ""));
        $adminpass = app_config("mysql_admin_pass");
        $pass = $adminpass !== null ? (string) $adminpass : (string) ($config["dbpass"] ?? "");
        $dbname = (string) $config["dbname"];

        return new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @param string $configfile
     * @return array
     */
    private static function readDatabaseConfig(string $configfile): array {
        if ($configfile === "" || !is_readable($configfile)) {
            return [];
        }
        $content = (string) file_get_contents($configfile);
        $config = [];
        foreach (["dbhost", "dbname", "dbuser", "dbpass", "prefix", "dbtype"] as $key) {
            if (preg_match('/\$CFG->' . preg_quote($key, "/") . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
                $config[$key] = stripcslashes($matches[2]);
            }
        }
        if (preg_match('/[\'\"]dbport[\'\"]\s*=>\s*([\'\"])(\d+)\1/s', $content, $matches)) {
            $config["dbport"] = (int) $matches[2];
        }
        return $config;
    }

    /**
     * @param string $prefix
     * @return string
     */
    private static function safePrefix(string $prefix): string {
        return preg_match('/^[A-Za-z0-9_]+$/', $prefix) ? $prefix : "mdl_";
    }

    /**
     * @param string $prefix
     * @param string $name
     * @return string
     */
    private static function table(string $prefix, string $name): string {
        return "`" . self::safePrefix($prefix) . $name . "`";
    }

    /**
     * @param PDO $pdo
     * @param string $dbname
     * @param string $table
     * @return bool
     */
    private static function tableExists(PDO $pdo, string $dbname, string $table): bool {
        $sql = "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table LIMIT 1";
        $statement = $pdo->prepare($sql);
        $statement->execute(["database" => $dbname, "table" => $table]);
        return (bool) $statement->fetchColumn();
    }

    /**
     * @return array
     */
    private static function emptyActivity(): array {
        $days = [];
        $today = new DateTimeImmutable("today", new DateTimeZone("America/Sao_Paulo"));
        for ($index = self::ACTIVITY_DAYS - 1; $index >= 0; $index--) {
            $day = $today->modify("-{$index} days");
            $days[$day->format("Y-m-d")] = ["active" => 0, "accesses" => 0];
        }
        return $days;
    }

    /**
     * @param array $activity
     * @return array
     */
    private static function chart(array $activity): array {
        $active = array_map(static fn(array $day): int => (int) $day["active"], $activity);
        $accesses = array_map(static fn(array $day): int => (int) $day["accesses"], $activity);
        $maxactive = max(1, ...array_values($active));
        $maxaccesses = max(1, ...array_values($accesses));
        $count = max(1, count($activity) - 1);
        $activepoints = [];
        $accesspoints = [];
        $labels = [];
        $index = 0;

        foreach ($activity as $day => $values) {
            $x = 38 + ($index / $count) * 674;
            $activey = 218 - (((int) $values["active"] / $maxactive) * 184);
            $accessy = 218 - (((int) $values["accesses"] / $maxaccesses) * 184);
            $activepoints[] = round($x, 1) . "," . round($activey, 1);
            $accesspoints[] = round($x, 1) . "," . round($accessy, 1);
            if ($index % 5 === 0 || $index === count($activity) - 1) {
                $labels[] = [
                    "x" => round($x, 1),
                    "label" => date("d/m", strtotime($day)),
                ];
            }
            $index++;
        }

        return [
            "has_data" => array_sum($active) > 0 || array_sum($accesses) > 0,
            "active_points" => implode(" ", $activepoints),
            "access_points" => implode(" ", $accesspoints),
            "labels" => $labels,
            "active_max" => self::formatCompactNumber($maxactive),
            "access_max" => self::formatCompactNumber($maxaccesses),
        ];
    }

    /**
     * @param array $buckets
     * @param int $onlineusers
     * @return array
     */
    private static function locations(array $buckets, int $onlineusers): array {
        $locations = array_values($buckets);
        usort($locations, static fn(array $a, array $b): int => ((int) $b["count"]) <=> ((int) $a["count"]));
        $items = [];
        $points = [];
        $mapped = 0;

        foreach ($locations as $location) {
            $city = trim((string) ($location["city"] ?? ""));
            $country = trim((string) ($location["country"] ?? ""));
            $count = (int) ($location["count"] ?? 0);
            $label = implode(", ", array_filter([$city, $country]));
            if ($label === "") {
                $label = t("dashboard.unknown_location");
            }
            $items[] = [
                "label" => $label,
                "count" => self::formatNumber($count),
                "percentage" => $onlineusers > 0 ? self::formatPercentage(($count / $onlineusers) * 100) : "0%",
            ];

            $latitude = $location["latitude"] ?? null;
            $longitude = $location["longitude"] ?? null;
            $inbrazil = is_numeric($latitude) && is_numeric($longitude)
                && (float) $latitude >= -34.5 && (float) $latitude <= 6.0
                && (float) $longitude >= -74.5 && (float) $longitude <= -34.0;
            if (!$inbrazil) {
                continue;
            }
            $x = 18 + (((float) $longitude + 74.5) / 40.5) * 224;
            $y = 15 + ((6.0 - (float) $latitude) / 40.5) * 270;
            $points[] = [
                "x" => round($x, 1),
                "y" => round($y, 1),
                "radius" => min(18, round(4 + sqrt(max(1, $count)) * 2, 1)),
                "label" => $label . ": " . self::formatNumber($count),
            ];
            $mapped += $count;
        }

        return [
            "has_data" => !empty($locations),
            "has_map_points" => !empty($points),
            "points" => $points,
            "items" => array_slice($items, 0, 5),
            "mapped" => self::formatNumber($mapped),
            "unmapped" => self::formatNumber(max(0, $onlineusers - $mapped)),
            "online" => self::formatNumber($onlineusers),
        ];
    }

    /**
     * @param array $site
     * @param array $metrics
     * @param bool $maintenance
     * @return array
     */
    private static function siteHealth(array $site, array $metrics, bool $maintenance): array {
        if (empty($site["installation_complete"])) {
            return ["status" => "running", "label" => t("dashboard.installing")];
        }
        if ($maintenance) {
            return ["status" => "running", "label" => t("status.maintenance")];
        }
        if (empty($site["moodle_enabled"]) || !$metrics["connected"]) {
            return ["status" => "danger", "label" => t("dashboard.unavailable")];
        }
        return ["status" => "ok", "label" => t("dashboard.healthy")];
    }

    /**
     * @param array $summary
     * @return array
     */
    private static function alerts(array $summary): array {
        $alerts = [];
        $add = static function(string $class, string $icon, string $title, string $description, string $url = "", string $action = "") use (&$alerts): void {
            $alerts[] = [
                "class" => $class,
                "icon" => $icon,
                "title" => $title,
                "description" => $description,
                "url" => $url,
                "action" => $action,
                "has_action" => $url !== "" && $action !== "",
            ];
        };

        if ($summary["database_errors"] > 0) {
            $add("danger", "database", t("dashboard.database_alert", ["count" => $summary["database_errors"]]),
                t("dashboard.database_alert_description"), "/moodle.php", t("dashboard.view_environments"));
        }
        if ($summary["delayed_cron"] > 0) {
            $add("warning", "clock", t("dashboard.cron_alert", ["count" => $summary["delayed_cron"]]),
                t("dashboard.cron_alert_description"), "/moodle.php", t("dashboard.check_now"));
        }
        if ($summary["missing_kopere_bi"] > 0) {
            $add("warning", "activity", t("dashboard.kopere_alert", ["count" => $summary["missing_kopere_bi"]]),
                t("dashboard.kopere_alert_description"), "/moodle.php", t("dashboard.view_environments"));
        }
        if ($summary["failed_jobs"] > 0) {
            $add("danger", "jobs", t("dashboard.jobs_alert", ["count" => $summary["failed_jobs"]]),
                t("dashboard.jobs_alert_description"), "/jobs.php", t("dashboard.open_queue"));
        }
        if ($summary["maintenance"] > 0) {
            $add("info", "settings", t("dashboard.maintenance_alert", ["count" => $summary["maintenance"]]),
                t("dashboard.maintenance_alert_description"), "/moodle.php", t("dashboard.view_environments"));
        }
        if (is_array($summary["certificate"])) {
            $certificatekey = $summary["certificate"]["days"] < 0
                ? "dashboard.ssl_expired_alert"
                : "dashboard.ssl_alert";
            $add("warning", "shield", t($certificatekey, ["days" => $summary["certificate"]["days"]]),
                $summary["certificate"]["domain"], "/moodle.php", t("dashboard.view_environments"));
        }
        if (AppUpdater::hasCachedUpdate()) {
            $add("info", "update", t("dashboard.update_alert"), t("dashboard.update_alert_description"),
                "/update.php", t("dashboard.open_update"));
        }
        if (!$alerts) {
            $add("ok", "check", t("dashboard.no_alerts"), t("dashboard.no_alerts_description"));
        }

        return array_slice($alerts, 0, 5);
    }

    /**
     * Reads a local Let's Encrypt certificate without making a remote request.
     *
     * @param string $domain
     * @return array|null
     */
    private static function certificateWarning(string $domain): ?array {
        if ($domain === "" || !function_exists("openssl_x509_parse")) {
            return null;
        }
        foreach (["cert.pem", "fullchain.pem"] as $filename) {
            $file = "/etc/letsencrypt/live/{$domain}/{$filename}";
            if (!is_readable($file)) {
                continue;
            }
            $certificate = openssl_x509_parse((string) file_get_contents($file));
            $validto = (int) ($certificate["validTo_time_t"] ?? 0);
            if ($validto <= 0) {
                return null;
            }
            $days = (int) floor(($validto - time()) / 86400);
            return $days < 15 ? ["domain" => $domain, "days" => $days] : null;
        }
        return null;
    }

    /**
     * @param int $timestamp
     * @return string
     */
    private static function relativeTime(int $timestamp): string {
        if ($timestamp <= 0) {
            return t("dashboard.never");
        }
        $seconds = max(0, time() - $timestamp);
        if ($seconds < 90) {
            return t("dashboard.now");
        }
        if ($seconds < 3600) {
            return t("dashboard.minutes_ago", ["count" => (int) floor($seconds / 60)]);
        }
        if ($seconds < 86400) {
            return t("dashboard.hours_ago", ["count" => (int) floor($seconds / 3600)]);
        }
        return t("dashboard.days_ago", ["count" => (int) floor($seconds / 86400)]);
    }

    /**
     * @param int $value
     * @return string
     */
    private static function formatNumber(int $value): string {
        return number_format($value, 0, t("decimal_separator"), t("thousands_separator"));
    }

    /**
     * @param float $value
     * @return string
     */
    private static function formatPercentage(float $value): string {
        return number_format($value, 0, t("decimal_separator"), t("thousands_separator")) . "%";
    }

    /**
     * @param int $value
     * @return string
     */
    private static function formatCompactNumber(int $value): string {
        if ($value >= 1000000) {
            return number_format($value / 1000000, 1, t("decimal_separator"), "") . " mi";
        }
        if ($value >= 1000) {
            return number_format($value / 1000, 1, t("decimal_separator"), "") . " mil";
        }
        return self::formatNumber($value);
    }
}

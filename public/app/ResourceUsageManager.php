<?php

// Collects storage usage outside web requests and stores per-site snapshots.
namespace app;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Class ResourceUsageManager
 */
class ResourceUsageManager {
    private const int COLLECTION_INTERVAL = 21600;
    private const int SCHEDULE_SCAN_INTERVAL = 900;

    /**
     * Returns the last collected snapshot for a domain.
     *
     * @param string $domain
     * @return array
     */
    public static function snapshot(string $domain): array {
        $snapshot = JsonStorage::read(self::snapshotPath($domain));
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * Queues stale or missing resource snapshots at most once every 15 minutes.
     *
     * @return void
     * @throws \Random\RandomException
     */
    public static function scheduleDueCollections(): void {
        $statefile = app_config_path("/data/runtime/resource-usage-schedule.json");
        $state = JsonStorage::read($statefile);
        $lastscan = strtotime($state["last_scan_at"]);
        if ($lastscan !== false && (time() - $lastscan) < self::SCHEDULE_SCAN_INTERVAL) {
            return;
        }

        JsonStorage::write($statefile, ["last_scan_at" => now_iso()]);

        foreach (SiteManager::all() as $site) {
            $domain = $site["domain"];
            if ($domain === "" || !self::isStale(self::snapshot($domain))) {
                continue;
            }
            JobManager::createResourceUsageJob($site, "system");
        }
    }

    /**
     * Collects storage and filesystem usage for a queued job.
     *
     * @param array $job
     * @return array
     * @throws \Random\RandomException
     */
    public static function collect(array $job): array {
        $domain = $job["domain"];
        $site = SiteManager::get($domain);
        if ($site === null) {
            throw new RuntimeException("Site not found for resource collection: {$domain}");
        }

        $startedat = microtime(true);
        $codebytes = self::directoryBytes($site["moodle_dir"]);
        $databytes = self::directoryBytes($site["dataroot"]);
        $database = self::databaseUsage($site["config_file"]);
        $filesystem = self::filesystemUsage($site["base_dir"]);

        $snapshot = [
            "domain" => $domain,
            "status" => "ready",
            "collected_at" => now_iso(),
            "duration_seconds" => round(microtime(true) - $startedat, 3),
            "moodle_code_bytes" => $codebytes,
            "moodledata_bytes" => $databytes,
            "database_bytes" => $database["bytes"],
            "database_tables" => $database["tables"],
            "database_error" => $database["error"],
            "total_bytes" => $codebytes + $databytes + $database["bytes"],
            "filesystem_total_bytes" => $filesystem["total"],
            "filesystem_free_bytes" => $filesystem["free"],
            "filesystem_used_bytes" => $filesystem["used"],
            "filesystem_used_percent" => $filesystem["percent"],
        ];

        $file = self::snapshotPath($domain);
        JsonStorage::write($file, $snapshot);
        self::fixSnapshotPermissions($file);
        return $snapshot;
    }

    /**
     * Formats a byte amount for the interface.
     *
     * @param int $bytes
     * @return string
     */
    public static function formatBytes(int $bytes): string {
        $bytes = max(0, $bytes);
        $units = ["B", "KB", "MB", "GB", "TB", "PB"];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        $decimals = $index === 0 ? 0 : ($value >= 100 ? 0 : 1);
        return number_format($value, $decimals, t("decimal_separator"), t("thousands_separator")) . " " . $units[$index];
    }

    /**
     * Checks whether a snapshot needs to be collected again.
     *
     * @param array $snapshot
     * @return bool
     */
    public static function isStale(array $snapshot): bool {
        $collectedat = strtotime($snapshot["collected_at"]);
        return $collectedat === false || (time() - $collectedat) >= self::COLLECTION_INTERVAL;
    }

    /**
     * Returns the snapshot storage path.
     *
     * @param string $domain
     * @return string
     */
    private static function snapshotPath(string $domain): string {
        $prefix = preg_replace('/[^a-z0-9.-]+/', "-", strtolower($domain)) ?: "site";
        $prefix = trim(substr($prefix, 0, 80), "-.");
        return app_config_path("/data/resources/{$prefix}-" . substr(sha1($domain), 0, 12) . ".json");
    }

    /**
     * Measures a directory without running the work in a web request.
     *
     * @param string $path
     * @return int
     */
    private static function directoryBytes(string $path): int {
        if ($path === "" || !is_dir($path)) {
            return 0;
        }

        foreach (["/usr/bin/du", "/bin/du"] as $du) {
            if (!is_executable($du)) {
                continue;
            }
            $output = [];
            $exitcode = 0;
            exec(escapeshellarg($du) . " -sb -- " . escapeshellarg($path) . " 2>/dev/null", $output, $exitcode);
            if ($exitcode === 0 && preg_match('/^(\d+)/', (string) ($output[0] ?? ""), $matches)) {
                return (int) $matches[1];
            }
        }

        $bytes = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if ($item->isFile() && !$item->isLink()) {
                    $bytes += $item->getSize();
                }
            }
        } catch (Throwable) {
            return $bytes;
        }
        return $bytes;
    }

    /**
     * Reads database size and table count from information_schema.
     *
     * @param string $configfile
     * @return array{bytes: int, tables: int, error: string}
     */
    private static function databaseUsage(string $configfile): array {
        $moodleconfig = self::readMoodleConfig($configfile);
        $dbname = $moodleconfig["dbname"];
        if ($dbname === "") {
            return ["bytes" => 0, "tables" => 0, "error" => "Database name was not found in config.php."];
        }

        $host = (string) (app_config("mysql_admin_host") ?: ($moodleconfig["dbhost"] ?? "localhost"));
        $port = (int) (app_config("mysql_admin_port") ?: 3306);
        $user = (string) (app_config("mysql_admin_user") ?: ($moodleconfig["dbuser"] ?? ""));
        $pass = app_config("mysql_admin_pass");
        if ($pass === null) {
            $pass = $moodleconfig["dbpass"] ?? "";
        }

        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, (string) $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $statement = $pdo->prepare(
                "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes, COUNT(*) AS tables
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = :dbname"
            );
            $statement->execute(["dbname" => $dbname]);
            $row = $statement->fetch() ?: [];
            return [
                "bytes" => (int) ($row["bytes"] ?? 0),
                "tables" => (int) ($row["tables"] ?? 0),
                "error" => "",
            ];
        } catch (Throwable $e) {
            return ["bytes" => 0, "tables" => 0, "error" => $e->getMessage()];
        }
    }

    /**
     * Reads only the database values required by the collector.
     *
     * @param string $configfile
     * @return array
     */
    private static function readMoodleConfig(string $configfile): array {
        if ($configfile === "" || !is_readable($configfile)) {
            return [];
        }
        $content = (string) file_get_contents($configfile);
        $config = [];
        foreach (["dbhost", "dbname", "dbuser", "dbpass"] as $key) {
            if (preg_match('/\$CFG->' . preg_quote($key, "/") . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
                $config[$key] = stripcslashes($matches[2]);
            }
        }
        return $config;
    }

    /**
     * Returns usage of the filesystem containing the site.
     *
     * @param string $path
     * @return array{total: int, free: int, used: int, percent: float}
     */
    private static function filesystemUsage(string $path): array {
        $total = $path !== "" ? @disk_total_space($path) : false;
        $free = $path !== "" ? @disk_free_space($path) : false;
        $total = $total === false ? 0 : (int) $total;
        $free = $free === false ? 0 : (int) $free;
        $used = max(0, $total - $free);
        return [
            "total" => $total,
            "free" => $free,
            "used" => $used,
            "percent" => $total > 0 ? round(($used / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Makes root-generated snapshots readable by the panel process.
     *
     * @param string $file
     * @return void
     */
    private static function fixSnapshotPermissions(string $file): void {
        $dir = dirname($file);
        $group = (string) (app_config("apache_group") ?: "");
        if ($group !== "") {
            @chgrp($dir, $group);
            @chgrp($file, $group);
        }
        @chmod($dir, 0750);
        @chmod($file, 0640);
    }
}

<?php

// Provides safe, bounded access to logs associated with one installed site.
namespace app;

/**
 * Class LogManager
 */
class LogManager {
    private const int MAX_BYTES = 262144;
    private const int MAX_LINES = 500;

    /**
     * Lists log sources that belong to one site.
     *
     * @param array $site
     * @return array
     */
    public static function sources(array $site): array {
        $domain = (string) ($site["domain"] ?? "");
        $base = rtrim((string) ($site["base_dir"] ?? ""), "/");
        $sources = [];

        $sitepaths = [
            ["label" => I18n::get("logs.nginx_access"), "path" => "{$base}/logs/nginx-access.log", "kind" => "access"],
            ["label" => I18n::get("logs.nginx_error"), "path" => "{$base}/logs/nginx-error.log", "kind" => "error"],
            ["label" => I18n::get("logs.apache_access"), "path" => "{$base}/logs/apache-access.log", "kind" => "access"],
            ["label" => I18n::get("logs.apache_error"), "path" => "{$base}/logs/apache-error.log", "kind" => "error"],
        ];

        if (preg_match('/^[a-z0-9.-]+$/', $domain)) {
            $sitepaths = array_merge($sitepaths, [
                ["label" => I18n::get("logs.nginx_access"), "path" => "/var/log/nginx/{$domain}.access.log", "kind" => "access"],
                ["label" => I18n::get("logs.nginx_error"), "path" => "/var/log/nginx/{$domain}.error.log", "kind" => "error"],
                ["label" => I18n::get("logs.apache_access"), "path" => "/var/log/apache2/{$domain}-access.log", "kind" => "access"],
                ["label" => I18n::get("logs.apache_error"), "path" => "/var/log/apache2/{$domain}-error.log", "kind" => "error"],
                ["label" => I18n::get("logs.apache_access"), "path" => "/var/log/httpd/{$domain}-access.log", "kind" => "access"],
                ["label" => I18n::get("logs.apache_error"), "path" => "/var/log/httpd/{$domain}-error.log", "kind" => "error"],
            ]);
        }

        foreach ($sitepaths as $item) {
            if (!is_file($item["path"])) {
                continue;
            }
            self::appendSource($sources, $item["label"], $item["path"], $item["kind"]);
        }

        foreach (JobManager::all() as $job) {
            if (($job["domain"] ?? "") !== $domain || empty($job["log_file"])) {
                continue;
            }
            $path = (string) $job["log_file"];
            if (!self::isPanelLog($path) || !is_file($path)) {
                continue;
            }
            $type = str_replace("_", " ", (string) ($job["type"] ?? "job"));
            $created = self::formatDate((string) ($job["created_at"] ?? ""));
            $label = trim(I18n::get("logs.job_log") . " - {$type} {$created}");
            self::appendSource($sources, $label, $path, "job");
        }

        usort($sources, static function(array $a, array $b): int {
            return ($b["modified_at_timestamp"] ?? 0) <=> ($a["modified_at_timestamp"] ?? 0);
        });
        return $sources;
    }

    /**
     * Returns one source by its opaque identifier.
     *
     * @param array $site
     * @param string $sourceid
     * @return array|null
     */
    public static function source(array $site, string $sourceid): ?array {
        foreach (self::sources($site) as $source) {
            if (($source["id"] ?? "") === $sourceid) {
                return $source;
            }
        }
        return null;
    }

    /**
     * Reads only the tail of a log file and optionally filters its lines.
     *
     * @param array $source
     * @param string $query
     * @return array{content: string, error: string, truncated: bool, line_count: int}
     */
    public static function read(array $source, string $query = ""): array {
        $path = (string) ($source["path"] ?? "");
        if ($path === "" || !is_file($path)) {
            return ["content" => "", "error" => I18n::get("logs.file_not_found"), "truncated" => false, "line_count" => 0];
        }
        if (!is_readable($path)) {
            return ["content" => "", "error" => I18n::get("logs.file_not_readable"), "truncated" => false, "line_count" => 0];
        }

        $size = (int) (@filesize($path) ?: 0);
        $offset = max(0, $size - self::MAX_BYTES);
        $handle = @fopen($path, "rb");
        if ($handle === false) {
            return ["content" => "", "error" => I18n::get("logs.file_not_readable"), "truncated" => false, "line_count" => 0];
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        if ($offset > 0) {
            $newline = strpos($content, "\n");
            $content = $newline === false ? "" : substr($content, $newline + 1);
        }

        $content = str_replace("\0", "", $content);
        $trimmedcontent = rtrim($content);
        $lines = $trimmedcontent === "" ? [] : (preg_split('/\R/', $trimmedcontent) ?: []);
        $query = trim(mb_substr($query, 0, 100));
        if ($query !== "") {
            $lines = array_values(array_filter($lines, static fn(string $line): bool => mb_stripos($line, $query) !== false));
        }

        $truncated = $offset > 0 || count($lines) > self::MAX_LINES;
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
        }

        return [
            "content" => implode("\n", $lines),
            "error" => "",
            "truncated" => $truncated,
            "line_count" => count($lines),
        ];
    }

    /**
     * Appends a source without exposing its path as the selection value.
     *
     * @param array $sources
     * @param string $label
     * @param string $path
     * @param string $kind
     * @return void
     */
    private static function appendSource(array &$sources, string $label, string $path, string $kind): void {
        foreach ($sources as $source) {
            if (($source["path"] ?? "") === $path) {
                return;
            }
        }
        $modified = (int) (@filemtime($path) ?: 0);
        $sources[] = [
            "id" => substr(hash("sha256", $path), 0, 20),
            "label" => $label,
            "path" => $path,
            "kind" => $kind,
            "readable" => is_readable($path),
            "size" => ResourceUsageManager::formatBytes((int) (@filesize($path) ?: 0)),
            "modified_at" => $modified > 0 ? date("d/m/Y H:i:s", $modified) : "-",
            "modified_at_timestamp" => $modified,
        ];
    }

    /**
     * Restricts job logs to the panel data/logs directory.
     *
     * @param string $path
     * @return bool
     */
    private static function isPanelLog(string $path): bool {
        $logroot = realpath(app_config_path("/data/logs"));
        $realpath = realpath($path);
        return $logroot !== false && $realpath !== false
            && ($realpath === $logroot || str_starts_with($realpath, $logroot . DIRECTORY_SEPARATOR));
    }

    /**
     * Formats an ISO date for a source label.
     *
     * @param string $date
     * @return string
     */
    private static function formatDate(string $date): string {
        $timestamp = strtotime($date);
        return $timestamp ? date("d/m/Y H:i", $timestamp) : "";
    }
}

<?php
// Self-update helper for the Moodle Friendly Installation panel.
namespace app;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Class AppUpdater
 */
class AppUpdater {
    private const string GITHUB_API_VERSION = "2026-03-10";

    /**
     * Function state
     *
     * @return array<string, mixed>
     */
    public static function state(): array {
        $state = JsonStorage::read(self::stateFile());
        if (!is_array($state)) {
            $state = [];
        }

        $defaults = [
            "installed_at" => "",
            "updated_at" => "",
            "updated_by" => "",
            "previous_tag" => "",
            "latest_checked_at" => "",
            "latest_tag" => "",
            "latest_published_at" => "",
            "latest_html_url" => "",
            "backup_dir" => "",
            "update_available" => false,
            "update_marked_at" => "",
            "update_requested" => false,
            "update_requested_at" => "",
            "update_requested_by" => "",
            "update_status" => "",
            "update_message" => "",
        ];

        $state = array_replace($defaults, $state);

        $localversion = require __DIR__ . "/version.php";
        $state["installed_tag"] = $localversion["version"];

        return $state;
    }

    /**
     * Function check
     *
     * @return array<string, mixed>
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function check(): array {
        $state = self::state();
        $latest = self::latestRelease();
        $installedtag = trim($state["installed_tag"]);
        $latesttag = trim($latest["tag_name"]);

        $updateavailable = $latesttag != "" && $latesttag != $installedtag;

        $state["latest_checked_at"] = now_iso();
        $state["latest_tag"] = $latesttag;
        $state["latest_published_at"] = $latest["published_at"];
        $state["latest_html_url"] = $latest["html_url"];
        $state["latest_zipball_url"] = $latest["zipball_url"];
        $state["update_available"] = $updateavailable;

        if ($updateavailable) {
            if (empty($state["update_marked_at"])) {
                $state["update_marked_at"] = now_iso();
            }
            if (empty($state["update_requested"]) && !in_array($state["update_status"], ["requested", "installing"], true)) {
                $state["update_status"] = "available";
                $state["update_message"] = t("updater.update_available");
            }
        } else {
            $state["update_marked_at"] = "";
            $state["update_requested"] = false;
            $state["update_requested_at"] = "";
            $state["update_requested_by"] = "";
            $state["update_status"] = "current";
            $state["update_message"] = "No updates available.";
        }

        JsonStorage::write(self::stateFile(), $state);

        return [
            "state" => $state,
            "latest" => $latest,
            "update_available" => $updateavailable,
        ];
    }

    /**
     * Function hasUpdateForMenu
     *
     * @return bool
     */
    public static function hasUpdateForMenu(): bool {
        $state = self::state();
        return self::hasCachedUpdate($state) || !empty($state["update_requested"]);
    }

    /**
     * Function hasCachedUpdate
     *
     * @param array<string, mixed>|null $state
     * @return bool
     */
    public static function hasCachedUpdate(?array $state = null): bool {
        $state = $state ?? self::state();
        $localversion = require __DIR__ . "/version.php";

        return $state["latest_tag"] != $localversion["version"];
    }

    /**
     * Function isInstallRequested
     *
     * @return bool
     */
    public static function isInstallRequested(): bool {
        $state = self::state();
        return !empty($state["update_requested"]);
    }

    /**
     * Function requestInstall
     *
     * @return array<string, mixed>
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function requestInstall(): array {
        $state = self::state();
        if (!self::hasCachedUpdate($state)) {
            return [
                "requested" => false,
                "message" => "No updates available.",
                "state" => $state,
            ];
        }

        $user = Auth::user();
        $state["update_requested"] = true;
        $state["update_requested_at"] = now_iso();
        $state["update_requested_by"] = $user["username"] ?? "system";
        $state["update_status"] = "requested";
        $state["update_message"] = t("updater.wait_update_message");
        JsonStorage::write(self::stateFile(), $state);

        return [
            "requested" => true,
            "message" => t("updater.wait_update_message"),
            "state" => $state,
        ];
    }

    /**
     * Function installRequested
     *
     * @return array<string, mixed>
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     * @throws \Throwable
     */
    public static function installRequested(): array {
        $state = self::state();
        if (empty($state["update_requested"])) {
            return [
                "updated" => false,
                "message" => "No update was requested from the panel.",
                "state" => $state,
            ];
        }

        $state["update_status"] = "installing";
        $state["update_message"] = "Update running through the root CRON.";
        JsonStorage::write(self::stateFile(), $state);

        try {
            return self::installLatest($state);
        } catch (Throwable $e) {
            $state = self::state();
            $state["update_requested"] = false;
            $state["update_status"] = "failed";
            $state["update_message"] = $e->getMessage();
            JsonStorage::write(self::stateFile(), $state);
            throw $e;
        }
    }

    /**
     * Function installLatest
     *
     * @return array<string, mixed>
     */
    public static function installLatest($state): array {
        self::ensureZipAvailable();
        self::ensureWorkDirs();

        $lockfile = self::workDir() . "/update.lock";
        $lock = fopen($lockfile, "c");
        if (!$lock) {
            throw new RuntimeException("Could not open the update lock.");
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException("Another update is already running.");
            }

            $check = self::check();
            if (empty($check["update_available"])) {
                return [
                    "updated" => false,
                    "message" => "No update available.",
                    "state" => $check["state"],
                ];
            }

            $stamp = gmdate("YmdHis") . "-" . preg_replace('/[^a-zA-Z0-9_.-]+/', "-", $state["latest_tag"]);

            $zipfile = self::downloadReleaseZip($state, $stamp);
            $extractdir = self::extractReleaseZip($zipfile, $stamp);
            $sourceroot = self::locateSourceRoot($extractdir);
            $backupdir = self::backupCurrentCode($stamp);
            self::syncDirectory($sourceroot, self::projectRoot(), "");

            $newstate = [
                "installed_tag" => $state["latest_tag"],
                "installed_at" => now_iso(),
                "updated_at" => now_iso(),
                "updated_by" => Auth::user()["username"] ?? "system",
                "previous_tag" => $state["installed_tag"],
                "latest_checked_at" => now_iso(),
                "latest_tag" => $state["latest_tag"],
                "latest_published_at" => $state["latest_published_at"],
                "latest_html_url" => $state["latest_html_url"],
                "backup_dir" => $backupdir,
                "update_available" => false,
                "update_marked_at" => "",
                "update_requested" => false,
                "update_requested_at" => "",
                "update_requested_by" => "",
                "update_status" => "installed",
                "update_message" => "Panel updated to version {$state["latest_tag"]}.",
            ];
            JsonStorage::write(self::stateFile(), $newstate);

            self::cleanupOldWorkDirs();

            return [
                "updated" => true,
                "message" => "Panel updated to version {$state["latest_tag"]}.",
                "state" => $newstate,
                "backup_dir" => $backupdir,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Function latestRelease
     *
     * @return array<string, mixed>
     */
    public static function latestRelease(): array {
        $url = "https://api.github.com/repos/EduardoKrausME/moodle_friendly_installation/releases/latest";
        $json = self::httpGet($url, ["Accept: application/vnd.github+json"]);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("GitHub returned invalid JSON.");
        }
        if (empty($data["tag_name"])) {
            $message = $data["message"] ?? "No published release was found on GitHub.";
            throw new RuntimeException($message);
        }

        return [
            "tag_name" => $data["tag_name"],
            "name" => $data["name"] ?? $data["tag_name"],
            "published_at" => $data["published_at"],
            "html_url" => $data["html_url"],
            "zipball_url" => $data["zipball_url"],
            "tarball_url" => $data["tarball_url"],
            "draft" => !empty($data["draft"]),
            "prerelease" => !empty($data["prerelease"]),
        ];
    }

    /**
     * Function stateFile
     *
     * @return string
     */
    private static function stateFile(): string {
        return self::projectRoot() . "/data/update/app_update.json";
    }

    /**
     * Function projectRoot
     *
     * @return string
     */
    private static function projectRoot(): string {
        return PanelConfigManager::projectRoot();
    }

    /**
     * Function workDir
     *
     * @return string
     */
    private static function workDir(): string {
        return self::projectRoot() . "/data/update";
    }

    /**
     * Function ensureWorkDirs
     *
     * @return void
     */
    private static function ensureWorkDirs(): void {
        $workDir = self::workDir();
        foreach ([$workDir, "{$workDir}/downloads", "{$workDir}/extract", "{$workDir}/backups"] as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    throw new RuntimeException("Unable to create directory: {$dir}");
                }
                chmod($dir, 0777);
            }
            if (!is_writable($dir)) {
                throw new RuntimeException("The directory does not have write permission: {$dir}");
            }

        }
    }

    /**
     * Function ensureZipAvailable
     *
     * @return void
     */
    private static function ensureZipAvailable(): void {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("The PHP ZipArchive/php-zip extension needs to be installed to extract the update.");
        }
    }

    /**
     * Function downloadReleaseZip
     *
     * @param array<string, mixed> $latest
     * @param string $stamp
     * @return string
     */
    private static function downloadReleaseZip(array $latest, string $stamp): string {
        if (!isset($latest["latest_zipball_url"][15])) {
            throw new RuntimeException("The GitHub release did not return a zipball_url.");
        }

        $dest = self::workDir() . "/downloads/{$stamp}.zip";
        self::httpDownload($latest["latest_zipball_url"], $dest);
        if (!is_file($dest) || filesize($dest) < 100) {
            throw new RuntimeException("The downloaded file is empty or invalid.");
        }
        chmod($dest, 0640);
        return $dest;
    }

    /**
     * Function extractReleaseZip
     *
     * @param string $zipfile
     * @param string $stamp
     * @return string
     */
    private static function extractReleaseZip(string $zipfile, string $stamp): string {
        $extractdir = self::workDir() . "/extract/{$stamp}";
        if (is_dir($extractdir)) {
            self::deletePath($extractdir);
        }
        if (!mkdir($extractdir, 0750, true)) {
            throw new RuntimeException("Unable to create directory: {$extractdir}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipfile) !== true) {
            throw new RuntimeException("Unable to open the update ZIP file.");
        }
        if (!$zip->extractTo($extractdir)) {
            $zip->close();
            throw new RuntimeException("It was not possible to extract the update ZIP.");
        }
        $zip->close();
        return $extractdir;
    }

    /**
     * Function locateSourceRoot
     *
     * @param string $extractdir
     * @return string
     */
    private static function locateSourceRoot(string $extractdir): string {
        $candidates = [$extractdir];
        foreach (glob($extractdir . "/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $candidates[] = $dir;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate . "/public/app/bootstrap.php")) {
                return $candidate;
            }
        }

        throw new RuntimeException("The release ZIP does not contain public/app/bootstrap.php.");
    }

    /**
     * Function backupCurrentCode
     *
     * @param string $stamp
     * @return string
     */
    private static function backupCurrentCode(string $stamp): string {
        $backupdir = self::workDir() . "/backups/{$stamp}";
        if (is_dir($backupdir)) {
            self::deletePath($backupdir);
        }
        if (!mkdir($backupdir, 0750, true)) {
            throw new RuntimeException("Unable to create directory: {$backupdir}");
        }

        self::copyDirectory(self::projectRoot(), $backupdir, "", true);
        return $backupdir;
    }

    /**
     * Function syncDirectory
     *
     * @param string $src
     * @param string $dst
     * @param string $relative
     * @return void
     */
    private static function syncDirectory(string $src, string $dst, string $relative): void {
        if (!is_dir($dst) && !mkdir($dst, 0750, true)) {
            throw new RuntimeException("Unable to create directory: {$dst}");
        }

        $sourceitems = [];
        foreach (scandir($src) ?: [] as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $sourceitems[$item] = true;
        }

        foreach (scandir($dst) ?: [] as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $rel = self::joinRelative($relative, $item);
            if (self::isPreservedPath($rel)) {
                continue;
            }
            if (!isset($sourceitems[$item])) {
                self::deletePath($dst . "/{$item}");
            }
        }

        foreach (array_keys($sourceitems) as $item) {
            $rel = self::joinRelative($relative, $item);
            if (self::isPreservedPath($rel)) {
                continue;
            }

            $sourcepath = $src . "/{$item}";
            $targetpath = $dst . "/{$item}";

            if (is_dir($sourcepath)) {
                if (is_file($targetpath) || is_link($targetpath)) {
                    self::deletePath($targetpath);
                }
                self::syncDirectory($sourcepath, $targetpath, $rel);
                continue;
            }

            if (is_link($sourcepath)) {
                continue;
            }

            if (is_dir($targetpath)) {
                self::deletePath($targetpath);
            }
            if (!copy($sourcepath, $targetpath)) {
                throw new RuntimeException("Could not copy the file: {$rel}");
            }
            @chmod($targetpath, fileperms($sourcepath) & 0777);
        }
    }

    /**
     * Function copyDirectory
     *
     * @param string $src
     * @param string $dst
     * @param string $relative
     * @param bool $backupmode
     * @return void
     */
    private static function copyDirectory(string $src, string $dst, string $relative, bool $backupmode = false): void {
        foreach (scandir($src) ?: [] as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }

            $rel = self::joinRelative($relative, $item);
            if ($backupmode && self::isSkippedBackupPath($rel)) {
                continue;
            }

            $sourcepath = $src . "/{$item}";
            $targetpath = $dst . "/{$item}";

            if (is_link($sourcepath)) {
                continue;
            }
            if (is_dir($sourcepath)) {
                if (!is_dir($targetpath) && !mkdir($targetpath, 0750, true)) {
                    throw new RuntimeException("Unable to create directory: {$targetpath}");
                }
                self::copyDirectory($sourcepath, $targetpath, $rel, $backupmode);
                continue;
            }

            $parent = dirname($targetpath);
            if (!is_dir($parent) && !mkdir($parent, 0750, true)) {
                throw new RuntimeException("Unable to create directory: {$parent}");
            }
            if (!copy($sourcepath, $targetpath)) {
                throw new RuntimeException("Could not copy the file: {$rel}");
            }
            @chmod($targetpath, fileperms($sourcepath) & 0777);
        }
    }

    /**
     * Function isPreservedPath
     *
     * @param string $relative
     * @return bool
     */
    private static function isPreservedPath(string $relative): bool {
        $relative = trim(str_replace("\\", "/", $relative), "/");
        if ($relative === "") {
            return false;
        }

        $preserve = [
            "data",
            "public/config.php",
            "app-MoodleMobile-V2/res",
            ".git",
            ".env",
        ];

        foreach ($preserve as $path) {
            if ($relative === $path || str_starts_with($relative, "{$path}/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Function isSkippedBackupPath
     *
     * @param string $relative
     * @return bool
     */
    private static function isSkippedBackupPath(string $relative): bool {
        $relative = trim(str_replace("\\", "/", $relative), "/");
        if ($relative === "") {
            return false;
        }

        $skip = [
            "data",
            "app-MoodleMobile-V2/res",
            ".git",
        ];

        foreach ($skip as $path) {
            if ($relative === $path || str_starts_with($relative, "{$path}/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Function joinRelative
     *
     * @param string $base
     * @param string $item
     * @return string
     */
    private static function joinRelative(string $base, string $item): string {
        return trim($base === "" ? $item : "{$base}/{$item}", "/");
    }

    /**
     * Function deletePath
     *
     * @param string $path
     * @return void
     */
    private static function deletePath(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            self::deletePath("{$path}/{$item}");
        }
        @rmdir($path);
    }

    /**
     * Function cleanupOldWorkDirs
     *
     * @return void
     */
    private static function cleanupOldWorkDirs(): void {
        foreach ([self::workDir() . "/extract", self::workDir() . "/downloads"] as $parent) {
            $items = glob($parent . "/*") ?: [];
            rsort($items);
            foreach (array_slice($items, 5) as $path) {
                self::deletePath($path);
            }
        }
    }

    /**
     * Function httpGet
     *
     * @param string $url
     * @param array<int, string> $headers
     * @return string
     */
    private static function httpGet(string $url, array $headers = []): string {
        return self::httpRequest($url, null, $headers);
    }

    /**
     * Function httpDownload
     *
     * @param string $url
     * @param string $dest
     * @return void
     */
    private static function httpDownload(string $url, string $dest): void {
        $body = self::httpRequest($url, null, ["Accept: application/vnd.github+json"]);
        if (file_put_contents($dest, $body, LOCK_EX) === false) {
            throw new RuntimeException("It was not possible to save the update ZIP.");
        }
    }

    /**
     * Function httpRequest
     *
     * @param string $url
     * @param string|null $body
     * @param array<int, string> $headers
     * @return string
     */
    private static function httpRequest(string $url, ?string $body = null, array $headers = []): string {
        $headers[] = "User-Agent: Moodle-Friendly-Installation-Updater";
        $headers[] = "X-GitHub-Api-Version: " . self::GITHUB_API_VERSION;

        if (function_exists("curl_init")) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }

            $result = curl_exec($curl);
            $error = curl_error($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($result === false) {
                throw new RuntimeException("Unable to query GitHub: {$error}");
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("GitHub returned HTTP {$status}");
            }
            return $result;
        }

        $context = stream_context_create([
            "http" => [
                "method" => $body === null ? "GET" : "POST",
                "timeout" => 120,
                "ignore_errors" => true,
                "header" => implode("\r\n", $headers) . "\r\n",
                "content" => $body,
            ],
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($result === false) {
            throw new RuntimeException("Unable to query GitHub: file_get_contents");
        }
        if ($status != 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException("GitHub returned HTTP {$status}");
        }

        return $result;
    }
}

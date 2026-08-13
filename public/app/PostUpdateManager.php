<?php

namespace app;

use RuntimeException;
use Throwable;

/**
 * Manages versioned post-update scripts.
 */
class PostUpdateManager {
    /**
     * Runs all pending post-update scripts up to the installed application version.
     *
     * @return array<string, mixed>
     */
    public static function runPending(): array {
        $root = PanelConfigManager::projectRoot();
        $updatedir = $root . "/bin/post-update";
        $statefile = $root . "/data/update/post-update.json";
        $currentversion = self::currentVersion();

        if (!is_dir($updatedir)) {
            return [
                "current_version" => $currentversion,
                "last_version" => $currentversion,
                "executed" => [],
            ];
        }

        $stateexists = is_file($statefile);
        $state = JsonStorage::read($statefile);
        if (!is_array($state)) {
            $state = [];
        }

        $lastversion = trim((string)($state["last_version"] ?? ""));
        if (!self::isValidVersion($lastversion)) {
            $lastversion = self::initialVersion($currentversion, $stateexists);
        }

        $updates = [];
        foreach (glob($updatedir . "/*.php") ?: [] as $file) {
            $version = basename($file, ".php");
            if (!self::isValidVersion($version)) {
                continue;
            }
            if (version_compare($version, $lastversion, "<=")) {
                continue;
            }
            if (version_compare($version, $currentversion, ">")) {
                continue;
            }
            $updates[$version] = $file;
        }

        uksort($updates, static fn(string $a, string $b): int => version_compare($a, $b));

        $executed = [];
        foreach ($updates as $version => $file) {
            try {
                $callback = require $file;
                if (!is_callable($callback)) {
                    throw new RuntimeException("Post-update {$version}.php must return a callable.");
                }

                $callback();

                $state["last_version"] = $version;
                $state["updated_at"] = now_iso();
                unset($state["last_error"]);
                JsonStorage::write($statefile, $state);

                $lastversion = $version;
                $executed[] = $version;
            } catch (Throwable $e) {
                $state["last_error"] = [
                    "version" => $version,
                    "message" => $e->getMessage(),
                    "failed_at" => now_iso(),
                ];
                JsonStorage::write($statefile, $state);

                throw new RuntimeException(
                    "Post-update {$version} failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        if (!$stateexists && empty($executed)) {
            $state["last_version"] = $lastversion;
            $state["updated_at"] = now_iso();
            JsonStorage::write($statefile, $state);
        }

        return [
            "current_version" => $currentversion,
            "last_version" => $lastversion,
            "executed" => $executed,
        ];
    }

    /**
     * Repairs ownership group and directory traversal permissions under public/.
     *
     * Files keep their existing mode so executable bits and other intentional
     * permissions are not changed. Directories are normalized to 0750.
     *
     * @return void
     */
    public static function repairPublicPermissions(): void {
        $publicdir = PanelConfigManager::projectRoot() . "/public";
        $group = trim((string)app_config("apache_group"));

        if ($group === "") {
            throw new RuntimeException("Web server group is not configured.");
        }
        if (!is_dir($publicdir)) {
            throw new RuntimeException("Public directory was not found: {$publicdir}");
        }

        self::repairPathPermissions($publicdir, true, $group);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($publicdir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            self::repairPathPermissions($item->getPathname(), $item->isDir(), $group);
        }
    }

    /**
     * Repairs one public path.
     *
     * @param string $path
     * @param bool $isdirectory
     * @param string $group
     * @return void
     */
    private static function repairPathPermissions(string $path, bool $isdirectory, string $group): void {
        if (!@chgrp($path, $group)) {
            throw new RuntimeException("Could not change group of {$path} to {$group}.");
        }

        if ($isdirectory && !@chmod($path, 0750)) {
            throw new RuntimeException("Could not change directory permissions: {$path}");
        }
    }

    /**
     * Returns the currently installed application version.
     *
     * @return string
     */
    private static function currentVersion(): string {
        $versionfile = __DIR__ . "/version.php";
        if (!is_file($versionfile)) {
            throw new RuntimeException("Application version file was not found.");
        }

        $versioninfo = require $versionfile;
        $version = trim((string)($versioninfo["version"] ?? ""));
        if (!self::isValidVersion($version)) {
            throw new RuntimeException("Invalid application version: {$version}");
        }

        return $version;
    }

    /**
     * Determines the baseline version when post-update state does not exist yet.
     *
     * During the first upgrade to the post-update system, AppUpdater already stores
     * previous_tag in app_update.json. A fresh installation has no previous version
     * and therefore starts at its current version without running historical updates.
     *
     * @param string $currentversion
     * @param bool $stateexists
     * @return string
     */
    private static function initialVersion(string $currentversion, bool $stateexists): string {
        if ($stateexists) {
            return "0.0.0";
        }

        $appupdatestate = JsonStorage::read(PanelConfigManager::projectRoot() . "/data/update/app_update.json");
        if (is_array($appupdatestate)) {
            $previousversion = trim((string)($appupdatestate["previous_tag"] ?? ""));
            if (self::isValidVersion($previousversion)
                && version_compare($previousversion, $currentversion, "<")) {
                return $previousversion;
            }
        }

        return $currentversion;
    }

    /**
     * Validates the three-part application version format.
     *
     * @param string $version
     * @return bool
     */
    private static function isValidVersion(string $version): bool {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
    }
}

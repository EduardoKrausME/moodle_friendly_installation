<?php

// Reads and changes per-site web server controls through queued root jobs.
namespace app;

use RuntimeException;

/**
 * Class ServerControlManager
 */
class ServerControlManager {
    /**
     * Returns NGINX cache status for a site.
     *
     * @param array $site
     * @return array
     */
    public static function status(array $site): array {
        return [
            "cache" => self::cacheStatus($site),
        ];
    }

    /**
     * Applies a queued server control change.
     *
     * @param array $job
     * @return array
     */
    public static function apply(array $job): array {
        $domain = $job["domain"];
        $site = SiteManager::get($domain);
        if ($site === null) {
            throw new RuntimeException("Site not found for server control: {$domain}");
        }

        $control = $job["control"];
        $enabled = !empty($job["enabled"]);
        return match ($control) {
            "cache" => self::setCache($site, $enabled),
            default => throw new RuntimeException("Unsupported server control: {$control}"),
        };
    }

    /**
     * Reads whether the NGINX cache include is active in the site configuration.
     *
     * @param array $site
     * @return array
     */
    private static function cacheStatus(array $site): array {
        $path = self::nginxConfigPath($site["domain"]);
        if ($path === "" || !is_file($path) || !is_readable($path)) {
            return self::controlState(
                "cache",
                I18n::get("server_controls.cache"),
                false,
                null,
                "muted",
                I18n::get("status.unavailable"),
                I18n::get("server_controls.nginx_config_missing"),
                $path
            );
        }

        $content = (string) file_get_contents($path);
        preg_match_all('/^\s*include\s+\/etc\/nginx\/util\/cache\.conf;\s*$/m', $content, $active);
        preg_match_all('/^\s*#\s*include\s+\/etc\/nginx\/util\/cache\.conf;.*$/m', $content, $inactive);
        $activecount = count($active[0] ?? []);
        $inactivecount = count($inactive[0] ?? []);
        if (($activecount + $inactivecount) === 0) {
            return self::controlState(
                "cache",
                I18n::get("server_controls.cache"),
                false,
                null,
                "muted",
                I18n::get("status.unavailable"),
                I18n::get("server_controls.cache_directive_missing"),
                $path
            );
        }

        $enabled = $activecount > 0;
        $mixed = $activecount > 0 && $inactivecount > 0;
        return self::controlState(
            "cache",
            I18n::get("server_controls.cache"),
            true,
            $enabled,
            $mixed || !$enabled ? "warning" : "ok",
            I18n::get($mixed ? "status.check" : ($enabled ? "status.enabled" : "status.disabled")),
            I18n::get($mixed ? "server_controls.cache_mixed" : ($enabled ? "server_controls.cache_enabled" : "server_controls.cache_disabled")),
            $path,
            ["active_locations" => $activecount, "inactive_locations" => $inactivecount]
        );
    }

    /**
     * Enables or comments all managed NGINX cache includes and reloads NGINX.
     *
     * @param array $site
     * @param bool $enabled
     * @return array
     */
    private static function setCache(array $site, bool $enabled): array {
        $path = self::nginxConfigPath($site["domain"]);
        $original = self::readConfig($path, "NGINX");
        $count = 0;
        $content = preg_replace_callback(
            '/^(\s*)(?:#\s*)?include\s+\/etc\/nginx\/util\/cache\.conf;.*$/m',
            static function(array $matches) use ($enabled, &$count): string {
                $count++;
                return $enabled
                    ? $matches[1] . "include                 /etc/nginx/util/cache.conf;"
                    : $matches[1] . "# include               /etc/nginx/util/cache.conf; # disabled by moodle_friendly_installation";
            },
            $original
        );

        if ($count === 0 || !is_string($content)) {
            throw new RuntimeException(I18n::get("server_controls.cache_directive_missing"));
        }

        self::writeValidatedConfig($path, $original, $content, "nginx");
        return [
            "message" => I18n::get(
                $enabled ? "server_controls.cache_enabled_done" : "server_controls.cache_disabled_done",
                ["domain" => $site["domain"], "count" => $count]
            ),
        ];
    }

    /**
     * Builds a common control state.
     *
     * @param string $key
     * @param string $label
     * @param bool $supported
     * @param bool|null $enabled
     * @param string $status
     * @param string $statuslabel
     * @param string $message
     * @param string $path
     * @param array $extra
     * @return array
     */
    private static function controlState(
        string $key,
        string $label,
        bool $supported,
        ?bool $enabled,
        string $status,
        string $statuslabel,
        string $message,
        string $path,
        array $extra = []
    ): array {
        return array_merge([
            "key" => $key,
            "label" => $label,
            "supported" => $supported,
            "enabled" => $enabled,
            "status" => $status,
            "status_label" => $statuslabel,
            "message" => $message,
            "path" => $path,
        ], $extra);
    }

    /**
     * Returns the Apache virtual host path for the current operating system.
     *
     * @param string $domain
     * @return string
     */
    private static function apacheConfigPath(string $domain): string {
        $candidates = [
            "/etc/apache2/sites-enabled/{$domain}.conf",
            "/etc/httpd/sites-enabled/{$domain}.conf",
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return is_dir("/etc/apache2/sites-enabled") ? $candidates[0] : $candidates[1];
    }

    /**
     * Returns the NGINX virtual host path.
     *
     * @param string $domain
     * @return string
     */
    private static function nginxConfigPath(string $domain): string {
        return "/etc/nginx/sites-enabled/{$domain}.conf";
    }

    /**
     * Reads a configuration file or throws an actionable error.
     *
     * @param string $path
     * @param string $label
     * @return string
     */
    private static function readConfig(string $path, string $label): string {
        if ($path === "" || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException("{$label} configuration is not readable: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read {$label} configuration: {$path}");
        }
        return $content;
    }

    /**
     * Writes, validates and reloads a service, rolling back on failure.
     *
     * @param string $path
     * @param string $original
     * @param string $content
     * @param string $service
     * @return void
     */
    private static function writeValidatedConfig(string $path, string $original, string $content, string $service): void {
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write server configuration: {$path}");
        }

        $test = self::testConfiguration($service);
        if ($test["exitcode"] !== 0) {
            file_put_contents($path, $original, LOCK_EX);
            throw new RuntimeException("Configuration test failed; the original file was restored. {$test["output"]}");
        }

        $reload = self::reloadService($service);
        if ($reload["exitcode"] !== 0) {
            file_put_contents($path, $original, LOCK_EX);
            self::testConfiguration($service);
            self::reloadService($service);
            throw new RuntimeException("Service reload failed; the original file was restored. {$reload["output"]}");
        }
    }

    /**
     * Tests Apache or NGINX configuration.
     *
     * @param string $service
     * @return array{exitcode: int, output: string}
     */
    private static function testConfiguration(string $service): array {
        $command = $service === "nginx"
            ? self::commandPath(["nginx"])
            : self::commandPath(["apache2ctl", "apachectl", "httpd"]);
        if ($command === "") {
            return ["exitcode" => 1, "output" => "Configuration test command was not found."];
        }
        $argument = $service === "nginx" || basename($command) === "httpd"
            ? "-t"
            : "configtest";
        return self::runCommand([$command, $argument]);
    }

    /**
     * Reloads a service after a successful configuration test.
     *
     * @param string $service
     * @return array{exitcode: int, output: string}
     */
    private static function reloadService(string $service): array {
        $systemctl = self::commandPath(["systemctl"]);
        if ($systemctl === "") {
            return ["exitcode" => 1, "output" => "systemctl was not found."];
        }
        $servicename = $service === "nginx" ? "nginx" : (is_dir("/etc/apache2") ? "apache2" : "httpd");
        $result = self::runCommand([$systemctl, "reload", $servicename]);
        if ($result["exitcode"] !== 0) {
            $result = self::runCommand([$systemctl, "restart", $servicename]);
        }
        return $result;
    }

    /**
     * Finds a known executable without accepting user-provided command names.
     *
     * @param array $commands
     * @return string
     */
    private static function commandPath(array $commands): string {
        foreach ($commands as $command) {
            foreach (["/usr/sbin/{$command}", "/usr/bin/{$command}", "/sbin/{$command}", "/bin/{$command}"] as $path) {
                if (is_executable($path)) {
                    return $path;
                }
            }
        }
        return "";
    }

    /**
     * Runs a fixed command using escaped arguments.
     *
     * @param array $arguments
     * @return array{exitcode: int, output: string}
     */
    private static function runCommand(array $arguments): array {
        $command = implode(" ", array_map("escapeshellarg", $arguments)) . " 2>&1";
        $output = [];
        $exitcode = 0;
        exec($command, $output, $exitcode);
        return ["exitcode" => $exitcode, "output" => trim(implode("\n", $output))];
    }
}

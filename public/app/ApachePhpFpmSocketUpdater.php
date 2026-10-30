<?php

namespace app;

use RuntimeException;
use Throwable;

/**
 * Repairs only Moodle HTTP virtual hosts that have the Red Hat PHP-FPM socket on Debian.
 */
final class ApachePhpFpmSocketUpdater {
    /**
     * @param array<int, string>|null $directories
     * @return array<int, string>
     */
    public static function run(
        ?array $directories = null,
        ?callable $validate = null,
        ?callable $reload = null,
        ?string $socket = null
    ): array {
        if ($directories === null && !is_file("/etc/debian_version")) {
            return [];
        }
        $directories ??= ["/etc/apache2/sites-enabled"];
        $changed = [];
        $reloadattempted = false;

        try {
            foreach ($directories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }
                foreach (glob(rtrim($directory, "/") . "/*.conf") ?: [] as $path) {
                    if (basename($path) === "admin.moodle.conf" || !is_file($path)) {
                        continue;
                    }
                    $target = realpath($path);
                    if ($target === false || !is_readable($target)) {
                        throw new RuntimeException("Cannot read Apache virtual host: {$path}");
                    }
                    $original = file_get_contents($target);
                    if ($original === false) {
                        throw new RuntimeException("Cannot read Apache virtual host: {$path}");
                    }
                    if (!str_contains($original, "/run/php-fpm/www.sock")) {
                        continue;
                    }
                    $socket ??= PhpFpmSocketResolver::resolve((string)app_config("php_bin"), "debian");
                    $updated = self::replaceLegacySocket($original, $socket);
                    if ($updated === $original) {
                        continue;
                    }
                    $changed[$target] ??= $original;
                    if (file_put_contents($target, $updated, LOCK_EX) !== strlen($updated)) {
                        throw new RuntimeException("Cannot update Apache virtual host: {$path}");
                    }
                    echo "Apache PHP-FPM socket fixed: {$path}\n";
                }
            }
            if ($changed === []) {
                return [];
            }
            ($validate ?? static fn() => self::command(["/usr/sbin/apache2ctl", "configtest"]))();
            $reloadattempted = true;
            ($reload ?? static fn() => self::command(["systemctl", "reload", "apache2"]))();
            return array_keys($changed);
        } catch (Throwable $error) {
            $failed = [];
            foreach ($changed as $path => $original) {
                if (file_put_contents($path, $original, LOCK_EX) !== strlen($original)) {
                    $failed[] = $path;
                }
            }
            if ($failed !== []) {
                throw new RuntimeException($error->getMessage() . "; rollback failed: " . implode(", ", $failed), 0, $error);
            }
            if ($reloadattempted) {
                try {
                    ($reload ?? static fn() => self::command(["systemctl", "reload", "apache2"]))();
                } catch (Throwable $rollbackerror) {
                    throw new RuntimeException(
                        $error->getMessage() . "; reload after rollback failed: " . $rollbackerror->getMessage(),
                        0,
                        $error
                    );
                }
            }
            throw $error;
        }
    }

    public static function replaceLegacySocket(string $config, string $socket): string {
        if (!str_starts_with($socket, "/") || !preg_match('~^/[A-Za-z0-9_./-]+$~', $socket)) {
            throw new RuntimeException("Invalid PHP-FPM socket path: {$socket}");
        }
        return preg_replace_callback(
            '~<VirtualHost\b([^>]*)>(.*?)</VirtualHost>~is',
            static function(array $vhost) use ($socket): string {
                if (!preg_match('/(?:^|\s)\S+:(?:80|8080)(?:\s|$)/', trim($vhost[1]))) {
                    return $vhost[0];
                }
                if (!preg_match('~^[ \t]*DocumentRoot[ \t]+["\x27]?/home/[^/"\x27\s]+/moodle(?:/public)?["\x27]?[ \t]*$~mi', $vhost[2])) {
                    return $vhost[0];
                }
                return str_replace(
                    "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost/",
                    "proxy:unix:{$socket}|fcgi://localhost/",
                    $vhost[0]
                );
            },
            $config
        );
    }

    /** @param array<int, string> $args */
    private static function command(array $args): void {
        $process = proc_open(
            $args,
            [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot execute: " . implode(" ", $args));
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(implode(" ", $args) . " failed: " . trim($output));
        }
    }
}

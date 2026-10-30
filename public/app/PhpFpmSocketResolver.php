<?php

namespace app;

use RuntimeException;

/**
 * Resolves the Unix socket of the PHP-FPM instance matching the configured PHP CLI.
 */
final class PhpFpmSocketResolver {
    public static function resolve(string $phpbin, string $os): string {
        $series = self::phpSeries($phpbin);
        $default = self::expectedPath($series, $os);
        $poolconfig = $os === "debian"
            ? "/etc/php/{$series}/fpm/pool.d/www.conf"
            : "/etc/php-fpm.d/www.conf";

        $candidates = [];
        if (is_readable($poolconfig)) {
            $config = file_get_contents($poolconfig);
            if ($config !== false && preg_match('/^[ \t]*listen[ \t]*=[ \t]*(\/[^\s;#]+)[ \t]*(?:[;#].*)?$/mi', $config, $match)) {
                $candidates[] = $match[1];
            }
        }
        $candidates[] = $default;

        foreach (array_unique($candidates) as $socket) {
            if (is_socket($socket)) {
                return $socket;
            }
        }

        throw new RuntimeException(
            "PHP-FPM socket for PHP {$series} was not found. Checked: "
            . implode(", ", array_unique($candidates))
            . ". Start the corresponding PHP-FPM service and verify its pool listen directive."
        );
    }

    public static function expectedPath(string $series, string $os): string {
        if (!preg_match('/^\d+\.\d+$/', $series)) {
            throw new RuntimeException("Invalid PHP version: {$series}");
        }
        return match ($os) {
            "debian" => "/run/php/php{$series}-fpm.sock",
            "redhat" => "/run/php-fpm/www.sock",
            default => throw new RuntimeException("Cannot select PHP-FPM socket for unsupported OS: {$os}"),
        };
    }

    public static function phpSeries(string $phpbin): string {
        if (trim($phpbin) === "") {
            throw new RuntimeException("PHP CLI binary is not configured.");
        }
        $process = proc_open(
            [$phpbin, "-r", 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'],
            [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot execute configured PHP CLI: {$phpbin}");
        }
        $version = trim((string)stream_get_contents($pipes[1]));
        $error = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !preg_match('/^\d+\.\d+$/', $version)) {
            throw new RuntimeException("Cannot determine PHP version from {$phpbin}: {$error}");
        }
        return $version;
    }
}

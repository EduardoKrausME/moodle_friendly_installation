<?php

namespace app;

use RuntimeException;

/**
 * Finds the live Unix socket configured for the selected PHP-FPM service.
 * Socket names and PHP versions are never hard-coded in Apache templates.
 */
final class PhpFpmSocketResolver {
    public static function resolve(string $phpbin, string $os): string {
        $series = self::phpSeries($phpbin);
        $pooldirectory = match ($os) {
            "debian" => "/etc/php/{$series}/fpm/pool.d",
            "redhat" => "/etc/php-fpm.d",
            default => throw new RuntimeException("Unsupported OS for PHP-FPM: {$os}"),
        };

        $candidates = self::configuredPoolSockets($pooldirectory);

        // Existing panel configurations may use a custom socket outside the
        // standard pool directory. Check that actual handler as a fallback.
        $panelconf = $os === "debian"
            ? "/etc/apache2/sites-enabled/admin.moodle.conf"
            : "/etc/httpd/sites-enabled/admin.moodle.conf";
        if (is_readable($panelconf)) {
            $config = file_get_contents($panelconf);
            if ($config !== false &&
                preg_match('~^[ \t]*SetHandler[ \t]+["\x27]?proxy:unix:(/[A-Za-z0-9_./-]+)\|fcgi://localhost/~mi', $config, $match)) {
                $candidates[] = $match[1];
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (@filetype($candidate) === "socket") {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "No active PHP-FPM Unix socket was found for PHP {$series}. "
            . "Checked configured pool files in {$pooldirectory} and {$panelconf}. "
            . "Verify the running FPM service and pool listen directives."
        );
    }

    /**
     * Read live pool configuration instead of guessing /run/php/... or
     * /run/php-fpm/...; supports renamed/custom pool files and sockets.
     *
     * @return array<int, string>
     */
    public static function configuredPoolSockets(string $directory): array {
        $files = glob(rtrim($directory, "/") . "/*.conf") ?: [];
        $defaultpool = rtrim($directory, "/") . "/www.conf";
        if (in_array($defaultpool, $files, true)) {
            $files = array_values(array_diff($files, [$defaultpool]));
            array_unshift($files, $defaultpool);
        }

        $sockets = [];
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $config = file_get_contents($file);
            if ($config !== false &&
                preg_match('~^[ \t]*listen[ \t]*=[ \t]*["\x27]?(/[A-Za-z0-9_./-]+)["\x27]?[ \t]*(?:[;#].*)?$~mi', $config, $match)) {
                $sockets[] = $match[1];
            }
        }

        return array_values(array_unique($sockets));
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

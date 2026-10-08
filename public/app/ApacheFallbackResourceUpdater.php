<?php

namespace app;

use RuntimeException;
use Throwable;

/**
 * Repairs Apache virtual hosts created before the Moodle routing fallback was added.
 */
final class ApacheFallbackResourceUpdater {
    /**
     * Updates existing enabled Moodle HTTP virtual hosts and reloads Apache once.
     *
     * The optional arguments allow filesystem and service checks to be tested
     * without changing the machine's real Apache configuration.
     *
     * @param array<int, string>|null $directories
     * @param callable|null $validate
     * @param callable|null $reload
     * @return array<int, string> Updated configuration paths.
     */
    public static function run(?array $directories = null, ?callable $validate = null, ?callable $reload = null): array {
        $directories ??= ["/etc/apache2/sites-enabled", "/etc/httpd/sites-enabled"];
        $changed = [];
        $reloadattempted = false;

        try {
            foreach ($directories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }

                foreach (glob(rtrim($directory, "/") . "/*.conf") ?: [] as $path) {
                    // The admin panel is a separate application, not a Moodle site.
                    if (basename($path) === "admin.moodle.conf" || !is_file($path)) {
                        continue;
                    }

                    // Debian's sites-enabled files are generally symlinks. Preserve
                    // those links by editing their target, rather than replacing them.
                    $target = realpath($path);
                    if ($target === false || !is_readable($target)) {
                        throw new RuntimeException("Cannot read Apache virtual host: {$path}");
                    }

                    $original = file_get_contents($target);
                    if ($original === false) {
                        throw new RuntimeException("Cannot read Apache virtual host: {$path}");
                    }

                    $updated = self::addFallbackResource($original);
                    if ($updated === $original) {
                        continue;
                    }

                    // Remember the original before any write, so partial writes
                    // can also be rolled back if validation or reload fails.
                    $changed[$target] ??= $original;
                    if (file_put_contents($target, $updated, LOCK_EX) !== strlen($updated)) {
                        throw new RuntimeException("Cannot update Apache virtual host: {$path}");
                    }
                    echo "Apache fallback updated: {$path}\n";
                }
            }

            if ($changed === []) {
                return [];
            }

            $service = is_dir("/etc/apache2") ? "apache2" : "httpd";
            ($validate ?? static fn() => self::validateApache())();
            $reloadattempted = true;
            ($reload ?? static fn() => self::runCommand(["systemctl", "reload", $service]))();

            return array_keys($changed);
        } catch (Throwable $error) {
            $failures = [];
            foreach ($changed as $path => $original) {
                if (file_put_contents($path, $original, LOCK_EX) !== strlen($original)) {
                    $failures[] = $path;
                }
            }
            if ($failures !== []) {
                throw new RuntimeException(
                    $error->getMessage() . "; rollback failed for: " . implode(", ", $failures),
                    0,
                    $error
                );
            }
            // A failed reload may have partially applied the new configuration.
            // Try to reactivate the restored files before reporting the error.
            if ($reloadattempted) {
                try {
                    ($reload ?? static fn() => self::runCommand(["systemctl", "reload", $service]))();
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

    /**
     * Adds the fallback only to the webroot Directory of managed HTTP sites.
     * Other Apache directives and unrelated virtual hosts are kept unchanged.
     */
    public static function addFallbackResource(string $config): string {
        return preg_replace_callback(
            '~<VirtualHost\b([^>]*)>(.*?)</VirtualHost>~is',
            static function(array $vhost): string {
                if (!preg_match('/(?:^|\s)\S+:8080(?:\s|$)/', trim($vhost[1]))) {
                    return $vhost[0];
                }
                if (!preg_match('/^[ \t]*DocumentRoot[ \t]+("[^"\r\n]+"|\x27[^\x27\r\n]+\x27|\S+)/mi', $vhost[2], $match)) {
                    return $vhost[0];
                }

                $webroot = rtrim(trim($match[1], "\"'"), "/");
                if (!preg_match('~^/home/[^/]+/moodle(?:/public)?$~', $webroot)) {
                    return $vhost[0];
                }

                $directorypattern = '~(^[ \t]*<Directory[ \t]+("[^"\r\n]+"|\x27[^\x27\r\n]+\x27|[^>\r\n]+)>[ \t]*\r?\n)(.*?)(^[ \t]*</Directory>[ \t]*\r?$)~ims';
                $body = preg_replace_callback(
                    $directorypattern,
                    static function(array $directory) use ($webroot): string {
                        if (rtrim(trim($directory[2], " \t\"'"), "/") !== $webroot) {
                            return $directory[0];
                        }
                        // Legacy Moodle installs can declare the same path twice:
                        // one denied block, followed by the permitted webroot.
                        if (!preg_match('/^[ \t]*(?:Require[ \t]+all[ \t]+granted|Allow[ \t]+from[ \t]+all)\b/mi', $directory[3])
                            || preg_match('/^[ \t]*Require[ \t]+all[ \t]+denied\b/mi', $directory[3])) {
                            return $directory[0];
                        }
                        if (preg_match('/^[ \t]*FallbackResource\b/mi', $directory[3])) {
                            return $directory[0];
                        }

                        $newline = str_contains($directory[0], "\r\n") ? "\r\n" : "\n";
                        $indent = "        ";
                        if (preg_match('/^([ \t]+)(?:Options|Require|AllowOverride|DirectoryIndex|Allow)\b/mi', $directory[3], $indentmatch)) {
                            $indent = $indentmatch[1];
                        }
                        $inner = $directory[3];
                        if ($inner !== "" && !str_ends_with($inner, "\n")) {
                            $inner .= $newline;
                        }
                        return $directory[1] . $inner . $indent . "FallbackResource /r.php" . $newline . $directory[4];
                    },
                    $vhost[2]
                );
                return str_replace($vhost[2], $body, $vhost[0]);
            },
            $config
        );
    }

    private static function validateApache(): void {
        foreach (["/usr/sbin/apache2ctl", "/usr/sbin/apachectl", "/usr/bin/apache2ctl", "/usr/sbin/httpd"] as $binary) {
            if (is_executable($binary)) {
                self::runCommand([$binary, basename($binary) === "httpd" ? "-t" : "configtest"]);
                return;
            }
        }
        throw new RuntimeException("Apache configuration validator not found.");
    }

    /** @param array<int, string> $command */
    private static function runCommand(array $command): void {
        $pipes = [];
        $process = proc_open($command, [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot execute: " . implode(" ", $command));
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(implode(" ", $command) . " failed: " . trim($output));
        }
    }
}

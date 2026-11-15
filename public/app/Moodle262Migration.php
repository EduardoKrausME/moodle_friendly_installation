<?php

declare(strict_types=1);

namespace app;

use RuntimeException;
use Throwable;

/** Applies the 2.6.2 routing settings to existing, managed Moodle installations. */
final class Moodle262Migration {
    /** @param array<string, mixed> $options Test overrides for paths and commands. */
    public static function run(array $options = []): array {
        $home = rtrim((string)($options['home'] ?? '/home'), '/');
        $apache = (string)($options['apache'] ?? (is_dir('/etc/apache2/sites-enabled')
            ? '/etc/apache2/sites-enabled' : '/etc/httpd/sites-enabled'));
        $backupbase = (string)($options['backups'] ?? dirname(__DIR__, 2) . '/data/update/moodle-2.6.2');
        $log = $options['logger'] ?? static function(string $message): void { echo $message . "\n"; };
        $debian = str_contains($apache, '/apache2/');
        $validate = $options['validate'] ?? static fn() => self::command($debian
            ? ['/usr/sbin/apache2ctl', 'configtest'] : ['/usr/sbin/httpd', '-t']);
        $reload = $options['reload'] ?? static fn() => self::command(['systemctl', 'reload', $debian ? 'apache2' : 'httpd']);

        $summary = ['sites' => 0, 'config_updated' => 0, 'config_unchanged' => 0,
            'apache_updated' => 0, 'apache_unchanged' => 0, 'apache_missing' => 0, 'backups' => ''];
        $changes = [];
        $domains = [];
        foreach (self::discoverConfigs($home) as $configpath) {
            $domain = basename(dirname(dirname($configpath)));
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $domain)) {
                throw new RuntimeException('Invalid Moodle site directory: ' . $configpath);
            }
            if (isset($domains[$domain])) {
                throw new RuntimeException('Duplicate Moodle domain directory: ' . $domain);
            }
            $domains[$domain] = true;
            ++$summary['sites'];

            $original = self::read($configpath);
            $updated = self::patchMoodleConfig($original);
            if ($original === $updated) {
                ++$summary['config_unchanged'];
            } else {
                self::plan($changes, $configpath, $updated, 'moodle/' . $domain . '-config.php');
                ++$summary['config_updated'];
            }

            $vhost = rtrim($apache, '/') . '/' . $domain . '.conf';
            if (!file_exists($vhost)) {
                if (is_link($vhost)) {
                    throw new RuntimeException('Broken Apache site symlink: ' . $vhost);
                }
                ++$summary['apache_missing'];
                $log('WARNING: Apache site not found for ' . $domain . '; Moodle config still checked.');
                continue;
            }
            $oldhost = self::read($vhost);
            $newhost = self::patchApache($oldhost, $domain, dirname($configpath));
            if ($oldhost === $newhost) {
                ++$summary['apache_unchanged'];
            } else {
                self::plan($changes, $vhost, $newhost, 'apache/' . $domain . '.conf');
                ++$summary['apache_updated'];
            }
        }

        if ($changes === []) {
            $log('Moodle 2.6.2: no routing changes required (' . $summary['sites'] . ' sites).');
            return $summary;
        }

        $backupdir = rtrim($backupbase, '/') . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!mkdir($backupdir, 0700, true) && !is_dir($backupdir)) {
            throw new RuntimeException('Could not create migration backup: ' . $backupdir);
        }
        chmod($backupdir, 0700);
        $summary['backups'] = $backupdir;
        $written = [];
        $reloadattempted = false;
        try {
            foreach ($changes as $target => $change) {
                $backuppath = $backupdir . '/' . $change['backup'];
                if (!is_dir(dirname($backuppath)) && !mkdir(dirname($backuppath), 0700, true)) {
                    throw new RuntimeException('Cannot create backup directory: ' . dirname($backuppath));
                }
                if (file_put_contents($backuppath, $change['original'], LOCK_EX) !== strlen($change['original'])) {
                    throw new RuntimeException('Cannot back up: ' . $target);
                }
                chmod($backuppath, 0600); // Moodle config.php contains database credentials.
                $written[$target] = $change['original'];
                self::write($target, $change['updated']);
                $log('Updated ' . $target);
            }
            if ($summary['apache_updated'] > 0) {
                $validate();
                $reloadattempted = true;
                $reload();
            }
            $log('Moodle 2.6.2: ' . $summary['sites'] . ' sites, '
                . $summary['config_updated'] . ' Moodle configs and '
                . $summary['apache_updated'] . ' Apache configs updated.');
            return $summary;
        } catch (Throwable $error) {
            $failures = [];
            foreach ($written as $target => $original) {
                try {
                    self::write($target, $original);
                } catch (Throwable $rollback) {
                    $failures[] = $target . ': ' . $rollback->getMessage();
                }
            }
            if ($reloadattempted) {
                try {
                    $reload();
                } catch (Throwable $rollback) {
                    $failures[] = 'Apache reload after rollback: ' . $rollback->getMessage();
                }
            }
            throw new RuntimeException('Moodle 2.6.2 migration failed: ' . $error->getMessage()
                . ($failures ? '; rollback failed: ' . implode('; ', $failures) : ''), 0, $error);
        }
    }

    /** Recursively locate Moodle installations without traversing Moodle code/data trees. */
    private static function discoverConfigs(string $home): array {
        if (!is_dir($home)) {
            return [];
        }
        $configs = [];
        $walk = static function(string $directory, int $depth) use (&$walk, &$configs): void {
            $config = $directory . '/moodle/config.php';
            if (is_file($config) && !is_link($config) && !is_link($directory . '/moodle')) {
                $configs[] = $config;
            }
            if ($depth >= 3) {
                return;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, ['moodle', 'moodledata', 'cache', 'tmp', '.git'], true)) {
                    continue;
                }
                $next = $directory . '/' . $entry;
                if (is_dir($next) && !is_link($next)) {
                    $walk($next, $depth + 1);
                }
            }
        };
        $walk($home, 0);
        sort($configs, SORT_STRING);
        return $configs;
    }

    /** Replace a recognized switch or insert it before Moodle setup; refuse ambiguous configs. */
    public static function patchMoodleConfig(string $content): string {
        if (!str_contains($content, '<?php') || !preg_match('/^[ \t]*\$CFG->wwwroot[ \t]*=/m', $content)) {
            throw new RuntimeException('Unrecognized Moodle config.php; refusing to change routing.');
        }
        $matches = [];
        preg_match_all('/^[ \t]*\$CFG->routerconfigured[ \t]*=[ \t]*([^;\r\n]+)[ \t]*;/m', $content, $matches);
        if (count($matches[0]) > 1) {
            throw new RuntimeException('Multiple routerconfigured assignments require review.');
        }
        if ($matches[0] !== []) {
            $value = trim($matches[1][0]);
            if (!in_array($value, ['true', 'false', '1', '0'], true)) {
                throw new RuntimeException('Custom routerconfigured value requires review.');
            }
            return preg_replace_callback(
                '/^([ \t]*\$CFG->routerconfigured[ \t]*=[ \t]*)(?:true|false|1|0)([ \t]*;)/m',
                static fn(array $match): string => $match[1] . 'false' . $match[2],
                $content,
                1
            ) ?? throw new RuntimeException('Could not update routerconfigured.');
        }
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $updated = preg_replace_callback(
            '~^[ \t]*require(?:_once)?\s*\(?[^\r\n]*\/lib\/setup\.php[^\r\n]*$~mi',
            static fn(array $match): string => '$CFG->routerconfigured = false;' . $newline . $newline . $match[0],
            $content,
            1,
            $count
        );
        if ($updated === null || $count !== 1) {
            throw new RuntimeException('Moodle setup.php entry point not found in config.php.');
        }
        return $updated;
    }

    /** Remove only the generated /r.php fallback from the matching HTTP webroot. */
    public static function patchApache(string $content, string $domain, string $moodledir): string {
        $domainregex = preg_quote($domain, '~');
        $updated = preg_replace_callback(
            '~<VirtualHost\b([^>]*)>(.*?)</VirtualHost>~is',
            static function(array $host) use ($domainregex, $moodledir): string {
                if (!preg_match('/(?:^|\s)\S+:8080(?:\s|$)/', trim($host[1]))
                    || !preg_match('~^[ \t]*ServerName[ \t]+' . $domainregex . '[ \t]*$~mi', $host[2])
                    || !preg_match('~^[ \t]*DocumentRoot[ \t]+("[^"\r\n]+"|\x27[^\x27\r\n]+\x27|\S+)~mi', $host[2], $match)) {
                    return $host[0];
                }
                $webroot = rtrim(trim($match[1], "\"'"), '/');
                if ($webroot !== $moodledir && $webroot !== $moodledir . '/public') {
                    return $host[0];
                }
                $body = preg_replace_callback(
                    '~(<Directory[ \t]+("[^"\r\n]+"|\x27[^\x27\r\n]+\x27|[^>\r\n]+)>)(.*?)(</Directory>)~is',
                    static function(array $directory) use ($webroot): string {
                        if (rtrim(trim($directory[2], " \t\"'"), '/') !== $webroot
                            || !preg_match('/^[ \t]*Require[ \t]+all[ \t]+granted\b/mi', $directory[3])
                            || preg_match('/^[ \t]*Require[ \t]+all[ \t]+denied\b/mi', $directory[3])) {
                            return $directory[0];
                        }
                        $body = preg_replace('~^[ \t]*FallbackResource[ \t]+/r\.php[ \t]*(?:\#[^\r\n]*)?\r?\n~mi', '', $directory[3]);
                        if ($body === null) {
                            throw new RuntimeException('Could not remove Apache FallbackResource.');
                        }
                        return $directory[1] . $body . $directory[4];
                    },
                    $host[2]
                );
                if ($body === null) {
                    throw new RuntimeException('Could not inspect Apache webroot Directory.');
                }
                return str_replace($host[2], $body, $host[0]);
            },
            $content
        );
        return $updated ?? throw new RuntimeException('Could not inspect Apache virtual hosts.');
    }

    /** @param array<string, array{original:string, updated:string, backup:string}> $changes */
    private static function plan(array &$changes, string $path, string $updated, string $backup): void {
        $target = realpath($path);
        if ($target === false || !is_file($target) || !is_writable($target)) {
            throw new RuntimeException('Cannot resolve or write: ' . $path);
        }
        if (isset($changes[$target])) {
            throw new RuntimeException('Two Moodle configs resolve to the same target: ' . $path);
        }
        $changes[$target] = ['original' => self::read($target), 'updated' => $updated, 'backup' => $backup];
    }

    private static function read(string $path): string {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read: ' . $path);
        }
        return $content;
    }

    /** Atomic replacement preserving ownership, permissions and any sites-enabled symlink. */
    private static function write(string $path, string $content): void {
        $mode = fileperms($path) & 0777;
        $owner = fileowner($path);
        $group = filegroup($path);
        $temp = tempnam(dirname($path), '.mfi-2.6.1-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create staged file: ' . $path);
        }
        try {
            if (file_put_contents($temp, $content, LOCK_EX) !== strlen($content)
                || !chmod($temp, $mode)
                || ($owner !== false && !chown($temp, $owner))
                || ($group !== false && !chgrp($temp, $group))
                || !rename($temp, $path)) {
                throw new RuntimeException('Cannot replace: ' . $path);
            }
        } finally {
            if (file_exists($temp)) {
                unlink($temp);
            }
        }
    }

    /** @param array<int, string> $args */
    private static function command(array $args): void {
        $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot execute ' . implode(' ', $args));
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(implode(' ', $args) . ' failed: ' . trim($output));
        }
    }
}

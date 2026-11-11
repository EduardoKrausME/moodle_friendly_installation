<?php

declare(strict_types=1);

namespace app;

use RuntimeException;
use Throwable;

/**
 * Applies the 2.6.1 template fixes to already installed Moodle sites.
 * Patches only the legacy directives; never regenerates an NGINX vhost, as
 * that would discard Certbot SSL blocks and local configuration changes.
 */
final class Moodle261Migration {
    /**
     * @param array<string, mixed> $options Paths and callbacks can be replaced by tests.
     * @return array<string, int|string>
     */
    public static function run(array $options = []): array {
        $home = rtrim((string)($options['home'] ?? '/home'), '/');
        $nginx = rtrim((string)($options['nginx'] ?? '/etc/nginx/sites-enabled'), '/');
        $backupbase = (string)($options['backups'] ?? dirname(__DIR__, 2) . '/data/update/moodle-2.6.1');
        $log = $options['logger'] ?? static function(string $message): void { echo $message . "\n"; };
        $validate = $options['validate'] ?? static fn() => self::command(['nginx', '-t']);
        $reload = $options['reload'] ?? static fn() => self::command(['systemctl', 'reload', 'nginx']);

        $summary = [
            'sites' => 0,
            'config_updated' => 0,
            'config_unchanged' => 0,
            'nginx_updated' => 0,
            'nginx_unchanged' => 0,
            'nginx_missing' => 0,
            'backups' => '',
        ];
        $changes = [];
        $configs = glob($home . '/*/moodle/config.php') ?: [];
        sort($configs, SORT_STRING);

        // Plan all changes before touching any file. A custom/unrecognized
        // NGINX configuration must not leave half the sites upgraded.
        foreach ($configs as $configpath) {
            if (!is_file($configpath)) {
                continue;
            }
            $domain = basename(dirname(dirname($configpath)));
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $domain)) {
                continue;
            }
            ++$summary['sites'];
            $oldconfig = self::read($configpath);
            $newconfig = self::patchMoodleConfig($oldconfig);
            if ($oldconfig !== $newconfig) {
                self::plan($changes, $configpath, $newconfig, 'moodle/' . $domain . '-config.php');
                ++$summary['config_updated'];
            } else {
                ++$summary['config_unchanged'];
            }

            $nginxpath = $nginx . '/' . $domain . '.conf';
            if (!file_exists($nginxpath)) {
                if (is_link($nginxpath)) {
                    throw new RuntimeException('Broken NGINX site symlink: ' . $nginxpath);
                }
                ++$summary['nginx_missing'];
                $log('WARNING: NGINX site not found for ' . $domain . '; Moodle config still checked.');
                continue;
            }
            $oldnginx = self::read($nginxpath);
            $newnginx = self::patchNginx($oldnginx, $domain);
            if ($oldnginx !== $newnginx) {
                self::plan($changes, $nginxpath, $newnginx, 'nginx/' . $domain . '.conf');
                ++$summary['nginx_updated'];
            } else {
                ++$summary['nginx_unchanged'];
            }
        }

        if ($changes === []) {
            $log('Moodle 2.6.1: no changes required (' . $summary['sites'] . ' sites).');
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
                    throw new RuntimeException('Cannot create backup subdirectory: ' . dirname($backuppath));
                }
                $original = $change['original'];
                if (file_put_contents($backuppath, $original, LOCK_EX) !== strlen($original)) {
                    throw new RuntimeException('Could not back up ' . $target);
                }
                chmod($backuppath, 0600); // Moodle config.php contains DB credentials.
                $written[$target] = $original; // Also cover a partially written file.
                self::write($target, $change['updated']);
                $log('Updated ' . $target);
            }
            if ($summary['nginx_updated'] > 0) {
                $validate();
                $reloadattempted = true;
                $reload();
            }
            $log('Moodle 2.6.1: ' . $summary['sites'] . ' sites, ' . $summary['config_updated']
                . ' Moodle configs and ' . $summary['nginx_updated'] . ' NGINX configs updated.');
            return $summary;
        } catch (Throwable $error) {
            $failures = [];
            foreach ($written as $path => $original) {
                try {
                    self::write($path, $original);
                } catch (Throwable $rollback) {
                    $failures[] = $path . ': ' . $rollback->getMessage();
                }
            }
            if ($reloadattempted) {
                try {
                    $reload();
                } catch (Throwable $rollback) {
                    $failures[] = 'NGINX reload after rollback: ' . $rollback->getMessage();
                }
            }
            throw new RuntimeException(
                'Moodle 2.6.1 migration failed: ' . $error->getMessage()
                . ($failures ? '; rollback failed: ' . implode('; ', $failures) : ''),
                0,
                $error
            );
        }
    }

    /** Fix only the old debug.enable switch, not a user's custom debugging settings. */
    public static function patchMoodleConfig(string $content): string {
        $updated = preg_replace_callback(
            '~(if\s*\(\s*file_exists\s*\(\s*["\x27]\{\$domainroot\}/debug\.enable["\x27]\s*\)\s*\)\s*\{)(.*?)(^[ \t]*\})~ms',
            static function(array $matches): string {
                $body = preg_replace(
                    '/^([ \t]*\$CFG->debug[ \t]*=[ \t]*)30719([ \t]*;)/m',
                    '${1}32767${2}',
                    $matches[2]
                );
                return $matches[1] . $body . $matches[3];
            },
            $content
        );
        if ($updated === null) {
            throw new RuntimeException('Cannot process Moodle debug configuration.');
        }
        return $updated;
    }

    /** Preserve the entire vhost (especially SSL); touch only the legacy theme rules. */
    public static function patchNginx(string $content, string $domain): string {
        $quoted = preg_quote($domain, '~');
        if (!preg_match('~^[ \t]*server_name[ \t]+(?:[^;\r\n]*[ \t])?' . $quoted . '(?:[ \t;]|$)~mi', $content)
            || !str_contains($content, 'proxy_pass')
            || !preg_match('~proxy_pass[ \t]+http://127\.0\.0\.1:8080[ \t]*;~', $content)
            || !preg_match('~^[ \t]*location[ \t]+/theme/[ \t]*\{~m', $content)) {
            throw new RuntimeException('Refusing to modify unrecognized NGINX Moodle site for ' . $domain);
        }

        // Only remove blocks exactly matching the legacy generated cache routes.
        $legacy = '~^[ \t]*location[ \t]+/theme/(?:yui_combo\.php|jquery\.php|styles\.php)[ \t]*\{[ \t]*\R'
            . '[ \t]*include[ \t]+/etc/nginx/util/cache\.conf[ \t]*;[ \t]*\R'
            . '[ \t]*add_header[ \t]+EduardoKraus[ \t]+["\x27]?\d+["\x27]?[ \t]*;[ \t]*\R'
            . '[ \t]*\}[ \t]*(?:\R)?~m';
        $updated = preg_replace($legacy, '', $content);
        if ($updated === null) {
            throw new RuntimeException('Cannot process NGINX theme cache configuration.');
        }
        if (preg_match('~^[ \t]*location[ \t]+/theme/(?:yui_combo\.php|jquery\.php|styles\.php)[ \t]*\{~m', $updated)) {
            throw new RuntimeException('Custom legacy theme cache block requires review: ' . $domain);
        }

        if (preg_match('~^[ \t]*location[ \t]+=[ \t]*/theme/styles_debug\.php[ \t]*\{~m', $updated)) {
            // Already migrated. Do not silently accept an incorrectly cached route.
            if (!preg_match('~location[ \t]+=[ \t]*/theme/styles_debug\.php[ \t]*\{(?:(?!\}).)*proxy_cache[ \t]+off[ \t]*;(?:(?!\}).)*proxy_cache_bypass[ \t]+1[ \t]*;(?:(?!\}).)*\}~s', $updated)) {
                throw new RuntimeException('Unexpected styles_debug.php NGINX location for ' . $domain);
            }
            return $updated;
        }

        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $count = 0;
        $updated = preg_replace_callback(
            '~^([ \t]*)location[ \t]+/theme/[ \t]*\{~m',
            static function(array $match) use ($newline): string {
                $i = $match[1];
                $lines = [
                    $i . 'location = /theme/styles_debug.php {',
                    $i . '    proxy_cache             off;',
                    $i . '    proxy_cache_bypass      1;',
                    '',
                    $i . '    proxy_set_header        Host                    $host;',
                    $i . '    proxy_set_header        X-Real-IP               $remote_addr;',
                    $i . '    proxy_set_header        X-Forwarded-For         $proxy_add_x_forwarded_for;',
                    $i . '    proxy_set_header        X-Forwarded-Proto       https;',
                    $i . '    proxy_set_header        X-Forwarded-Host        $host;',
                    '',
                    $i . "    add_header              Nginx-Route             'DEBUG-NOCACHE';",
                    '',
                    $i . '    proxy_pass              http://127.0.0.1:8080;',
                    $i . '}',
                    '',
                ];
                return implode($newline, $lines) . $newline . $match[0];
            },
            $updated,
            1,
            $count
        );
        if ($updated === null || $count !== 1) {
            throw new RuntimeException('Could not add styles_debug.php NGINX location for ' . $domain);
        }
        return $updated;
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

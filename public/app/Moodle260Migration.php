<?php

namespace app;

use Exception;
use RuntimeException;
use Throwable;

/**
 * Repairs existing Moodle installations when updating the panel to 2.6.0.
 * Only managed Apache sites are regenerated. Composer failures are warnings.
 */
final class Moodle260Migration {
    /**
     * @param array<string, mixed> $options Test overrides for paths and actions.
     * @return array{sites:int,composer_ok:int,composer_failed:int,apache_updated:int,apache_unchanged:int,backups:string}
     */
    public static function run(array $options = []): array {
        $home = rtrim((string)($options['home'] ?? '/home'), '/');
        $apache = rtrim((string)($options['apache'] ?? (
            is_dir('/etc/apache2/sites-enabled') ? '/etc/apache2/sites-enabled' : '/etc/httpd/sites-enabled'
        )), '/');
        $templatefile = (string)($options['template'] ?? PanelConfigManager::projectRoot() . '/install/templates/httpd-site.conf');
        $backupbase = (string)($options['backups'] ?? PanelConfigManager::projectRoot() . '/data/update/apache-2.6.0');
        $logfile = (string)($options['composer_log'] ?? PanelConfigManager::projectRoot() . '/data/update/composer-2.6.0.log');
        $log = $options['logger'] ?? static function(string $message): void { echo $message . "\n"; };
        $composer = $options['composer'] ?? static fn(string $dir, string $domain): int => self::installComposer($dir, $domain, $logfile);
        $validate = $options['validate'] ?? static fn(): null => self::runCommand(
            is_dir('/etc/apache2') ? ['/usr/sbin/apache2ctl', 'configtest'] : ['/usr/sbin/httpd', '-t']
        );
        $reload = $options['reload'] ?? static fn(): null => self::runCommand([
            'systemctl', 'reload', is_dir('/etc/apache2') ? 'apache2' : 'httpd'
        ]);

        if (!is_file($templatefile) || !is_readable($templatefile)) {
            throw new RuntimeException('Apache template not readable: ' . $templatefile);
        }
        $template = file_get_contents($templatefile);
        if ($template === false) {
            throw new RuntimeException('Cannot read Apache template: ' . $templatefile);
        }

        $sites = self::discover($home, $apache);
        $results = ['sites' => count($sites), 'composer_ok' => 0, 'composer_failed' => 0,
            'apache_updated' => 0, 'apache_unchanged' => 0, 'backups' => ''];
        if ($sites === []) {
            $log('Moodle 2.6.0: no existing installations discovered.');
            return $results;
        }

        // This first phase is best-effort. Never abort installation of other sites
        // because Composer failed for one of them or is not installed on the host.
        foreach ($sites as $site) {
            try {
                $code = $composer($site['moodledir'], $site['domain']);
                if ($code !== 0) {
                    throw new RuntimeException('Composer exited with code ' . $code);
                }
                ++$results['composer_ok'];
                $log('Composer OK: ' . $site['domain']);
            } catch (Throwable $e) {
                ++$results['composer_failed'];
                $log('WARNING: Composer failed for ' . $site['domain'] . ': ' . $e->getMessage() . '; continuing.');
            }
        }

        if (!is_dir($apache)) {
            throw new RuntimeException('Apache sites-enabled directory not found: ' . $apache);
        }

        // Resolve the current PHP-FPM socket, not the value of an obsolete config.
        $socket = $options['socket'] ?? PhpFpmSocketResolver::resolve(
            (string)app_config('php_bin'),
            str_contains($apache, '/apache2/') ? 'debian' : 'redhat'
        );
        if (!is_string($socket) || !preg_match('~^/[a-zA-Z0-9_./-]+$~', $socket)) {
            throw new RuntimeException('Invalid PHP-FPM socket.');
        }

        $updates = [];
        $backupdir = '';
        $reloadattempted = false;
        try {
            foreach ($sites as $site) {
                $domain = $site['domain'];
                $path = $apache . '/' . $domain . '.conf';
                $exists = is_file($path);
                if (is_link($path) && !$exists) {
                    throw new RuntimeException('Broken Apache symlink: ' . $path);
                }
                $target = $exists ? realpath($path) : $path;
                if ($target === false) {
                    throw new RuntimeException('Cannot resolve Apache config: ' . $path);
                }
                $old = $exists ? file_get_contents($target) : null;
                if ($exists && $old === false) {
                    throw new RuntimeException('Cannot read Apache config: ' . $path);
                }
                if (is_string($old)) {
                    self::assertManagedSite($old, $site);
                }
                $new = self::render($template, $site, $socket);
                if ($new === $old) {
                    ++$results['apache_unchanged'];
                    continue;
                }

                if ($backupdir === '') {
                    $backupdir = rtrim($backupbase, '/') . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
                    if (!is_dir($backupdir) && !mkdir($backupdir, 0700, true)) {
                        throw new RuntimeException('Cannot create Apache backup directory: ' . $backupdir);
                    }
                    chmod($backupdir, 0700);
                }
                if ($old !== null) {
                    $backup = $backupdir . '/' . $domain . '.conf';
                    if (file_put_contents($backup, $old, LOCK_EX) !== strlen($old)) {
                        throw new RuntimeException('Cannot back up Apache config: ' . $path);
                    }
                    chmod($backup, 0600);
                }
                // Register the original before writing so rollback covers partial writes.
                $updates[$target] = ['content' => $old, 'mode' => $exists ? (fileperms($target) & 0777) : 0644];
                self::writeConfig($target, $new, $updates[$target]['mode']);
                ++$results['apache_updated'];
                $log('Apache virtual host recreated: ' . $path);
            }
            if ($updates !== []) {
                $validate();
                $reloadattempted = true;
                $reload();
            }
            $results['backups'] = $backupdir;
            $log('Moodle 2.6.0 completed: ' . $results['sites'] . ' sites, '
                . $results['composer_failed'] . ' Composer warnings, '
                . $results['apache_updated'] . ' Apache configs recreated.');
            return $results;
        } catch (Throwable $e) {
            $rollbackfailures = [];
            foreach ($updates as $target => $original) {
                try {
                    if ($original['content'] === null) {
                        if (file_exists($target) && !unlink($target)) {
                            throw new RuntimeException('Cannot delete generated file');
                        }
                    } else {
                        self::writeConfig($target, $original['content'], $original['mode']);
                    }
                } catch (Throwable $rollback) {
                    $rollbackfailures[] = $target . ': ' . $rollback->getMessage();
                }
            }
            if ($reloadattempted) {
                try {
                    $reload();
                } catch (Throwable $rollback) {
                    $rollbackfailures[] = 'Reload after rollback: ' . $rollback->getMessage();
                }
            }
            if ($rollbackfailures !== []) {
                throw new RuntimeException($e->getMessage() . '; rollback failed: ' . implode('; ', $rollbackfailures), 0, $e);
            }
            throw $e;
        }
    }

    /**
     * discover
     *
     * @param string $home
     * @param string $apache
     * @return array<int, array{domain:string,moodledir:string,webroot:string,dataroot:string}>
     */
    private static function discover(string $home, string $apache): array {
        $sites = [];

        $configs = glob($home . '/*/moodle/config.php') ?: [];
        foreach ($configs as $config) {
            if (!is_file($config)) {
                continue;
            }
            try {
                $moodledir = dirname($config);
                $domain = basename(dirname($moodledir));
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $domain)) {
                    continue;
                }
                $webroot = is_dir($moodledir . '/public') ? $moodledir . '/public' : $moodledir;
                $dataroot = self::dataRoot($config, dirname($moodledir) . '/moodledata', $apache . '/' . $domain . '.conf');
                $sites[$domain] = compact('domain', 'moodledir', 'webroot', 'dataroot');
            } catch (Exception) {
                continue;
            }
        }
        ksort($sites);
        return array_values($sites);
    }

    /**
     * dataRoot
     *
     * @param string $config
     * @param string $fallback
     * @param string $apachefile
     * @return string
     */
    private static function dataRoot(string $config, string $fallback, string $apachefile): string {
        $contents = file_get_contents($config);
        if ($contents === false) {
            throw new RuntimeException('Cannot read Moodle config: ' . $config);
        }
        if (preg_match('~\$CFG->dataroot\s*=\s*([\'\"])(/[^\'\"\r\n]+)\1\s*;~', $contents, $match)) {
            return rtrim($match[2], '/');
        }
        // An older installation may compute dataroot dynamically in PHP. In
        // that case, preserve the path already configured in Apache instead of
        // blindly assuming Moodledata lives beside the Moodle checkout.
        if (is_readable($apachefile)) {
            $oldapache = file_get_contents($apachefile);
            if (is_string($oldapache) && preg_match('~^[ \t]*XSendFilePath[ \t]+["\']?(/[^"\'\r\n]+)["\']?[ \t]*$~mi', $oldapache, $matched)) {
                return rtrim(trim($matched[1]), '/');
            }
        }
        if (is_dir($fallback)) {
            return $fallback;
        }
        throw new RuntimeException('Cannot safely determine dataroot from: ' . $config);
    }

    /** @param array{domain:string,moodledir:string,webroot:string,dataroot:string} $site */
    private static function assertManagedSite(string $old, array $site): void {
        $domain = preg_quote($site['domain'], '~');
        if (preg_match_all('~<VirtualHost\b[^>]*>~i', $old) !== 1
            || !preg_match('~<VirtualHost\s+[^>]*:8080\s*>~i', $old)
            || !preg_match('~^\s*ServerName\s+' . $domain . '\s*$~mi', $old)
            || !preg_match('~^\s*DocumentRoot\s+["\']?'
                . preg_quote($site['moodledir'], '~') . '(?:/public)?["\']?\s*$~mi', $old)) {
            throw new RuntimeException('Refusing to replace custom/unrecognized Apache config for ' . $site['domain']);
        }
    }

    /** @param array{domain:string,moodledir:string,webroot:string,dataroot:string} $site */
    private static function render(string $template, array $site, string $socket): string {
        $vars = [
            '{{DOMAIN}}' => $site['domain'],
            '{{MOODLE_DIR}}' => $site['moodledir'],
            '{{WEBROOT}}' => $site['webroot'],
            '{{MOODLEDATA}}' => $site['dataroot'],
            '{{PHP_FPM_SOCKET}}' => $socket,
        ];
        $result = strtr($template, $vars);
        if (preg_match('/\{\{[A-Z_]+\}\}/', $result)) {
            throw new RuntimeException('Apache template contains an unresolved placeholder.');
        }
        return $result;
    }

    /**
     * writeConfig
     *
     * @param string $path
     * @param string $body
     * @param int $mode
     * @return void
     */
    private static function writeConfig(string $path, string $body, int $mode): void {
        $tmp = tempnam(dirname($path), '.mfi-2.6.0-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot stage Apache config: ' . $path);
        }
        try {
            if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !chmod($tmp, $mode) || !rename($tmp, $path)) {
                throw new RuntimeException('Cannot write Apache config: ' . $path);
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * installComposer
     *
     * @param string $directory
     * @param string $domain
     * @param string $logfile
     * @return int
     */
    private static function installComposer(string $directory, string $domain, string $logfile): int {
        if (!is_file($directory . '/composer.json')) {
            throw new RuntimeException('composer.json not found');
        }
        $parent = dirname($logfile);
        if (!is_dir($parent) && !mkdir($parent, 0750, true)) {
            throw new RuntimeException('Cannot create Composer log directory');
        }
        $handle = fopen($logfile, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Cannot open Composer log');
        }
        chmod($logfile, 0640);
        try {
            fwrite($handle, "\n[" . date('c') . "] composer for " . $domain . "\n");
            $process = proc_open(
                ['/usr/bin/env', 'COMPOSER_ALLOW_SUPERUSER=1', 'composer', 'install', '--no-dev', '--classmap-authoritative', '--no-interaction'],
                [0 => ['file', '/dev/null', 'r'], 1 => $handle, 2 => $handle],
                $pipes,
                $directory
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start Composer');
            }
            return proc_close($process);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int,string> $command */
    private static function runCommand(array $command): null {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start command: ' . implode(' ', $command));
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(implode(' ', $command) . ' failed: ' . trim($output));
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace app;

use RuntimeException;

/** Execute Moodle's own database upgrade CLI, never PHP application code as root. */
final class MoodlePluginUpgrade {
    /**
     * Validate the configured Moodle directory and build a shell-free argv array.
     * @param array<string, mixed> $site
     * @return array<int, string>
     */
    public static function command(array $site, string $phpbin, string $apacheuser): array {
        $domain = (string)($site['domain'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $domain)) {
            throw new RuntimeException('Invalid Moodle domain.');
        }
        $moodledir = realpath((string)($site['moodle_dir'] ?? ''));
        $webroot = realpath((string)($site['webroot'] ?? ''));
        if ($moodledir === false || $webroot === false || !is_file($moodledir . '/config.php')
            || !in_array($webroot, [$moodledir, $moodledir . '/public'], true)) {
            throw new RuntimeException('Moodle installation could not be verified for ' . $domain);
        }
        // The CLI entry point does not necessarily live under the HTTP document root.
        // Check both supported layouts, preferring the configured webroot.
        $candidates = array_unique([
            $webroot . '/admin/cli/upgrade.php',
            $moodledir . '/admin/cli/upgrade.php',
            $moodledir . '/public/admin/cli/upgrade.php',
        ]);
        $upgrade = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $upgrade = $candidate;
                break;
            }
        }
        if ($upgrade === null) {
            throw new RuntimeException('Moodle CLI upgrade.php not found: ' . implode(', ', $candidates));
        }
        if ($phpbin === '' || $phpbin[0] !== '/' || !is_executable($phpbin)) {
            throw new RuntimeException('Invalid configured PHP CLI binary.');
        }
        if (!preg_match('/^[a-z_][a-z0-9_-]*[$]?$/iD', $apacheuser) || $apacheuser === 'root') {
            throw new RuntimeException('Invalid Apache user for Moodle CLI execution.');
        }
        return ['timeout', '--signal=TERM', '--kill-after=15s', '900s', 'runuser',
            '-u', $apacheuser, '--', $phpbin, $upgrade, '--non-interactive'];
    }

    /**
     * Run CLI and append stdout/stderr to the private job log.
     * @param callable|null $execute Optional test runner receiving (argv, logfile), returning an exit code.
     */
    public static function run(array $site, string $logfile, ?callable $execute = null): void {
        $command = self::command($site, (string)app_config('php_bin'), (string)app_config('apache_user'));
        $execute ??= static function(array $argv, string $file): int {
            $process = proc_open($argv, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $file, 'a'],
                2 => ['file', $file, 'a'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start Moodle upgrade CLI process.');
            }
            return proc_close($process);
        };
        $exitcode = $execute($command, $logfile);
        if ($exitcode !== 0) {
            throw new RuntimeException('Moodle upgrade CLI failed (exit code ' . $exitcode . '). Check the job log.');
        }
    }
}

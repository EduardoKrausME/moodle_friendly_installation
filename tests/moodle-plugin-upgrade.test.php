<?php

declare(strict_types=1);

namespace app {
    class Auth {
        public static function user(): array { return ['username' => 'tester']; }
    }
}

namespace {
    use app\JobManager;
    use app\JsonStorage;
    use app\MoodlePluginUpgrade;

    $root = sys_get_temp_dir() . '/mfi-plugin-upgrade-' . bin2hex(random_bytes(5));
    function app_config(?string $key = null): mixed {
        $data = ['php_bin' => PHP_BINARY, 'apache_user' => 'www-data', 'apache_group' => ''];
        return $key === null ? $data : ($data[$key] ?? null);
    }
    function app_config_path(string $path): string {
        global $root;
        return $root . $path;
    }
    function now_iso(): string { return date('c'); }
    function verifyUpgrade(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $message); }
        echo 'PASS: ' . $message . "\n";
    }
    function cleanupUpgrade(string $path): void {
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') { cleanupUpgrade($path . '/' . $file); }
        }
        rmdir($path);
    }
    require_once __DIR__ . '/../public/app/JsonStorage.php';
    require_once __DIR__ . '/../public/app/JobManager.php';
    require_once __DIR__ . '/../public/app/MoodlePluginUpgrade.php';

    try {
        $site = ['domain' => 'example.org', 'moodle_dir' => $root . '/home/example.org/moodle',
            'webroot' => $root . '/home/example.org/moodle/public'];
        mkdir($site['webroot'] . '/admin/cli', 0755, true);
        file_put_contents($site['moodle_dir'] . '/config.php', "<?php\n");
        file_put_contents($site['webroot'] . '/admin/cli/upgrade.php', "<?php\n");

        $command = MoodlePluginUpgrade::command($site, PHP_BINARY, 'www-data');
        verifyUpgrade(in_array($site['webroot'] . '/admin/cli/upgrade.php', $command, true),
            'Moodle 5.1+ public/admin/cli upgrade discovered');
        verifyUpgrade(array_slice($command, -1) === ['--non-interactive'], 'CLI uses non-interactive mode');
        verifyUpgrade(array_slice($command, 4, 4) === ['runuser', '-u', 'www-data', '--'],
            'CLI runs as Apache user rather than root');

        $legacy = $site;
        $legacy['webroot'] = $legacy['moodle_dir'];
        mkdir($legacy['webroot'] . '/admin/cli', 0755, true);
        file_put_contents($legacy['webroot'] . '/admin/cli/upgrade.php', "<?php\n");
        verifyUpgrade(in_array($legacy['webroot'] . '/admin/cli/upgrade.php',
            MoodlePluginUpgrade::command($legacy, PHP_BINARY, 'www-data'), true),
            'Legacy Moodle admin/cli path discovered');
        // HTTP public/ can exist while the CLI entry point remains in the Moodle root.
        $publiccli = $site['webroot'] . '/admin/cli/upgrade.php';
        $rootcli = $site['moodle_dir'] . '/admin/cli/upgrade.php';
        unlink($publiccli);
        verifyUpgrade(in_array($rootcli, MoodlePluginUpgrade::command($site, PHP_BINARY, 'www-data'), true),
            'Public webroot falls back to root admin/cli upgrade');
        unlink($rootcli);
        try {
            MoodlePluginUpgrade::command($site, PHP_BINARY, 'www-data');
            throw new \RuntimeException('Expected missing CLI failure.');
        } catch (\RuntimeException $e) {
            verifyUpgrade(str_contains($e->getMessage(), 'Moodle CLI upgrade.php not found:')
                && str_contains($e->getMessage(), $rootcli)
                && str_contains($e->getMessage(), $publiccli), 'Missing CLI reports both candidate paths');
        }
        file_put_contents($publiccli, "<?php\n");
        verifyUpgrade(in_array($publiccli, MoodlePluginUpgrade::command($legacy, PHP_BINARY, 'www-data'), true),
            'Legacy webroot falls back to public admin/cli upgrade');
        file_put_contents($rootcli, "<?php\n");
        verifyUpgrade(in_array($publiccli, MoodlePluginUpgrade::command($site, PHP_BINARY, 'www-data'), true),
            'Configured webroot takes precedence when both CLI paths exist');
        $calls = 0;
        MoodlePluginUpgrade::run($site, $root . '/dummy.log',
            static function(array $argv, string $log) use (&$calls): int { ++$calls; return 0; });
        verifyUpgrade($calls === 1, 'Successful CLI execution accepted');
        try {
            MoodlePluginUpgrade::run($site, $root . '/dummy.log',
                static fn(array $argv, string $log): int => 124);
            throw new \RuntimeException('Expected timeout failure.');
        } catch (\RuntimeException $e) {
            verifyUpgrade(str_contains($e->getMessage(), '124'), 'CLI timeout reported as failure');
        }
        $bad = $site;
        $bad['webroot'] = $root;
        try {
            MoodlePluginUpgrade::command($bad, PHP_BINARY, 'www-data');
            throw new \RuntimeException('Expected invalid site failure.');
        } catch (\RuntimeException $e) {
            verifyUpgrade(str_contains($e->getMessage(), 'could not be verified'),
                'Refuses unrecognized site directory');
        }
        try {
            MoodlePluginUpgrade::command($site, PHP_BINARY, 'root');
            throw new \RuntimeException('Expected root rejection.');
        } catch (\RuntimeException $e) {
            verifyUpgrade(str_contains($e->getMessage(), 'Invalid Apache user'), 'Never runs Moodle CLI as root');
        }

        $queued = JobManager::createPluginUpgradeJob($site, 'local_kopere_mobile');
        verifyUpgrade(($queued['type'] ?? '') === 'plugin_upgrade' && ($queued['status'] ?? '') === 'pending',
            'Creates queued upgrade job');
        $duplicate = JobManager::createPluginUpgradeJob($site, 'mod_forum');
        verifyUpgrade($duplicate['id'] === $queued['id'], 'Same site does not queue duplicate upgrades');
        $pending = JobManager::nextPendingJob();
        verifyUpgrade(($pending['id'] ?? '') === $queued['id'], 'Cron runner can pick up queued upgrade');
        JobManager::markRunning($queued['id']);
        verifyUpgrade(JobManager::activeJob('plugin_upgrade', 'example.org') !== null, 'Running job is active');
        JobManager::markDone($queued['id']);
        verifyUpgrade(JobManager::activeJob('plugin_upgrade', 'example.org') === null, 'Completed job is inactive');
        $retry = JobManager::createPluginUpgradeJob($site, 'core');
        verifyUpgrade($retry['id'] !== $queued['id'], 'Manual CLI upgrade can be queued after completion');
        JobManager::markFailed($retry['id'], 'test failure');
        verifyUpgrade((JobManager::latestPluginUpgradeJob('example.org')['status'] ?? '') === 'failed',
            'Failure remains visible in latest job status');

        // Exercise selection with controlled queue contents, independent of job creation.
        $job = static fn(string $id, string $type, string $status, string $date): array => [
            'id' => $id, 'type' => $type, 'status' => $status, 'created_at' => $date,
        ];
        $waiting = $job('old', 'install_moodle', 'waiting_dns', '2026-01-01');
        $ready = $job('new', 'app_build', 'pending', '2026-01-02');
        $background = $job('background', 'resource_usage', 'pending', '2025-01-01');
        $running = $job('running', 'app_build', 'running', '2025-01-01');
        $done = $job('done', 'app_build', 'done', '2025-01-01');
        $queue = app_config_path('/data/jobs.json');
        JsonStorage::write($queue, [$waiting, $ready, $background, $running, $done]);
        $selected = [];
        for ($i = 0; $i < 100; ++$i) {
            $id = JobManager::nextPendingJob()['id'] ?? '';
            $selected[$id] = true;
        }
        verifyUpgrade(count($selected) === 2 && isset($selected['old'], $selected['new']),
            'Randomly selects both eligible foreground jobs without selecting lower priority or inactive jobs');
        $cleanup = $job('cleanup', 'cleanup_failed_installation', 'pending', '2026-01-03');
        JsonStorage::write($queue, [$waiting, $ready, $background, $cleanup]);
        verifyUpgrade((JobManager::nextPendingJob()['id'] ?? '') === 'cleanup', 'Cleanup retains priority');
        JsonStorage::write($queue, [$running, $done, $background]);
        verifyUpgrade((JobManager::nextPendingJob()['id'] ?? '') === 'background', 'Background runs when foreground is empty');
        JsonStorage::write($queue, [$running, $done]);
        verifyUpgrade(JobManager::nextPendingJob() === null, 'No eligible jobs returns null');
        $legacyPending = $job('legacy-pending', 'server_control', 'pending', '2026-01-01');
        $legacyRunning = $job('legacy-running', 'server_control', 'running', '2026-01-01');
        $legacyDone = $job('legacy-done', 'server_control', 'done', '2026-01-01');
        $upgrade = $job('upgrade', 'plugin_upgrade', 'pending', '2026-01-01');
        JsonStorage::write($queue, [$legacyPending, $legacyRunning, $legacyDone, $upgrade]);
        verifyUpgrade((JobManager::nextPendingJob()['id'] ?? '') === 'upgrade', 'Retired controls cannot be selected by cron');
        verifyUpgrade(JobManager::retireServerControlJobs() === 2, 'Pending and interrupted legacy controls retired');
        $history = array_column(JobManager::all(), null, 'id');
        verifyUpgrade($history['legacy-pending']['status'] === 'canceled', 'Pending legacy job canceled');
        verifyUpgrade($history['legacy-running']['status'] === 'failed', 'Interrupted legacy job marked failed');
        verifyUpgrade($history['legacy-done'] === $legacyDone, 'Completed legacy history preserved');
        verifyUpgrade($history['upgrade'] === $upgrade, 'Plugin upgrade job untouched');
        verifyUpgrade(JobManager::retireServerControlJobs() === 0, 'Legacy retirement is idempotent');
        verifyUpgrade((JobManager::nextPendingJob()['id'] ?? '') === 'upgrade', 'Plugin upgrades remain executable');

        $details = file_get_contents(__DIR__ . '/../public/details.php');
        $template = file_get_contents(__DIR__ . '/../public/app/templates/page/details.mustache');
        $bootstrap = file_get_contents(__DIR__ . '/../public/app/bootstrap.php');
        $runner = file_get_contents(__DIR__ . '/../bin/cron-root-runner.php');
        verifyUpgrade(!str_contains($details, 'server_control') && !str_contains($template, 'server_controls')
            && !str_contains($bootstrap, 'ServerControlManager.php')
            && !str_contains($runner, 'cron-server_control.php'), 'Removed cache UI, endpoint, bootstrap and cron dispatch');
        verifyUpgrade(!method_exists(JobManager::class, 'createServerControlJob')
            && !file_exists(__DIR__ . '/../public/app/ServerControlManager.php')
            && !file_exists(__DIR__ . '/../bin/cron-server_control.php'), 'Removed obsolete cache control implementation');
        $bottom = strpos($template, 'class="site-details-bottom-grid');
        $diagnostic = strpos($template, 'i18n.details.diagnostics_heading');
        $files = strpos($template, 'i18n.details.files_heading');
        $config = strpos($template, 'i18n.details.config_heading');
        verifyUpgrade($bottom !== false && $diagnostic > $bottom && $files > $diagnostic && $config > $files
            && strpos($template, 'i18n.details.flags_heading') < $bottom, 'Three site detail cards appear together at the end');
        verifyUpgrade(str_contains($template, 'site-details-bottom-grid{{#stats_warning}} site-details-bottom-grid-single{{/stats_warning}}'),
            'Diagnostics remain available when database statistics fail');
        foreach (['style.scss', 'style.css'] as $stylesheet) {
            $css = file_get_contents(__DIR__ . '/../public/assets/' . $stylesheet);
            verifyUpgrade(preg_match('/\\.site-details-bottom-grid\\s*\\{[^}]*grid-template-columns:\\s*repeat\\(3, minmax\\(0, 1fr\\)\\)/s', $css)
                && str_contains($css, '.site-details-bottom-grid:not(.site-details-bottom-grid-single)')
                && str_contains($css, '@media (max-width: 920px)'), 'Responsive three-column site details: ' . $stylesheet);
        }
        echo "ALL TESTS PASSED\n";
    } finally {
        cleanupUpgrade($root);
    }
}

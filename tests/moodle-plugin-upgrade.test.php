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
        echo "ALL TESTS PASSED\n";
    } finally {
        cleanupUpgrade($root);
    }
}

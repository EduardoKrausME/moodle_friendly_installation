<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/app/Moodle260Migration.php';

use app\Moodle260Migration;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

function rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rrmdir($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

$fixture = sys_get_temp_dir() . '/moodle260-test-' . bin2hex(random_bytes(5));
$home = $fixture . '/home';
$apache = $fixture . '/apache/sites-enabled';
$available = $fixture . '/apache/sites-available';
$backups = $fixture . '/backups';
$logfile = $fixture . '/composer.log';
$logs = [];
$composerCalls = [];
$validations = 0;
$reloads = 0;

$template = __DIR__ . '/../install/templates/httpd-site.conf';
$options = [
    'home' => $home,
    'apache' => $apache,
    'template' => $template,
    'backups' => $backups,
    'composer_log' => $logfile,
    'socket' => '/run/php/php8.4-fpm.sock',
    'logger' => static function(string $msg) use (&$logs): void { $logs[] = $msg; },
    'composer' => static function(string $dir, string $domain) use (&$composerCalls): int {
        $composerCalls[] = [$dir, $domain];
        if ($domain === 'bad.example.org') {
            throw new RuntimeException('composer.json failure');
        }
        return $domain === 'legacy.example.org' ? 42 : 0;
    },
    'validate' => static function() use (&$validations): void { ++$validations; },
    'reload' => static function() use (&$reloads): void { ++$reloads; },
];

try {
    mkdir($home, 0755, true);
    mkdir($apache, 0755, true);
    mkdir($available, 0755, true);
    foreach (['good.example.org', 'bad.example.org', 'legacy.example.org'] as $domain) {
        $moodle = $home . '/' . $domain . '/moodle';
        mkdir($moodle, 0755, true);
        mkdir($home . '/' . $domain . '/moodledata', 0755, true);
        file_put_contents($moodle . '/config.php', "<?php\n\$CFG->dataroot = '" . $home . '/' . $domain . "/moodledata';\n");
        if ($domain !== 'legacy.example.org') {
            mkdir($moodle . '/public');
        }
    }
    $legacyHost = static function(string $domain) use ($home): string {
        $root = $home . '/' . $domain . '/moodle' . ($domain === 'legacy.example.org' ? '' : '/public');
        return <<<CONF
<VirtualHost *:8080>
    ServerName {$domain}
    DocumentRoot {$root}
    <Directory {$root}>
        Require all granted
    </Directory>
</VirtualHost>
CONF;
    };
    file_put_contents($available . '/good.example.org.conf', $legacyHost('good.example.org'));
    symlink($available . '/good.example.org.conf', $apache . '/good.example.org.conf');
    file_put_contents($apache . '/bad.example.org.conf', $legacyHost('bad.example.org'));
    file_put_contents($apache . '/admin.moodle.conf', '<VirtualHost *:8080>Admin panel</VirtualHost>');

    $summary = Moodle260Migration::run($options);
    check($summary['sites'] === 3, 'all installed Moodle instances were discovered');
    check($summary['composer_ok'] === 1 && $summary['composer_failed'] === 2, 'Composer failures were isolated per site');
    check(count($composerCalls) === 3, 'Composer was attempted in every Moodle directory');
    check($summary['apache_updated'] === 3, 'all managed Apache configs were recreated including a missing one');
    check($validations === 1 && $reloads === 1, 'Apache was validated and reloaded exactly once');
    check(is_link($apache . '/good.example.org.conf'), 'Debian Apache symlink was preserved');
    check(file_get_contents($apache . '/admin.moodle.conf') === '<VirtualHost *:8080>Admin panel</VirtualHost>', 'panel virtual host was untouched');
    foreach (['good.example.org', 'bad.example.org', 'legacy.example.org'] as $domain) {
        $content = file_get_contents($apache . '/' . $domain . '.conf');
        check(substr_count($content, 'FallbackResource /r.php') === 1, $domain . ': one fallback present');
        check(substr_count($content, 'DirectoryIndex index.php') === 1, $domain . ': DirectoryIndex present');
        check(str_contains($content, '/run/php/php8.4-fpm.sock'), $domain . ': PHP-FPM socket set');
    }
    check(str_contains(file_get_contents($apache . '/legacy.example.org.conf'), 'DocumentRoot ' . $home . '/legacy.example.org/moodle' . "\n"), 'legacy root is preserved');
    check(is_file($summary['backups'] . '/good.example.org.conf'), 'backup of original symlink target persisted');
    check(!is_file($summary['backups'] . '/legacy.example.org.conf'), 'new configs have no nonexistent original backup');
    check(count(array_filter($logs, fn(string $v): bool => str_contains($v, 'WARNING: Composer failed'))) === 2, 'warnings are visible in logging');

    $again = Moodle260Migration::run($options);
    check($again['apache_unchanged'] === 3 && $again['apache_updated'] === 0, 'Apache regeneration is idempotent');
    check($validations === 1 && $reloads === 1, 'idempotent run avoids unnecessary service reload');

    // A newly created configuration must be removed if Apache validation fails.
    $third = $apache . '/legacy.example.org.conf';
    unlink($third);
    $failCreate = $options;
    $failCreate['validate'] = static function(): void { throw new RuntimeException('new file validation error'); };
    try {
        Moodle260Migration::run($failCreate);
        throw new RuntimeException('FAIL: expected validation failure on newly generated vhost');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'new file validation error'), 'new-file validation failure propagates');
    }
    check(!file_exists($third), 'rollback deletes newly generated virtual host');
    $recreate = Moodle260Migration::run($options);
    check($recreate['apache_updated'] === 1 && file_exists($third), 'retry recreates the absent virtual host');

    $original = file_get_contents($available . '/good.example.org.conf');
    $badOriginal = $legacyHost('bad.example.org');
    file_put_contents($apache . '/bad.example.org.conf', $badOriginal);
    $brokenOptions = $options;
    $brokenOptions['validate'] = static function(): void { throw new RuntimeException('Apache configtest failure'); };
    try {
        Moodle260Migration::run($brokenOptions);
        throw new RuntimeException('FAIL: expected configtest failure');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'configtest failure'), 'validation failures propagate to trigger retry');
    }
    check(file_get_contents($apache . '/bad.example.org.conf') === $badOriginal, 'validation failure rolls back prior config');
    check(file_get_contents($available . '/good.example.org.conf') === $original, 'validation failure leaves other config unchanged');
    $restore = Moodle260Migration::run($options);
    check($restore['apache_updated'] === 1, 'retry safely restores missing fallback after prior failure');

    file_put_contents($apache . '/bad.example.org.conf', $badOriginal);
    $reloadCount = 0;
    $reloadOptions = $options;
    $reloadOptions['reload'] = static function() use (&$reloadCount): void {
        if (++$reloadCount === 1) {
            throw new RuntimeException('Apache reload failure');
        }
    };
    try {
        Moodle260Migration::run($reloadOptions);
        throw new RuntimeException('FAIL: expected reload failure');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'reload failure'), 'reload failures propagate');
    }
    check($reloadCount === 2, 'service is reloaded after restoring old configs');
    check(file_get_contents($apache . '/bad.example.org.conf') === $badOriginal, 'reload failure rolls back on-disk config');

    // When dataroot is computed dynamically, preserve the path in the old vhost.
    $badConfig = $home . '/bad.example.org/moodle/config.php';
    $originalConfig = file_get_contents($badConfig);
    file_put_contents($badConfig, "<?php\n\$CFG->dataroot = getenv('CUSTOM_MOODLEDATA');\n");
    file_put_contents($apache . '/bad.example.org.conf', str_replace(
        '    <Directory ',
        "    XSendFilePath /custom/moodle-data\n    <Directory ",
        $legacyHost('bad.example.org')
    ));
    $dynamic = Moodle260Migration::run($options);
    check($dynamic['apache_updated'] === 1, 'dynamic Moodle data root triggers regeneration');
    check(str_contains(file_get_contents($apache . '/bad.example.org.conf'), 'XSendFilePath /custom/moodle-data'), 'preserves configured custom data root');
    file_put_contents($badConfig, $originalConfig);

    $custom = '<VirtualHost *:443>unrelated app</VirtualHost>';
    file_put_contents($apache . '/bad.example.org.conf', $custom);
    try {
        Moodle260Migration::run($options);
        throw new RuntimeException('FAIL: expected custom config rejection');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'Refusing to replace'), 'unrecognized Apache config is rejected');
    }
    check(file_get_contents($apache . '/bad.example.org.conf') === $custom, 'unrecognized Apache config remains intact');
    echo "ALL TESTS PASSED\n";
} finally {
    rrmdir($fixture);
}

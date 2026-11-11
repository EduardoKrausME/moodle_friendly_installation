<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/app/Moodle261Migration.php';

use app\Moodle261Migration;

function ok(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

$root = sys_get_temp_dir() . '/moodle261-' . bin2hex(random_bytes(5));
$home = $root . '/home';
$enabled = $root . '/nginx/sites-enabled';
$available = $root . '/nginx/sites-available';
$backups = $root . '/backups';
$validateCalls = 0;
$reloadCalls = 0;
$messages = [];

$nginxTemplate = <<<'CONF'
server {
    server_name          {{DOMAIN}};
    # Certbot and custom directives must survive the migration.
    listen 443 ssl; # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem;
    location /theme/yui_combo.php {
        include                 /etc/nginx/util/cache.conf;
        add_header              EduardoKraus            '0009';
    }
    location /theme/jquery.php {
        include                 /etc/nginx/util/cache.conf;
        add_header              EduardoKraus            '0018';
    }
    location /theme/styles.php {
        include                 /etc/nginx/util/cache.conf;
        add_header              EduardoKraus            '0041';
    }
    location /theme/ {
        include /etc/nginx/util/cache.conf;
    }
    location / {
        proxy_pass http://127.0.0.1:8080;
    }
}
CONF;

$configTemplate = <<<'PHP_CONFIG'
<?php
$CFG->debug = 30719; // custom config outside the debug.enable switch
$domainroot = dirname(__DIR__);
if (file_exists("{$domainroot}/debug.enable")) {
    error_reporting(E_ALL);
    $CFG->debug = 30719;
    $CFG->debugdisplay = 1;
}
PHP_CONFIG;

$options = [
    'home' => $home,
    'nginx' => $enabled,
    'backups' => $backups,
    'logger' => static function(string $message) use (&$messages): void { $messages[] = $message; },
    'validate' => static function() use (&$validateCalls): void { ++$validateCalls; },
    'reload' => static function() use (&$reloadCalls): void { ++$reloadCalls; },
];

try {
    mkdir($home, 0755, true);
    mkdir($enabled, 0755, true);
    mkdir($available, 0755, true);
    foreach (['first.example.com', 'second.example.com', 'third.example.com'] as $domain) {
        $moodle = $home . '/' . $domain . '/moodle';
        mkdir($moodle, 0755, true);
        file_put_contents($moodle . '/config.php', $configTemplate);
    }
    $firstAvailable = $available . '/first.example.com.conf';
    file_put_contents($firstAvailable, str_replace('{{DOMAIN}}', 'first.example.com', $nginxTemplate));
    symlink($firstAvailable, $enabled . '/first.example.com.conf');
    file_put_contents($enabled . '/second.example.com.conf', str_replace('{{DOMAIN}}', 'second.example.com', $nginxTemplate));
    file_put_contents($enabled . '/admin.moodle.conf', 'Admin panel configuration');
    $secondConfig = $home . '/second.example.com/moodle/config.php';
    file_put_contents($secondConfig, str_replace('    $CFG->debug = 30719;', '    $CFG->debug = 32767;', $configTemplate));

    $summary = Moodle261Migration::run($options);
    ok($summary['sites'] === 3, 'all installed Moodle sites were discovered');
    ok($summary['config_updated'] === 2 && $summary['config_unchanged'] === 1, 'only old debug values were updated');
    ok($summary['nginx_updated'] === 2 && $summary['nginx_missing'] === 1, 'only existing managed NGINX sites were updated');
    ok($validateCalls === 1 && $reloadCalls === 1, 'NGINX tested and reloaded once');
    ok(is_link($enabled . '/first.example.com.conf'), 'sites-enabled symlink preserved');
    ok(file_get_contents($enabled . '/admin.moodle.conf') === 'Admin panel configuration', 'admin vhost preserved');

    $firstConfig = file_get_contents($home . '/first.example.com/moodle/config.php');
    ok(str_contains($firstConfig, '$CFG->debug = 32767;'), 'debug.enable value changed to 32767');
    ok(substr_count($firstConfig, '$CFG->debug = 30719;') === 1, 'custom debugging outside switch preserved');
    foreach (['first.example.com', 'second.example.com'] as $domain) {
        $content = file_get_contents($enabled . '/' . $domain . '.conf');
        ok(substr_count($content, 'location = /theme/styles_debug.php') === 1, $domain . ': one no-cache debug location');
        ok(!str_contains($content, 'location /theme/styles.php') && !str_contains($content, 'location /theme/jquery.php')
            && !str_contains($content, 'location /theme/yui_combo.php'), $domain . ': old targeted caches removed');
        ok(str_contains($content, 'ssl_certificate /etc/letsencrypt/live/' . $domain), $domain . ': SSL retained');
        ok(str_contains($content, 'proxy_cache             off;'), $domain . ': cache disabled for debug');
    }
    ok(is_file($summary['backups'] . '/moodle/first.example.com-config.php'), 'original Moodle config backed up');
    ok(is_file($summary['backups'] . '/nginx/first.example.com.conf'), 'original NGINX config backed up');
    ok((fileperms($summary['backups'] . '/moodle/first.example.com-config.php') & 0777) === 0600, 'config backup is private');

    $again = Moodle261Migration::run($options);
    ok($again['config_updated'] === 0 && $again['nginx_updated'] === 0, 'migration is idempotent');
    ok($reloadCalls === 1 && $validateCalls === 1, 'idempotent run has no NGINX reload');

    // New config is rolled back when the NGINX config validation fails.
    $original = str_replace('{{DOMAIN}}', 'second.example.com', $nginxTemplate);
    file_put_contents($enabled . '/second.example.com.conf', $original);
    $failValidate = $options;
    $failValidate['validate'] = static function(): void { throw new RuntimeException('NGINX configtest failed'); };
    try {
        Moodle261Migration::run($failValidate);
        throw new RuntimeException('FAIL: expected config validation failure');
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'configtest failed'), 'NGINX validation failure propagates');
    }
    ok(file_get_contents($enabled . '/second.example.com.conf') === $original, 'validation failure restores NGINX config');

    // Reload failure restores files and reloads the original configuration.
    $reloadFailureCount = 0;
    $failReload = $options;
    $failReload['reload'] = static function() use (&$reloadFailureCount): void {
        if (++$reloadFailureCount === 1) {
            throw new RuntimeException('NGINX reload failed');
        }
    };
    try {
        Moodle261Migration::run($failReload);
        throw new RuntimeException('FAIL: expected reload failure');
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'reload failed'), 'NGINX reload failure propagates');
    }
    ok($reloadFailureCount === 2, 'original NGINX config reloaded following rollback');
    ok(file_get_contents($enabled . '/second.example.com.conf') === $original, 'reload failure restores NGINX config');

    // A custom cache block must fail without touching any Moodle config.
    $custom = str_replace('        include                 /etc/nginx/util/cache.conf;',
        '        proxy_pass http://some-custom-origin;', $original);
    file_put_contents($enabled . '/second.example.com.conf', $custom);
    $configBefore = file_get_contents($home . '/third.example.com/moodle/config.php');
    try {
        Moodle261Migration::run($options);
        throw new RuntimeException('FAIL: expected custom site rejection');
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'Custom legacy theme cache block'), 'modified legacy cache rule requires review');
    }
    ok(file_get_contents($enabled . '/second.example.com.conf') === $custom, 'custom NGINX config not overwritten');
    ok(file_get_contents($home . '/third.example.com/moodle/config.php') === $configBefore, 'planning failure made no changes');

    echo "ALL TESTS PASSED\n";
} finally {
    removeTree($root);
}

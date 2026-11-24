<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/app/Moodle262Migration.php';

use app\Moodle262Migration;

function check262(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

function cleanup262(string $path): void {
    if (is_file($path) || is_link($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                cleanup262($path . '/' . $file);
            }
        }
        rmdir($path);
    }
}

$root = sys_get_temp_dir() . '/mfi-262-' . bin2hex(random_bytes(5));
$home = $root . '/home';
$enabled = $root . '/apache/sites-enabled';
$available = $root . '/apache/sites-available';
$validations = 0;
$reloads = 0;
$config = <<<'CONF'
<?php
$CFG->wwwroot = 'https://{{DOMAIN}}';
$CFG->routerconfigured = true;
// Custom setting, preserve it.
$CFG->debug = 32767;
require_once(__DIR__ . '/public/lib/setup.php');
CONF;
$host = <<<'APACHE'
<VirtualHost *:8080>
    ServerName {{DOMAIN}}
    DocumentRoot {{ROOT}}/moodle/public
    <Directory {{ROOT}}/moodle>
        Require all denied
    </Directory>
    <Directory {{ROOT}}/moodle/public>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
        FallbackResource /r.php
    </Directory>
    # Preserve custom vhost changes.
</VirtualHost>
<VirtualHost *:443>
    ServerName {{DOMAIN}}
    DocumentRoot {{ROOT}}/moodle/public
    <Directory {{ROOT}}/moodle/public>
        Require all granted
        FallbackResource /r.php
    </Directory>
</VirtualHost>
APACHE;
$options = [
    'home' => $home,
    'apache' => $enabled,
    'backups' => $root . '/backups',
    'logger' => static function(string $message): void {},
    'validate' => static function() use (&$validations): void { ++$validations; },
    'reload' => static function() use (&$reloads): void { ++$reloads; },
];
try {
    mkdir($enabled, 0755, true);
    mkdir($available, 0755, true);
    foreach (['alpha.example.org', 'beta.example.org', 'gamma.example.org'] as $domain) {
        $prefix = $domain === 'gamma.example.org' ? $home . '/tenant' : $home;
        $site = $prefix . '/' . $domain;
        mkdir($site . '/moodle', 0755, true);
        $value = str_replace('{{DOMAIN}}', $domain, $config);
        if ($domain === 'beta.example.org') {
            $value = str_replace('$CFG->routerconfigured = true;', '', $value);
        }
        file_put_contents($site . '/moodle/config.php', $value);
        if ($domain !== 'gamma.example.org') {
            $vhost = str_replace(['{{DOMAIN}}', '{{ROOT}}'], [$domain, $site], $host);
            if ($domain === 'alpha.example.org') {
                file_put_contents($available . '/' . $domain . '.conf', $vhost);
                symlink($available . '/' . $domain . '.conf', $enabled . '/' . $domain . '.conf');
            } else {
                file_put_contents($enabled . '/' . $domain . '.conf', $vhost);
            }
        }
    }
    file_put_contents($enabled . '/admin.moodle.conf', 'Panel configuration');

    // Incomplete installations must not abort valid migrations or change their vhosts.
    $ignored = [
        'a-empty.example.org' => '',
        'b-placeholder.example.org' => "<?php\n",
        'c-missing.example.org' => null,
    ];
    foreach ($ignored as $domain => $contents) {
        $site = $home . '/' . $domain;
        mkdir($site . '/moodle', 0755, true);
        if ($contents !== null) {
            file_put_contents($site . '/moodle/config.php', $contents);
        }
        file_put_contents($enabled . '/' . $domain . '.conf',
            str_replace(['{{DOMAIN}}', '{{ROOT}}'], [$domain, $site], $host));
    }
    $messages = [];
    $options['logger'] = static function(string $message) use (&$messages): void { $messages[] = $message; };

    $summary = Moodle262Migration::run($options);
    check262($summary['config_skipped'] === 2, 'skips empty and unrecognized configs without aborting');
    foreach ($ignored as $domain => $contents) {
        $site = $home . '/' . $domain;
        $path = $site . '/moodle/config.php';
        check262($contents === null ? !file_exists($path) : file_get_contents($path) === $contents,
            $domain . ': skipped config stays untouched');
        check262(file_get_contents($enabled . '/' . $domain . '.conf') ===
            str_replace(['{{DOMAIN}}', '{{ROOT}}'], [$domain, $site], $host),
            $domain . ': skipped Apache vhost stays untouched');
        if ($contents !== null) {
            check262(in_array('WARNING: Skipping unrecognized Moodle config.php: ' . $path, $messages, true),
                $domain . ': warning identifies skipped config');
        }
    }

    check262($summary['sites'] === 3, 'finds Moodle installations recursively under /home');
    check262($summary['config_updated'] === 3, 'updates explicit and missing router switches');
    check262($summary['apache_updated'] === 2 && $summary['apache_missing'] === 1, 'updates Apache only for installed managed sites');
    check262($validations === 1 && $reloads === 1, 'validates and reloads Apache once');
    check262(is_link($enabled . '/alpha.example.org.conf'), 'preserves sites-enabled symlink');
    check262(file_get_contents($enabled . '/admin.moodle.conf') === 'Panel configuration', 'does not touch admin host');
    foreach (['alpha.example.org', 'beta.example.org', 'gamma.example.org'] as $domain) {
        $prefix = $domain === 'gamma.example.org' ? $home . '/tenant' : $home;
        $updated = file_get_contents($prefix . '/' . $domain . '/moodle/config.php');
        check262(substr_count($updated, '$CFG->routerconfigured = false;') === 1, $domain . ': one disabled routing directive');
        check262(str_contains($updated, '$CFG->debug = 32767;'), $domain . ': custom config preserved');
    }
    foreach (['alpha.example.org', 'beta.example.org'] as $domain) {
        $updated = file_get_contents($enabled . '/' . $domain . '.conf');
        check262(substr_count($updated, 'FallbackResource /r.php') === 1, $domain . ': removes HTTP fallback but keeps HTTPS fallback');
        check262(str_contains($updated, '# Preserve custom vhost changes.'), $domain . ': other directives preserved');
    }
    check262(is_file($summary['backups'] . '/moodle/alpha.example.org-config.php'), 'creates original config backup');
    check262((fileperms($summary['backups'] . '/moodle/alpha.example.org-config.php') & 0777) === 0600, 'private config backup');
    check262(is_file($summary['backups'] . '/apache/alpha.example.org.conf'), 'backs up Apache vhost');

    $again = Moodle262Migration::run($options);
    check262($again['config_updated'] === 0 && $again['apache_updated'] === 0, 'migration is idempotent');
    check262($validations === 1 && $reloads === 1, 'no Apache reload for idempotent run');

    $old = str_replace(['{{DOMAIN}}', '{{ROOT}}'], ['beta.example.org', $home . '/beta.example.org'], $host);
    file_put_contents($enabled . '/beta.example.org.conf', $old);
    $failure = $options;
    $failure['validate'] = static function(): void { throw new RuntimeException('Apache configtest failed'); };
    try {
        Moodle262Migration::run($failure);
        throw new RuntimeException('FAIL: expected validator failure');
    } catch (RuntimeException $e) {
        check262(str_contains($e->getMessage(), 'configtest failed'), 'validation failure propagated');
    }
    check262(file_get_contents($enabled . '/beta.example.org.conf') === $old, 'restores Apache vhost after failed validation');

    $reloadAttempt = 0;
    $failure = $options;
    $failure['reload'] = static function() use (&$reloadAttempt): void {
        if (++$reloadAttempt === 1) {
            throw new RuntimeException('Apache reload failed');
        }
    };
    try {
        Moodle262Migration::run($failure);
        throw new RuntimeException('FAIL: expected reload failure');
    } catch (RuntimeException $e) {
        check262(str_contains($e->getMessage(), 'reload failed'), 'reload failure propagated');
    }
    check262($reloadAttempt === 2, 'reloads original Apache configuration after rollback');
    check262(file_get_contents($enabled . '/beta.example.org.conf') === $old, 'restores Apache vhost after failed reload');

    $custom = str_replace('FallbackResource /r.php', 'FallbackResource /my-router.php', $old);
    $customUpdated = Moodle262Migration::patchApache($custom, 'beta.example.org', $home . '/beta.example.org/moodle');
    check262($customUpdated === $custom, 'preserves custom fallback');
    $unrelated = str_replace('ServerName beta.example.org', 'ServerName other.example.org', $old);
    check262(Moodle262Migration::patchApache($unrelated, 'beta.example.org', $home . '/beta.example.org/moodle') === $unrelated,
        'does not modify unrelated Apache virtual hosts');
    $unusual = str_replace('$CFG->routerconfigured = true;', '$CFG->routerconfigured = custom_routing();', $config);
    try {
        Moodle262Migration::patchMoodleConfig($unusual);
        throw new RuntimeException('FAIL: expected custom router value rejection');
    } catch (RuntimeException $e) {
        check262(str_contains($e->getMessage(), 'requires review'), 'custom router values require review');
    }
    echo "ALL TESTS PASSED\n";
} finally {
    cleanup262($root);
}

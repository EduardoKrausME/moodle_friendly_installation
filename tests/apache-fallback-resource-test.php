<?php

require_once __DIR__ . '/../public/app/ApacheFallbackResourceUpdater.php';

use app\ApacheFallbackResourceUpdater as Updater;

function verify(bool $value, string $description): void {
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . "\n";
}

$original = <<<'CONF'
<VirtualHost *:8080>
    ServerName example.org
    DocumentRoot /home/example.org/moodle/public
    <Directory /home/example.org/moodle>
        Require all denied
    </Directory>
    <Directory /home/example.org/moodle/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
CONF;

$fixed = Updater::addFallbackResource($original);
verify(substr_count($fixed, 'FallbackResource /r.php') === 1, 'adds one fallback to old virtual host');
verify(strpos($fixed, "Require all granted\n        FallbackResource /r.php\n    </Directory>") !== false, 'adds fallback only to public webroot Directory');
verify(Updater::addFallbackResource($fixed) === $fixed, 'idempotent when migrated');

$legacy = str_replace('/moodle/public', '/moodle', $original);
$legacy = str_replace('    <Directory /home/example.org/moodle>\n        Require all denied\n    </Directory>', '', $legacy);
verify(substr_count(Updater::addFallbackResource($legacy), 'FallbackResource /r.php') === 1, 'supports legacy Moodle document root');
verify(Updater::addFallbackResource(str_replace('*:8080', '*:443', $original)) === str_replace('*:8080', '*:443', $original), 'does not touch HTTPS vhosts');
verify(Updater::addFallbackResource(str_replace('/home/example.org/moodle/public', '/home/admin.moodle/public', $original)) === str_replace('/home/example.org/moodle/public', '/home/admin.moodle/public', $original), 'does not touch panel webroot');
verify(Updater::addFallbackResource(str_replace('<Directory /home/example.org/moodle/public>', '<Directory /srv/other>', $original)) === str_replace('<Directory /home/example.org/moodle/public>', '<Directory /srv/other>', $original), 'does not add a fallback to an unrelated directory');
$withExisting = str_replace('Require all granted', 'Require all granted' . "\n        FallbackResource /custom.php", $original);
verify(Updater::addFallbackResource($withExisting) === $withExisting, 'preserves existing custom fallback');
$withComment = str_replace('Require all granted', 'Require all granted' . "\n        # FallbackResource /r.php", $original);
verify(substr_count(Updater::addFallbackResource($withComment), 'FallbackResource /r.php') === 2, 'ignores commented-out fallback');
$multi = $original . "\n" . str_replace('example.org', 'second.org', $original);
verify(substr_count(Updater::addFallbackResource($multi), 'FallbackResource /r.php') === 2, 'updates all virtual hosts in a shared config file');
$quoted = str_replace('/home/example.org/moodle/public', '"/home/example.org/moodle/public"', $original);
verify(substr_count(Updater::addFallbackResource($quoted), 'FallbackResource /r.php') === 1, 'supports quoted Apache paths');
$crlf = str_replace("\n", "\r\n", $original);
verify(str_contains(Updater::addFallbackResource($crlf), "FallbackResource /r.php\r\n"), 'preserves CRLF line endings');

$base = sys_get_temp_dir() . '/apache-fallback-' . bin2hex(random_bytes(6));
mkdir($base);
mkdir($base . '/available');
mkdir($base . '/enabled');
file_put_contents($base . '/available/example.org.conf', $original);
symlink($base . '/available/example.org.conf', $base . '/enabled/example.org.conf');
file_put_contents($base . '/enabled/second.org.conf', str_replace('example.org', 'second.org', $original));
file_put_contents($base . '/enabled/admin.moodle.conf', $original);
file_put_contents($base . '/enabled/unrelated.conf', str_replace('*:8080', '*:443', $original));
$count = 0;
$updated = Updater::run([$base . '/enabled'], function() use (&$count) {$count++;}, function() use (&$count) {$count++;});
verify(count($updated) === 2 && $count === 2, 'updates all enabled sites and validates/reloads once');
verify(is_link($base . '/enabled/example.org.conf'), 'preserves Apache sites-enabled symlinks');
verify(substr_count(file_get_contents($base . '/enabled/second.org.conf'), 'FallbackResource /r.php') === 1, 'updates additional managed sites');
verify(file_get_contents($base . '/enabled/admin.moodle.conf') === $original, 'skips panel configuration');
verify(file_get_contents($base . '/enabled/unrelated.conf') === str_replace('*:8080', '*:443', $original), 'skips unrelated virtual hosts');
$rerun = Updater::run([$base . '/enabled'], function() {throw new RuntimeException('should not validate');}, function() {throw new RuntimeException('should not reload');});
verify(count($rerun) === 0, 'does not reload if already migrated');
file_put_contents($base . '/available/example.org.conf', $original);
try {
    Updater::run([$base . '/enabled'], function() {throw new RuntimeException('invalid Apache');}, function() {});
    throw new RuntimeException('FAIL: validation should throw');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'invalid Apache', 'propagates Apache validation failures');
}
verify(file_get_contents($base . '/available/example.org.conf') === $original, 'rolls back after failed Apache validation');
$reloadruns = 0;
try {
    Updater::run([$base . '/enabled'], function() {}, function() use (&$reloadruns) {
        if (++$reloadruns === 1) {
            throw new RuntimeException('reload failure');
        }
    });
    throw new RuntimeException('FAIL: reload should throw');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'reload failure' && $reloadruns === 2, 'restores active Apache config after failed reload');
}
verify(file_get_contents($base . '/available/example.org.conf') === $original, 'rolls back after failed Apache reload');
unlink($base . '/enabled/example.org.conf');
unlink($base . '/enabled/admin.moodle.conf');
unlink($base . '/enabled/second.org.conf');
unlink($base . '/enabled/unrelated.conf');
unlink($base . '/available/example.org.conf');
rmdir($base . '/available');
rmdir($base . '/enabled');
rmdir($base);
echo 'ALL TESTS PASSED' . "\n";

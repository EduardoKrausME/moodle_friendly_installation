<?php

require_once __DIR__ . '/../public/app/PhpFpmSocketResolver.php';
require_once __DIR__ . '/../public/app/ApachePhpFpmSocketUpdater.php';

use app\PhpFpmSocketResolver as Resolver;
use app\ApachePhpFpmSocketUpdater as Updater;

function verifySocket(bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

$series = Resolver::phpSeries(PHP_BINARY);
verifySocket((bool)preg_match('/^\d+\.\d+$/', $series), 'reads configured PHP CLI version');
verifySocket(Resolver::expectedPath('8.4', 'debian') === '/run/php/php8.4-fpm.sock', 'Debian socket is versioned');
verifySocket(Resolver::expectedPath('8.4', 'redhat') === '/run/php-fpm/www.sock', 'Red Hat socket is unchanged');

$old = <<<'CONF'
<VirtualHost *:8080>
    ServerName sample.example.org
    DocumentRoot /home/sample.example.org/moodle/public
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
CONF;
$socket = '/run/php/php8.4-fpm.sock';
$fixed = Updater::replaceLegacySocket($old, $socket);
verifySocket(str_contains($fixed, "proxy:unix:{$socket}|fcgi://localhost/"), 'replaces Moodle FPM socket');
verifySocket(Updater::replaceLegacySocket($fixed, $socket) === $fixed, 'migration is idempotent');
verifySocket(Updater::replaceLegacySocket(str_replace('*:8080', '*:443', $old), $socket) === str_replace('*:8080', '*:443', $old), 'ignores HTTPS vhosts');
verifySocket(Updater::replaceLegacySocket(str_replace('/moodle/public', '/application', $old), $socket) === str_replace('/moodle/public', '/application', $old), 'ignores unrelated applications');

$base = sys_get_temp_dir() . '/php-fpm-socket-' . bin2hex(random_bytes(6));
mkdir($base);
mkdir($base . '/available');
mkdir($base . '/enabled');
file_put_contents($base . '/available/site.conf', $old);
symlink($base . '/available/site.conf', $base . '/enabled/site.conf');
file_put_contents($base . '/enabled/admin.moodle.conf', $old);
$validations = 0;
$reloads = 0;
$result = Updater::run([$base . '/enabled'], function() use (&$validations) {$validations++;}, function() use (&$reloads) {$reloads++;}, $socket);
verifySocket(count($result) === 1 && $validations === 1 && $reloads === 1, 'updates once and reloads once');
verifySocket(is_link($base . '/enabled/site.conf'), 'keeps Debian enabled-site symlink');
verifySocket(file_get_contents($base . '/enabled/admin.moodle.conf') === $old, 'never modifies panel vhost');
verifySocket(Updater::run([$base . '/enabled'], function() {throw new RuntimeException('unexpected validation');}, function() {throw new RuntimeException('unexpected reload');}, $socket) === [], 'no-op after migration');

file_put_contents($base . '/available/site.conf', $old);
try {
    Updater::run([$base . '/enabled'], function() {throw new RuntimeException('invalid configuration');}, function() {}, $socket);
    throw new RuntimeException('expected Apache validation error');
} catch (RuntimeException $error) {
    verifySocket($error->getMessage() === 'invalid configuration', 'propagates validation failure');
}
verifySocket(file_get_contents($base . '/available/site.conf') === $old, 'restores original config after failure');
unlink($base . '/enabled/site.conf');
unlink($base . '/enabled/admin.moodle.conf');
unlink($base . '/available/site.conf');
rmdir($base . '/available');
rmdir($base . '/enabled');
rmdir($base);
echo 'ALL TESTS PASSED' . "\n";

<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/app/Moodle260Migration.php';

use app\Moodle260Migration;

$root = sys_get_temp_dir() . '/moodle260-composer-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
mkdir($root . '/bin', 0700);
mkdir($root . '/moodle', 0700);
file_put_contents($root . '/moodle/composer.json', '{}');
$tool = $root . '/bin/composer';
$log = $root . '/composer.log';
$oldpath = getenv('PATH');
try {
    file_put_contents($tool, <<<'SH'
#!/usr/bin/env bash
printf 'DIR=%s ARGS=%s SUPERUSER=%s\n' "$PWD" "$*" "$COMPOSER_ALLOW_SUPERUSER"
exit "${MOCK_COMPOSER_EXIT:-0}"
SH
    );
    chmod($tool, 0755);
    putenv('PATH=' . $root . '/bin:' . $oldpath);
    $method = new ReflectionMethod(Moodle260Migration::class, 'installComposer');
    $result = $method->invoke(null, $root . '/moodle', 'example.com', $log);
    if ($result !== 0) {throw new RuntimeException('unexpected success code ' . $result);}
    $text = file_get_contents($log);
    foreach (['DIR=' . $root . '/moodle', 'install --no-dev --classmap-authoritative --no-interaction', 'SUPERUSER=1'] as $expected) {
        if (!str_contains($text, $expected)) {throw new RuntimeException('missing: ' . $expected . ' in ' . $text);}
    }
    echo "PASS: subprocess runs in Moodle root with requested Composer flags and logs output\n";
    putenv('MOCK_COMPOSER_EXIT=37');
    $result = $method->invoke(null, $root . '/moodle', 'example.com', $log);
    if ($result !== 37) {throw new RuntimeException('unexpected failure code ' . $result);}
    echo "PASS: subprocess returns the real nonzero code\n";
    unlink($root . '/moodle/composer.json');
    try {
        $method->invoke(null, $root . '/moodle', 'example.com', $log);
        throw new RuntimeException('expected missing composer.json');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'composer.json not found') { throw $e; }
    }
    echo "PASS: missing composer.json results in warning-capable exception\n";
    echo "ALL COMPOSER TESTS PASSED\n";
} finally {
    putenv('PATH=' . $oldpath);
    putenv('MOCK_COMPOSER_EXIT');
    @unlink($log);
    @unlink($tool);
    @unlink($root . '/moodle/composer.json');
    @rmdir($root . '/moodle');
    @rmdir($root . '/bin');
    @rmdir($root);
}

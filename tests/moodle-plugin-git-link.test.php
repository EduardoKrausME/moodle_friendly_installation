<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/app/MoodlePluginGitLinker.php';
require_once __DIR__ . '/../public/app/MoodlePluginManager.php';

use app\MoodlePluginGitLinker;
use app\MoodlePluginManager;

function gitTestAssert(bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function gitTestRun(array $command): string {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run git fixture command.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException("Fixture command failed: " . implode(' ', $command) . ': ' . trim($output));
    }
    return trim($output);
}

function gitTestDelete(string $path): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                gitTestDelete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

$root = sys_get_temp_dir() . '/mfi-git-link-' . bin2hex(random_bytes(5));
$source = $root . '/repo';
$ftp = $root . '/home/example.org/moodle/public/theme/learnlingo';
$second = $root . '/home/second.org/moodle/public/theme/learnlingo';
$invalid = $root . '/home/invalid.org/moodle/public/theme/learnlingo';
$component = "theme_learnlingo";
$remote = ['clone_url' => $source, 'branch' => 'master', 'component' => $component];
try {
    mkdir($source, 0755, true);
    gitTestRun(['git', 'init', '-b', 'master', $source]);
    file_put_contents($source . '/version.php', "<?php\n\$plugin->component = 'theme_learnlingo';\n\$plugin->version = 2026111500;\n");
    file_put_contents($source . '/styles.css', "original\n");
    gitTestRun(['git', '-C', $source, 'add', '.']);
    gitTestRun(['git', '-C', $source, '-c', 'user.name=CI Tester', '-c', 'user.email=ci@example.org',
        'commit', '-m', 'Create plugin fixture']);

    mkdir($ftp, 0755, true);
    copy($source . '/version.php', $ftp . '/version.php');
    file_put_contents($ftp . '/styles.css', "custom FTP styles\n");
    file_put_contents($ftp . '/local-only.php', "<?php // Keep custom file\n");
    $site = ['webroot' => dirname(dirname($ftp))];
    $local = MoodlePluginManager::localInstallation($site, $component);
    gitTestAssert($local !== null && !$local['has_git'], 'FTP plugin is detected even without .git');
    $linked = MoodlePluginGitLinker::attach($ftp, $remote, true);
    gitTestAssert($linked['created'] && $linked['tracked_changes'], 'FTP plugin is linked with local changes detected');
    gitTestAssert(is_dir($ftp . '/.git'), 'Git metadata was created');
    gitTestAssert(file_get_contents($ftp . '/styles.css') === "custom FTP styles\n", 'Existing FTP files are unchanged');
    gitTestAssert(is_file($ftp . '/local-only.php'), 'Untracked FTP files remain untouched');
    gitTestAssert(gitTestRun(['git', '-C', $ftp, 'remote', 'get-url', 'origin']) === $source, 'Remote Git origin configured');
    gitTestAssert(gitTestRun(['git', '-C', $ftp, 'branch', '--show-current']) === 'master', 'Current branch configured');
    gitTestAssert(MoodlePluginManager::localInstallation($site, $component)['has_git'], 'Previously FTP plugin now shows as Git linked');
    gitTestAssert(str_contains(gitTestRun(['git', '-C', $ftp, 'status', '--short']), 'styles.css'),
        'Git sees modified FTP files instead of replacing them');

    mkdir($second, 0755, true);
    copy($source . '/version.php', $second . '/version.php');
    copy($source . '/styles.css', $second . '/styles.css');
    $clean = MoodlePluginGitLinker::attach($second, $remote, true);
    gitTestAssert(!$clean['tracked_changes'], 'Identical FTP plugin is linked as a clean checkout');
    gitTestAssert(file_get_contents($second . '/styles.css') === "original\n", 'Clean plugin source preserved');

    mkdir($invalid, 0755, true);
    copy($source . '/version.php', $invalid . '/version.php');
    file_put_contents($invalid . '/styles.css', "Do not delete\n");
    $wrong = ['clone_url' => $source, 'branch' => 'master', 'component' => 'theme_other'];
    try {
        MoodlePluginGitLinker::attach($invalid, $wrong, true);
        throw new RuntimeException('FAIL: Component mismatch must fail.');
    } catch (RuntimeException $e) {
        gitTestAssert(str_contains($e->getMessage(), 'não pertence'), 'Rejects remote plugin component mismatch');
    }
    gitTestAssert(!file_exists($invalid . '/.git'), 'Failure removes only newly created .git metadata');
    gitTestAssert(file_get_contents($invalid . '/styles.css') === "Do not delete\n", 'Failed link preserves FTP source');

    gitTestRun(['git', '-C', $second, 'remote', 'set-url', 'origin', $invalid]);
    MoodlePluginGitLinker::attach($second, $remote, true);
    gitTestAssert(gitTestRun(['git', '-C', $second, 'remote', 'get-url', 'origin']) === $source,
        'Existing Git plugin remote can be repaired');
    gitTestAssert(file_get_contents($second . '/styles.css') === "original\n", 'Repair preserves working tree');

    file_put_contents($second . '/styles.css', "manual change\n");
    $repaired = MoodlePluginGitLinker::attach($second, $remote, true);
    gitTestAssert($repaired['tracked_changes'], 'Repair identifies existing local changes');
    gitTestAssert(file_get_contents($second . '/styles.css') === "manual change\n", 'Repair never resets local files');

    echo "ALL TESTS PASSED\n";
} finally {
    gitTestDelete($root);
}

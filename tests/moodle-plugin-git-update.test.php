<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/app/MoodlePluginGitUpdater.php';
require_once __DIR__ . '/../public/app/MoodlePluginGitLinker.php';

use app\MoodlePluginGitUpdater;
use app\MoodlePluginGitLinker;

function verify(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: $message\n";
}
function git(string $path, string $args): string {
    exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException(implode("\n", $out));
    }
    return trim(implode("\n", $out));
}
$root = sys_get_temp_dir() . '/mfi-update-' . bin2hex(random_bytes(6));
$source = $root . '/source';
$web = $root . '/public';
$path = $web . '/plugin';
$backups = $root . '/backups';
mkdir($source, 0700, true);
mkdir($web, 0700);
try {
    git($source, 'init -b master');
    git($source, 'config user.name Tester');
    git($source, 'config user.email tester@example.org');
    file_put_contents($source . '/version.php', "<?php\n\$plugin->component = 'local_test';\n\$plugin->version = 1;\n");
    file_put_contents($source . '/code.php', "original\n");
    file_put_contents($source . '/.gitignore', "ignored.php\n");
    git($source, 'add .');
    git($source, 'commit -m initial');
    git($source, 'clone . ' . escapeshellarg($path));
    chmod($path . '/code.php', 0755);
    file_put_contents($path . '/extra.php', "keep\n");
    file_put_contents($source . '/code.php', "remote\n");
    git($source, 'commit -am second');
    git($path, 'fetch origin master');
    $commit = git($path, 'rev-parse FETCH_HEAD');
    $result = MoodlePluginGitUpdater::update($path, $commit, $backups, [$web]);
    verify(!$result['confirmation_required'] && $result['backup'] === '', 'Mode-only changes and harmless extra files do not block');
    verify(file_get_contents($path . '/extra.php') === "keep\n", 'Extra file preserved');
    verify(file_get_contents($path . '/code.php') === "remote\n", 'Remote code applied');

    file_put_contents($path . '/code.php', "custom\n");
    file_put_contents($path . '/ignored.php', "local ignored\n");
    mkdir($path . '/collision');
    file_put_contents($path . '/collision/local.php', "local nested\n");
    file_put_contents($source . '/code.php', "third\n");
    file_put_contents($source . '/ignored.php', "remote ignored\n");
    file_put_contents($source . '/collision', "remote file\n");
    git($source, 'add -f ignored.php collision code.php');
    git($source, 'commit -m third');
    git($path, 'fetch origin master');
    $commit = git($path, 'rev-parse FETCH_HEAD');
    $preview = MoodlePluginGitUpdater::update($path, $commit, $backups, [$web]);
    verify($preview['confirmation_required'] && count($preview['changes']) === 3, 'Tracked, ignored and directory conflicts require confirmation');
    verify(!is_dir($backups) && file_get_contents($path . '/code.php') === "custom\n", 'Preview changes no files');
    file_put_contents($path . '/code.php', "new custom\n");
    $stale = MoodlePluginGitUpdater::update($path, $commit, $backups, [$web], $preview['fingerprint']);
    verify($stale['confirmation_required'] && $stale['stale'], 'Changed content invalidates confirmation');
    try {
        MoodlePluginGitUpdater::update($path, $commit, $web . '/backups', [$web], $stale['fingerprint']);
        throw new RuntimeException('Backup inside webroot was allowed');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'fora dos'), 'Rejects backup inside webroot');
    }
    verify(file_get_contents($path . '/code.php') === "new custom\n", 'Failed backup never resets plugin');
    $result = MoodlePluginGitUpdater::update($path, $commit, $backups, [$web], $stale['fingerprint']);
    verify(!$result['confirmation_required'] && is_file($result['backup']), 'Confirmed update creates backup');
    verify((fileperms($result['backup']) & 0777) === 0600, 'Backup permissions are private');
    $archive = escapeshellarg($result['backup']);
    verify(shell_exec('tar -xOzf ' . $archive . ' ./code.php') === "new custom\n", 'Backup contains original tracked content');
    verify(shell_exec('tar -xOzf ' . $archive . ' ./ignored.php') === "local ignored\n", 'Backup contains original ignored content');
    verify(shell_exec('tar -xOzf ' . $archive . ' ./collision/local.php') === "local nested\n", 'Backup contains conflicting nested file');
    verify(file_get_contents($path . '/extra.php') === "keep\n", 'Harmless extra file still preserved');
    verify(file_get_contents($path . '/collision') === "remote file\n", 'Directory collision replaced after backup');

    $ftp = $web . '/ftp';
    mkdir($ftp);
    file_put_contents($ftp . '/version.php', "<?php\n\$plugin->component = 'local_test';\n\$plugin->version = 1;\n");
    file_put_contents($ftp . '/code.php', "old FTP version\n");
    MoodlePluginGitLinker::attach($ftp, ['clone_url' => $source, 'branch' => 'master', 'component' => 'local_test'], true);
    $preview = MoodlePluginGitUpdater::update($ftp, $commit, $backups, [$web]);
    verify($preview['confirmation_required'], 'Previously FTP-installed plugin can be previewed');
    $result = MoodlePluginGitUpdater::update($ftp, $commit, $backups, [$web], $preview['fingerprint']);
    verify(is_file($result['backup']) && file_get_contents($ftp . '/code.php') === "third\n", 'FTP differences no longer permanently block updates');
    $nobackup = $root . '/must-not-exist';
    file_put_contents($ftp . '/code.php', "discard this\n");
    file_put_contents($ftp . '/local-extra.php', "keep this\n");
    try {
        MoodlePluginGitUpdater::update($ftp, $commit, $nobackup, [$web], null, true);
        throw new RuntimeException('Missing confirmation was accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'confirmação explícita'), 'No backup requires explicit confirmation');
    }
    $preview = MoodlePluginGitUpdater::update($ftp, $commit, $nobackup, [$web]);
    file_put_contents($ftp . '/code.php', "changed after preview\n");
    $stale = MoodlePluginGitUpdater::update($ftp, $commit, $nobackup, [$web], $preview['fingerprint'], true);
    verify($stale['confirmation_required'] && $stale['stale'], 'No-backup choice still rejects stale confirmation');
    verify(file_get_contents($ftp . '/code.php') === "changed after preview\n", 'Stale confirmation leaves files untouched');
    $result = MoodlePluginGitUpdater::update($ftp, $commit, $nobackup, [$web], $stale['fingerprint'], true);
    verify(!$result['confirmation_required'] && $result['backup'] === '' && !file_exists($nobackup), 'Explicit no-backup update does not create backup');
    verify(file_get_contents($ftp . '/code.php') === "third\n", 'No-backup update applies remote code');
    verify(file_get_contents($ftp . '/local-extra.php') === "keep this\n", 'No-backup update preserves non-conflicting extra files');

    $template = file_get_contents(__DIR__ . '/../public/app/templates/page/moodle-plugins.mustache');
    $controller = file_get_contents(__DIR__ . '/../public/moodle_plugins.php');
    verify(str_contains($template, 'hero-card inverse plugin-update-confirmation'), 'Confirmation card uses vertical layout');
    verify(str_contains($template, 'name="action" value="confirm_plugin_update_without_backup"')
        && str_contains($template, 'name="action" value="confirm_plugin_update"')
        && str_contains($template, 'name="action" value="cancel_plugin_update"'), 'All three actions are present');
    verify(str_contains($controller, 'MoodlePluginManager::update($site, $component, $confirmation, $withoutbackup)')
        && str_contains($controller, 'JobManager::createPluginUpgradeJob($site, $result["component"])'), 'Both confirmation paths share upgrade scheduling');
    echo "ALL TESTS PASSED\n";
} finally {
    exec('rm -rf -- ' . escapeshellarg($root));
}

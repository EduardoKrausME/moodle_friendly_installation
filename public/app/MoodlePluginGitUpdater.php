<?php

declare(strict_types=1);

namespace app;

use RuntimeException;

/**
 * Safely update an existing plugin checkout, including previously FTP-installed plugins.
 * The caller must validate the component and pin the fetched remote commit first.
 */
final class MoodlePluginGitUpdater {
    /**
     * Return a confirmation preview or apply the pinned commit. Never discard local
     * content without an explicit, matching confirmation. Back up by default unless explicitly waived.
     */
    public static function update(string $path, string $commit, string $backuproot,
        array $webroots, ?string $confirmation = null, bool $withoutbackup = false): array {
        if ($withoutbackup && $confirmation === null) {
            throw new RuntimeException('Atualizar sem backup exige confirmação explícita.');
        }
        if (!preg_match('/^[0-9a-f]{40,64}$/D', $commit) || realpath($path) !== $path
            || !is_dir($path . '/.git') || is_link($path . '/.git')) {
            throw new RuntimeException('Diretório ou commit Git inválido.');
        }
        $git = ['git', '-c', 'safe.directory=' . $path, '-c', 'core.fileMode=false',
            '-c', 'core.hooksPath=/dev/null', '-C', $path];
        if (trim(self::run(array_merge($git, ['rev-parse', '--show-toplevel']))) !== $path) {
            throw new RuntimeException('Metadados Git não pertencem ao plugin.');
        }
        $lock = fopen($path . '/.git/mfi-update.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Não foi possível bloquear a atualização do plugin.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Já existe uma atualização deste plugin em andamento.');
            }
            $plan = self::plan($git, $path, $commit);
            if ($confirmation !== null && !hash_equals($plan['fingerprint'], $confirmation)) {
                return $plan + ['confirmation_required' => true, 'stale' => true];
            }
            if ($plan['changes'] && $confirmation === null) {
                return $plan + ['confirmation_required' => true, 'stale' => false];
            }
            $backup = '';
            if ($plan['changes'] && !$withoutbackup) {
                $backup = self::backup($path, $backuproot, $webroots);
                // Detect changes made during backup before running the destructive operation.
                if (!hash_equals($plan['fingerprint'], self::plan($git, $path, $commit)['fingerprint'])) {
                    throw new RuntimeException('Os arquivos mudaram durante o backup; reinicie a atualização. Backup: ' . $backup);
                }
            }
            try {
                $output = self::run(array_merge($git, ['reset', '--hard', $commit]), 120);
            } catch (RuntimeException $e) {
                throw new RuntimeException($e->getMessage() . ($backup !== '' ? ' Backup: ' . $backup : ''), 0, $e);
            }
            return ['confirmation_required' => false, 'backup' => $backup, 'output' => trim($output)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function plan(array $git, string $path, string $commit): array {
        $head = trim(self::run(array_merge($git, ['rev-parse', 'HEAD'])));
        // Ignore permission-only noise, not genuine content/index changes.
        $status = self::run(array_merge($git, ['status', '--porcelain=v1', '-z', '--no-renames',
            '--untracked-files=no', '--ignore-submodules=none']));
        $changes = [];
        $hashes = [];
        foreach (self::paths($status) as $entry) {
            $name = substr($entry, 3);
            $changes[] = substr($entry, 0, 2) . ' ' . $name;
            $hashes[$name] = self::pathHash($path . '/' . $name);
        }
        $remote = self::paths(self::run(array_merge($git, ['ls-tree', '-r', '-z', '--name-only', $commit])));
        $files = array_fill_keys($remote, true);
        $dirs = [];
        foreach ($remote as $name) {
            for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
                $dirs[$parent] = true;
            }
        }
        // No --exclude-standard: ignored files can also be removed by reset --hard.
        $extra = self::paths(self::run(array_merge($git, ['ls-files', '--others', '-z'])));
        $preserved = [];
        foreach ($extra as $name) {
            $name = rtrim($name, '/');
            $collision = isset($files[$name]) || isset($dirs[$name]);
            for ($parent = dirname($name); !$collision && $parent !== '.'; $parent = dirname($parent)) {
                $collision = isset($files[$parent]);
            }
            if ($collision) {
                $changes[] = '?? ' . $name;
                $hashes[$name] = self::pathHash($path . '/' . $name);
            } else {
                $preserved[] = $name;
            }
        }
        $index = self::run(array_merge($git, ['diff', '--cached', '--binary', '--no-ext-diff', '--no-textconv', 'HEAD', '--']));
        return ['changes' => $changes, 'preserved' => $preserved,
            'fingerprint' => hash('sha256', serialize([$head, $commit, $status, $index, $hashes, $preserved]))];
    }

    private static function paths(string $output): array {
        return $output === '' ? [] : explode("\0", rtrim($output, "\0"));
    }

    private static function pathHash(string $path): string {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return 'link:' . readlink($path);
        }
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Não foi possível verificar arquivo local: ' . $path);
            }
            return 'file:' . $hash;
        }
        if (is_dir($path)) {
            $entries = scandir($path);
            if ($entries === false) {
                throw new RuntimeException('Não foi possível verificar diretório local: ' . $path);
            }
            $hashes = [];
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $hashes[$entry] = self::pathHash($path . '/' . $entry);
                }
            }
            return 'dir:' . hash('sha256', serialize($hashes));
        }
        if (file_exists($path)) {
            throw new RuntimeException('Tipo de arquivo local não suportado: ' . $path);
        }
        return 'missing';
    }

    private static function backup(string $path, string $root, array $webroots): string {
        // Validate both the parent and the final directory before creating any archive.
        $parent = realpath(dirname($root));
        if ($parent === false || $parent . '/' . basename($root) !== $root || is_link($root)) {
            throw new RuntimeException('Diretório privado de backup inválido.');
        }
        foreach (array_merge($webroots, [$path]) as $webroot) {
            $public = realpath($webroot);
            if ($public === false || $root === $public || str_starts_with($root, $public . '/')) {
                throw new RuntimeException('O backup deve ficar fora dos diretórios públicos.');
            }
        }
        $umask = umask(0077);
        $archive = '';
        try {
            if (!is_dir($root) && !mkdir($root, 0700) && !is_dir($root)) {
                throw new RuntimeException('Não foi possível criar o diretório privado de backup.');
            }
            if (is_link($root) || realpath($root) !== $root || (fileperms($root) & 0077) !== 0) {
                throw new RuntimeException('O diretório de backup não possui permissões privadas.');
            }
            $archive = $root . '/' . basename($path) . '-' . gmdate('Ymd-His') . '-' .
                bin2hex(random_bytes(12)) . '.tar.gz';
            // Include .git/index and all local/ignored files; tar does not follow symlinks.
            self::run(['tar', '-czf', $archive, '-C', $path, '.'], 300);
            self::run(['tar', '-tzf', $archive], 300);
            if (!is_file($archive) || !chmod($archive, 0600)) {
                throw new RuntimeException('Não foi possível proteger o arquivo de backup.');
            }
            return $archive;
        } catch (\Throwable $e) {
            if ($archive !== '' && is_file($archive)) {
                unlink($archive);
            }
            throw $e;
        } finally {
            umask($umask);
        }
    }

    /** Capture stdout and stderr separately, including NUL-delimited Git output. */
    private static function run(array $args, int $timeout = 60): string {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Não foi possível preparar a execução do comando.');
        }
        try {
            $process = proc_open(array_merge(['timeout', $timeout . 's'], $args),
                [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Não foi possível executar o comando.');
            }
            $code = proc_close($process);
            rewind($stdout);
            rewind($stderr);
            if ($code !== 0) {
                throw new RuntimeException('Falha na atualização Git/backup: ' . trim(stream_get_contents($stderr)));
            }
            return stream_get_contents($stdout);
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }
}

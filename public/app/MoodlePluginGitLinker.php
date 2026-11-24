<?php

declare(strict_types=1);

namespace app;

use RuntimeException;

/**
 * Attach a checked GitHub repository to existing Moodle plugin files without
 * replacing the FTP working tree. Future updates preview and back up differences.
 */
final class MoodlePluginGitLinker {
    /**
     * @param array{clone_url:string,branch:string,component:string} $remote
     * @return array{branch:string,tracked_changes:bool,created:bool}
     */
    public static function attach(string $path, array $remote, bool $allowlocaltesting = false): array {
        $target = realpath($path);
        $url = (string)($remote['clone_url'] ?? '');
        $branch = (string)($remote['branch'] ?? '');
        $component = (string)($remote['component'] ?? '');
        if ($target === false || $target !== rtrim($path, '/') || !is_dir($target) || is_link($path)
            || !is_file($target . '/version.php')) {
            throw new RuntimeException('Diretório local do plugin não reconhecido.');
        }
        if ((!$allowlocaltesting && !preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git$~D', $url))
            || ($allowlocaltesting && $url === '')) {
            throw new RuntimeException('Repositório GitHub inválido.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $branch)
            || !preg_match('/^[a-z][a-z0-9_]*_[a-z][a-z0-9_]*$/D', $component)) {
            throw new RuntimeException('Branch ou componente Git inválido.');
        }
        self::command(['git', 'check-ref-format', '--branch', $branch]);
        $gitdir = $target . '/.git';
        if (is_link($gitdir) || (file_exists($gitdir) && !is_dir($gitdir))) {
            throw new RuntimeException('Metadados Git personalizados requerem verificação manual.');
        }
        $created = !is_dir($gitdir);
        if ($created && !is_writable($target)) {
            throw new RuntimeException('O servidor web não pode criar .git no diretório do plugin.');
        }
        try {
            if ($created) {
                self::command(['git', 'init', '-b', $branch, $target]);
            } else {
                $top = trim(self::command(['git', '-c', 'safe.directory=' . $target, '-C', $target, 'rev-parse', '--show-toplevel']));
                if (realpath($top) !== $target) {
                    throw new RuntimeException('Metadados Git não pertencem ao diretório deste plugin.');
                }
            }

            $base = ['git', '-c', 'safe.directory=' . $target, '-C', $target];
            self::command(array_merge($base, ['fetch', '--force', '--depth=1', '--no-tags', $url, $branch]), 120);
            $versionphp = self::command(array_merge($base, ['show', 'FETCH_HEAD:version.php']));
            if (!preg_match('/\$plugin->component\s*=\s*["\x27]' . preg_quote($component, '/') . '["\x27]\s*;/', $versionphp)) {
                throw new RuntimeException('O version.php remoto não pertence ao componente ' . $component . '.');
            }
            // Do not modify a previous origin until the new URL has been fetched and verified.
            [$origincode] = self::result(array_merge($base, ['remote', 'get-url', 'origin']));
            if ($origincode === 0) {
                self::command(array_merge($base, ['remote', 'set-url', 'origin', $url]));
            } else {
                self::command(array_merge($base, ['remote', 'add', 'origin', $url]));
            }
            if ($created) {
                // Updates only HEAD/index, preserving every tracked and untracked local file.
                self::command(array_merge($base, ['reset', '--mixed', 'FETCH_HEAD']));
            } else {
                // Detached HEAD cannot be checked by the existing update scanner.
                $head = trim(self::command(array_merge($base, ['rev-parse', '--abbrev-ref', 'HEAD'])));
                if ($head === 'HEAD') {
                    self::command(array_merge($base, ['switch', '-c', $branch]));
                }
            }
            $dirty = trim(self::command(array_merge($base, ['-c', 'core.fileMode=false', 'status', '--porcelain', '--untracked-files=no']))) !== '';
            return ['branch' => $branch, 'tracked_changes' => $dirty, 'created' => $created];
        } catch (\Throwable $error) {
            // Only delete metadata that this operation created. Never remove files from FTP.
            if ($created && is_dir($gitdir)) {
                self::removeNewGitMetadata($gitdir);
            }
            throw $error;
        }
    }

    /** @param array<int, string> $args */
    private static function command(array $args, int $timeout = 60): string {
        [$exit, $output] = self::result($args, $timeout);
        if ($exit !== 0) {
            throw new RuntimeException('Git falhou: ' . trim($output));
        }
        return $output;
    }

    /** @param array<int, string> $args
     *  @return array{0:int,1:string}
     */
    private static function result(array $args, int $timeout = 60): array {
        $process = proc_open(array_merge(['timeout', (string)$timeout . 's'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível executar o Git.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    private static function removeNewGitMetadata(string $gitdir): void {
        // Files created by git init/fetch are private metadata; never follow symlinks.
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($gitdir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $pathname = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($pathname);
            } else {
                @unlink($pathname);
            }
        }
        @rmdir($gitdir);
    }
}

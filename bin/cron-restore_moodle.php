#!/usr/bin/env php
<?php

// Restores a Kopere Dashboard ZIP exported as schema/*.json + data/*.csv and optional moodledata files.

use app\JobManager;

if (!function_exists('app_config_path')) {
    require_once __DIR__ . '/../public/app/bootstrap.php';
}

if (function_exists('posix_geteuid') && PHP_SAPI === 'cli' && posix_geteuid() !== 0) {
    fwrite(STDERR, "This restore must be executed as root.\n");
    exit(1);
}

if (isset($job) && is_array($job) && ($job['type'] ?? '') === 'restore_moodle') {
    $job = JobManager::markRunning((string) $job['id']);
    if (!$job) {
        throw new RuntimeException('Cannot mark restore job as running.');
    }

    try {
        $result = restoreMoodleFromKopereZip($job);
        JobManager::markDone((string) $job['id'], ['restore_summary' => $result]);
        echo "Restore completed: {$job['id']}\n";
    } catch (Throwable $e) {
        JobManager::markFailed((string) $job['id'], $e->getMessage());
        throw $e;
    }
}

/**
 * Restores a Kopere backup ZIP into a Moodle installation.
 *
 * @param array $job
 * @param array|null $target
 * @return array
 */
function restoreMoodleFromKopereZip(array $job, ?array $target = null): array {
    $zipfile = (string) ($job['kopere_backup_zip'] ?? $job['backup_zip'] ?? '');
    if ($zipfile === '' || !is_file($zipfile)) {
        throw new RuntimeException('Kopere backup ZIP not found.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required to restore Kopere backups.');
    }

    $domain = (string) ($target['domain'] ?? $job['domain'] ?? '');
    if ($domain === '') {
        throw new RuntimeException('Restore domain not informed.');
    }

    $target = $target ?? restoreTargetFromDomain($domain);
    $extractdir = app_config_path('/runtime/restore-' . preg_replace('/[^a-z0-9.-]+/', '-', strtolower($domain)) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
    if (!is_dir($extractdir)) {
        mkdir($extractdir, 0700, true);
    }

    try {
        restoreExtractZip($zipfile, $extractdir);
        $layout = restoreDetectLayout($extractdir);
        $summary = [
            'zip' => $zipfile,
            'database_tables' => 0,
            'database_rows' => 0,
            'moodledata_files' => 0,
            'schema_found' => $layout['schema_dir'] !== null,
            'data_found' => $layout['data_dir'] !== null,
            'moodledata_found' => $layout['moodledata_dir'] !== null,
        ];

        if ($layout['schema_dir'] !== null && $layout['data_dir'] !== null) {
            $dbsummary = restoreDatabaseFromSchemaCsv($layout['schema_dir'], $layout['data_dir'], $target);
            $summary['database_tables'] = $dbsummary['tables'];
            $summary['database_rows'] = $dbsummary['rows'];
        }

        if ($layout['moodledata_dir'] !== null) {
            $summary['moodledata_files'] = restoreMoodledataDirectory($layout['moodledata_dir'], (string) $target['dataroot']);
        }

        runMoodleCliAfterRestore($target, 'upgrade.php --non-interactive');
        runMoodleCliAfterRestore($target, 'purge_caches.php');
        restoreFixPermissions($target);

        @unlink($zipfile);
        return $summary;
    } finally {
        restoreDeletePath($extractdir);
    }
}

/**
 * Builds target metadata from an already installed Moodle domain.
 *
 * @param string $domain
 * @return array
 */
function restoreTargetFromDomain(string $domain): array {
    $base = '/home/' . $domain;
    $moodledir = $base . '/moodle';
    $webroot = is_dir($moodledir . '/public') ? $moodledir . '/public' : $moodledir;
    $config = restoreReadMoodleConfig($moodledir . '/config.php');

    return [
        'domain' => $domain,
        'base_dir' => $base,
        'moodle_dir' => $moodledir,
        'webroot' => $webroot,
        'dataroot' => $config['dataroot'] ?? ($base . '/moodledata'),
        'dbname' => $config['dbname'] ?? '',
        'dbuser' => $config['dbuser'] ?? '',
        'dbpass' => $config['dbpass'] ?? '',
        'dbhost' => $config['dbhost'] ?? 'localhost',
        'dbprefix' => $config['prefix'] ?? 'mdl_',
        'php_bin' => app_config('php_bin'),
        'apache_user' => app_config('apache_user'),
        'apache_group' => app_config('apache_group'),
    ];
}

/**
 * Extracts a ZIP safely.
 *
 * @param string $zipfile
 * @param string $destination
 * @return void
 */
function restoreExtractZip(string $zipfile, string $destination): void {
    $zip = new ZipArchive();
    if ($zip->open($zipfile) !== true) {
        throw new RuntimeException('Cannot open Kopere backup ZIP.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $entry);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../')) {
            $zip->close();
            throw new RuntimeException('Unsafe path inside Kopere backup ZIP: ' . $entry);
        }
    }

    if (!$zip->extractTo($destination)) {
        $zip->close();
        throw new RuntimeException('Cannot extract Kopere backup ZIP.');
    }
    $zip->close();
}

/**
 * Finds schema/data and moodledata directories in different Kopere ZIP layouts.
 *
 * @param string $root
 * @return array
 */
function restoreDetectLayout(string $root): array {
    $schema = restoreFindDirectory($root, 'schema', static fn(string $dir): bool => (bool) glob($dir . '/*.json'));
    $data = restoreFindDirectory($root, 'data', static fn(string $dir): bool => (bool) glob($dir . '/*.csv'));
    $moodledata = null;

    $candidates = [
        $root . '/moodledata',
        $root . '/data/moodledata',
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            $moodledata = $candidate;
            break;
        }
    }

    if ($moodledata === null) {
        foreach (['filedir', 'files', 'cache', 'localcache', 'sessions', 'temp', 'trashdir'] as $name) {
            if (is_dir($root . '/' . $name)) {
                $moodledata = $root;
                break;
            }
        }
    }

    return [
        'schema_dir' => $schema,
        'data_dir' => $data,
        'moodledata_dir' => $moodledata,
    ];
}

/**
 * Finds a directory by basename and predicate.
 *
 * @param string $root
 * @param string $basename
 * @param callable $predicate
 * @return string|null
 */
function restoreFindDirectory(string $root, string $basename, callable $predicate): ?string {
    $direct = $root . '/' . $basename;
    if (is_dir($direct) && $predicate($direct)) {
        return $direct;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $path = $item->getPathname();
        if (basename($path) === $basename && $predicate($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Restores database rows using Moodle native tables created by install_database.php.
 *
 * @param string $schemadir
 * @param string $datadir
 * @param array $target
 * @return array
 * @throws \Throwable
 */
function restoreDatabaseFromSchemaCsv(string $schemadir, string $datadir, array $target): array {
    $pdo = restorePdo($target);
    $prefix = (string) ($target['dbprefix'] ?? 'mdl_');
    $schemafiles = glob(rtrim($schemadir, '/') . '/*.json') ?: [];
    sort($schemafiles);

    $totalTables = 0;
    $totalRows = 0;

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($schemafiles as $schemafile) {
            $schema = json_decode((string) file_get_contents($schemafile), true);
            if (!is_array($schema) || empty($schema['table']) || empty($schema['fields']) || !is_array($schema['fields'])) {
                continue;
            }

            $exportname = restoreSafeTableName((string) $schema['table']);
            $csvfile = rtrim($datadir, '/') . '/' . restoreSafeFileName($exportname) . '.csv';
            if (!is_file($csvfile)) {
                $csvfile = rtrim($datadir, '/') . '/' . basename($schemafile, '.json') . '.csv';
            }
            if (!is_file($csvfile)) {
                continue;
            }

            $targettable = restoreResolveTargetTable($pdo, $prefix, $exportname);
            if ($targettable === null) {
                continue;
            }

            $targetcolumns = restoreReadTargetColumns($pdo, $targettable);
            $schemafields = array_values(array_filter($schema['fields'], static fn($field): bool => is_array($field) && !empty($field['name'])));
            $insertfields = [];
            $fieldIndexes = [];
            foreach ($schemafields as $index => $field) {
                $name = (string) $field['name'];
                if (isset($targetcolumns[$name])) {
                    $insertfields[] = $field;
                    $fieldIndexes[] = $index;
                }
            }
            if (empty($insertfields)) {
                continue;
            }

            $pdo->exec('TRUNCATE TABLE ' . restoreQuoteIdentifier($targettable));
            $rows = restoreImportCsvRows($pdo, $targettable, $insertfields, $fieldIndexes, $targetcolumns, $csvfile);
            $totalTables++;
            $totalRows += $rows;
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return ['tables' => $totalTables, 'rows' => $totalRows];
}

/**
 * Imports one CSV into one table.
 *
 * @param PDO $pdo
 * @param string $table
 * @param array $insertfields
 * @param array $fieldIndexes
 * @param array $targetcolumns
 * @param string $csvfile
 * @return int
 * @throws \Throwable
 */
function restoreImportCsvRows(PDO $pdo, string $table, array $insertfields, array $fieldIndexes, array $targetcolumns, string $csvfile): int {
    $columns = array_map(static fn(array $field): string => (string) $field['name'], $insertfields);
    $quotedcolumns = implode(', ', array_map('restoreQuoteIdentifier', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO ' . restoreQuoteIdentifier($table) . ' (' . $quotedcolumns . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);

    $handle = fopen($csvfile, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read CSV file: ' . $csvfile);
    }

    $count = 0;
    try {
        $pdo->beginTransaction();
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $values = [];
            foreach ($insertfields as $position => $field) {
                $sourceIndex = $fieldIndexes[$position];
                $name = (string) $field['name'];
                $raw = $row[$sourceIndex] ?? null;
                $values[] = restoreConvertCsvValue($raw, $field, $targetcolumns[$name] ?? []);
            }
            $stmt->execute($values);
            $count++;
            if (($count % 1000) === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        fclose($handle);
    }

    return $count;
}

/**
 * Converts CSV string values back to database values.
 *
 * @param mixed $raw
 * @param array $field
 * @param array $targetcolumn
 * @return mixed
 */
function restoreConvertCsvValue(mixed $raw, array $field, array $targetcolumn): mixed {
    if ($raw === null || $raw === '\\N') {
        return null;
    }

    $type = strtolower((string) ($field['type'] ?? 'text'));
    $sqltype = strtolower((string) ($targetcolumn['type'] ?? ''));

    if ($type === 'datetime') {
        $timestamp = strtotime((string) $raw);
        if ($timestamp === false) {
            return 0;
        }
        if (preg_match('/int|decimal|numeric|float|double/', $sqltype)) {
            return $timestamp;
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    if ($type === 'date') {
        $timestamp = strtotime((string) $raw);
        return $timestamp === false ? $raw : gmdate('Y-m-d', $timestamp);
    }

    if ($type === 'boolean') {
        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'y'], true) ? 1 : 0;
    }

    if ($type === 'integer') {
        return is_numeric($raw) ? (int) $raw : 0;
    }

    if (in_array($type, ['decimal', 'float'], true)) {
        return is_numeric($raw) ? (string) $raw : '0';
    }

    if ($type === 'binary') {
        $decoded = base64_decode((string) $raw, true);
        return $decoded === false ? '' : $decoded;
    }

    return (string) $raw;
}

/**
 * Restores moodledata content when the ZIP contains it.
 *
 * @param string $sourcedir
 * @param string $dataroot
 * @return int
 */
function restoreMoodledataDirectory(string $sourcedir, string $dataroot): int {
    if (!is_dir($dataroot)) {
        mkdir($dataroot, 0770, true);
    }

    $copied = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcedir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $source = $item->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($source, strlen(rtrim($sourcedir, DIRECTORY_SEPARATOR)))), '/');
        if ($relative === '' || restoreIsIgnoredMoodledataPath($relative)) {
            continue;
        }
        $dest = rtrim($dataroot, '/') . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0770, true);
            }
            continue;
        }
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0770, true);
        }
        copy($source, $dest);
        $copied++;
    }
    return $copied;
}

/**
 * Ignores Kopere backup metadata while copying moodledata.
 *
 * @param string $relative
 * @return bool
 */
function restoreIsIgnoredMoodledataPath(string $relative): bool {
    $relative = trim(str_replace('\\', '/', $relative), '/');
    foreach (['backup', 'schema', 'data'] as $ignored) {
        if ($relative === $ignored || str_starts_with($relative, $ignored . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Runs a Moodle CLI command after restore.
 *
 * @param array $target
 * @param string $command
 * @return void
 */
function runMoodleCliAfterRestore(array $target, string $command): void {
    $php = (string) ($target['php_bin'] ?? '/usr/bin/php');
    $user = (string) ($target['apache_user'] ?? 'apache');
    $moodledir = rtrim((string) $target['moodle_dir'], '/');
    $cli = $moodledir . '/admin/cli/' . $command;
    $cmd = 'sudo -u ' . escapeshellarg($user) . ' ' . escapeshellarg($php) . ' ' . $cli;
    exec($cmd, $output, $exitcode);
    if ($exitcode !== 0) {
        throw new RuntimeException('Moodle CLI failed after restore: ' . implode("\n", $output));
    }
}

/**
 * Fixes ownership after restore.
 *
 * @param array $target
 * @return void
 */
function restoreFixPermissions(array $target): void {
    $base = (string) ($target['base_dir'] ?? '');
    if ($base === '' || !is_dir($base)) {
        return;
    }
    $user = (string) ($target['apache_user'] ?? 'apache');
    $group = (string) ($target['apache_group'] ?? 'apache');
    exec('chown -R ' . escapeshellarg($user . ':' . $group) . ' ' . escapeshellarg($base));
    exec('chmod -R 777 ' . escapeshellarg($base));
}

/**
 * Creates the restore PDO connection.
 *
 * @param array $target
 * @return PDO
 */
function restorePdo(array $target): PDO {
    $dbname = (string) ($target['dbname'] ?? '');
    $user = (string) ($target['dbuser'] ?? '');
    $pass = (string) ($target['dbpass'] ?? '');
    $host = (string) ($target['dbhost'] ?? 'localhost');
    if ($dbname === '' || $user === '') {
        throw new RuntimeException('Database credentials are missing for restore.');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Resolves the destination table for a prefixless schema table name.
 *
 * @param PDO $pdo
 * @param string $prefix
 * @param string $exportname
 * @return string|null
 */
function restoreResolveTargetTable(PDO $pdo, string $prefix, string $exportname): ?string {
    $candidates = [$prefix . $exportname];
    if (str_ends_with($exportname, 's')) {
        $candidates[] = $prefix . substr($exportname, 0, -1);
    }
    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn()) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Reads target table columns.
 *
 * @param PDO $pdo
 * @param string $table
 * @return array
 */
function restoreReadTargetColumns(PDO $pdo, string $table): array {
    $columns = [];
    $stmt = $pdo->query('DESCRIBE ' . restoreQuoteIdentifier($table));
    foreach ($stmt->fetchAll() as $row) {
        $columns[(string) $row['Field']] = [
            'type' => (string) $row['Type'],
            'null' => (string) $row['Null'],
            'default' => $row['Default'] ?? null,
        ];
    }
    return $columns;
}

/**
 * Reads Moodle config.php values.
 *
 * @param string $configfile
 * @return array
 */
function restoreReadMoodleConfig(string $configfile): array {
    if (!is_readable($configfile)) {
        return [];
    }
    $content = (string) file_get_contents($configfile);
    $keys = ['dbname', 'dbuser', 'dbpass', 'dbhost', 'prefix', 'dataroot'];
    $config = [];
    foreach ($keys as $key) {
        if (preg_match('/\$CFG->' . preg_quote($key, '/') . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
            $config[$key] = stripcslashes($matches[2]);
        }
    }
    return $config;
}

/**
 * Quotes an identifier for MySQL.
 *
 * @param string $identifier
 * @return string
 */
function restoreQuoteIdentifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Sanitizes table names from schema metadata.
 *
 * @param string $table
 * @return string
 */
function restoreSafeTableName(string $table): string {
    $table = preg_replace('/[^a-zA-Z0-9_]+/', '_', $table);
    return trim((string) $table, '_');
}

/**
 * Sanitizes schema/data filenames.
 *
 * @param string $name
 * @return string
 */
function restoreSafeFileName(string $name): string {
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name);
    $filename = trim((string) $filename, '_');
    return $filename !== '' ? $filename : 'table';
}

/**
 * Deletes a file or directory tree.
 *
 * @param string $path
 * @return void
 */
function restoreDeletePath(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

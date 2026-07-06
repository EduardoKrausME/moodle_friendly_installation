<?php

// Restores a Kopere Dashboard ZIP exported as schema/*.json + data/*.csv and optional moodledata files.

use app\JobManager;

if (!isset($job) || !is_array($job)) {
    die("\$job empty");
}

if ($job["type"] == "restore_moodle") {
    runRestoreMoodleQueueJob($job);
}

/**
 * Runs a restore job from the root queue.
 *
 * Restore mode provisions Moodle files/configuration, but it never runs
 * admin/cli/install_database.php. Database tables are created from schema/*.json
 * and then populated from data/*.csv.
 *
 * @param array $job
 * @return void
 * @throws \Random\RandomException
 * @throws \Throwable
 */
function runRestoreMoodleQueueJob(array $job): void {
    require_once __DIR__ . "/cron-install_moodle.php";

    $domain = (string) ($job["domain"] ?? "");
    if (!domainHasDnsRecord($domain)) {
        $message =
            "DNS ainda não configurado para {$domain}. Configure o registro A ou AAAA apontando para este servidor. O cron verificará novamente em 1 minuto.";
        appendJobLog($job, $message, "danger");
        JobManager::markWaitingDns($job["id"], $message);
        echo "Restore job waiting DNS: {$job["id"]} - {$message}\n";
        return;
    }

    if (($job["status"] ?? "") === "waiting_dns") {
        appendJobLog($job, "DNS detectado para {$domain}. Continuando restauração.");
    }

    $job = JobManager::markRunning($job["id"]);
    if (!$job) {
        throw new RuntimeException("Cannot mark restore job as running.");
    }

    try {
        appendJobLog($job, "Provisioning Moodle restore target without install_database.php.");
        $provision = executeInstallJob($job, "restore");
        if (($provision["exitcode"] ?? 1) !== 0) {
            throw new RuntimeException($provision["message"] ?? "Restore provisioning failed.");
        }

        $target = $provision["extra"]["target"] ?? null;
        if (!is_array($target)) {
            throw new RuntimeException("Restore provisioning did not return target metadata.");
        }

        appendJobLog($job, "Starting Kopere Dashboard database restore from schema/data files.");
        $result = restoreMoodleFromKopereZip($job, $target);
        appendJobLog($job, "Kopere Dashboard backup restore finished.");

        JobManager::markDone($job["id"], ["restore_summary" => $result]);
        echo "Restore completed: {$job["id"]}\n";
    } catch (Throwable $e) {
        JobManager::markFailed($job["id"], $e->getMessage());
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
    $zipfile = (string) ($job["kopere_backup_zip"] ?? $job["backup_zip"] ?? "");
    if ($zipfile === "" || !is_file($zipfile)) {
        throw new RuntimeException("Kopere backup ZIP not found.");
    }
    if (!class_exists("ZipArchive")) {
        throw new RuntimeException("PHP ZipArchive extension is required to restore Kopere backups.");
    }

    $domain = (string) ($target["domain"] ?? $job["domain"] ?? "");
    if ($domain === "") {
        throw new RuntimeException("Restore domain not informed.");
    }

    $target = $target ?? restoreTargetFromDomain($domain);
    $extractdir = app_config_path(
        "/runtime/restore-" . preg_replace('/[^a-z0-9.-]+/', "-", strtolower($domain)) . "-" . date("Ymd-His") . "-" .
        bin2hex(random_bytes(3))
    );
    if (!is_dir($extractdir)) {
        mkdir($extractdir, 0700, true);
    }

    try {
        restoreExtractZip($zipfile, $extractdir);
        $layout = restoreDetectLayout($extractdir);
        $summary = [
            "zip" => $zipfile,
            "database_tables" => 0,
            "database_created_tables" => 0,
            "database_rows" => 0,
            "moodledata_files" => 0,
            "schema_found" => $layout["schema_dir"] !== null,
            "data_found" => $layout["data_dir"] !== null,
            "moodledata_found" => $layout["moodledata_dir"] !== null,
            "manifest_found" => $layout["manifest_file"] !== null,
            "config_version_found" => false,
            "config_version_inserted" => false,
        ];

        if ($layout["schema_dir"] !== null && $layout["data_dir"] !== null) {
            $dbsummary = restoreDatabaseFromSchemaCsv($layout["schema_dir"], $layout["data_dir"], $target);
            $summary["database_tables"] = $dbsummary["tables"];
            $summary["database_created_tables"] = $dbsummary["created_tables"] ?? $dbsummary["tables"];
            $summary["database_rows"] = $dbsummary["rows"];
            $configsummary = restoreEnsureMoodleConfigVersion($target, $layout["manifest_file"]);
            $summary["config_version_found"] = $configsummary["found"];
            $summary["config_version_inserted"] = $configsummary["inserted"];
            $summary["config_version_source"] = $configsummary["source"];
        }

        if ($layout["moodledata_dir"] !== null) {
            $summary["moodledata_files"] = restoreMoodledataDirectory($layout["moodledata_dir"], $target["dataroot"]);
        }

        runMoodleCliAfterRestore($target, "upgrade.php --non-interactive");
        runMoodleCliAfterRestore($target, "purge_caches.php");
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
    $base = "/home/{$domain}";
    $moodledir = "{$base}/moodle";
    $webroot = is_dir("{$moodledir}/public") ? "{$moodledir}/public" : $moodledir;
    $moodleconfig = restoreReadMoodleConfig("{$moodledir}/config.php");

    return [
        "domain" => $domain,
        "base_dir" => $base,
        "moodle_dir" => $moodledir,
        "webroot" => $webroot,
        "dataroot" => $moodleconfig["dataroot"] ?? ("{$base}/moodledata"),
        "dbname" => $moodleconfig["dbname"] ?? "",
        "dbuser" => $moodleconfig["dbuser"] ?? "",
        "dbpass" => $moodleconfig["dbpass"] ?? "",
        "dbhost" => $moodleconfig["dbhost"] ?? "localhost",
        "dbprefix" => $moodleconfig["prefix"] ?? "mdl_",
        "php_bin" => app_config("php_bin"),
        "apache_user" => app_config("apache_user"),
        "apache_group" => app_config("apache_group"),
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
        throw new RuntimeException("Cannot open Kopere backup ZIP.");
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        $normalized = str_replace('\\', "/", $entry);
        if ($normalized === "" || str_starts_with($normalized, "/") || str_contains($normalized, "../")) {
            $zip->close();
            throw new RuntimeException("Unsafe path inside Kopere backup ZIP: {$entry}");
        }
    }

    if (!$zip->extractTo($destination)) {
        $zip->close();
        throw new RuntimeException("Cannot extract Kopere backup ZIP.");
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
    $schema = restoreFindDirectory($root, "schema", static fn(string $dir): bool => (bool) glob($dir . '/*.json'));
    $data = restoreFindDirectory($root, "data", static fn(string $dir): bool => (bool) glob("{$dir}/*.csv"));
    $moodledata = null;
    $manifest = restoreFindManifestFile($root);

    $candidates = [
        "{$root}/moodledata",
        "{$root}/data/moodledata",
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            $moodledata = $candidate;
            break;
        }
    }

    if ($moodledata === null) {
        foreach (["filedir", "files", "cache", "localcache", "sessions", "temp", "trashdir"] as $name) {
            if (is_dir("{$root}/{$name}")) {
                $moodledata = $root;
                break;
            }
        }
    }

    return [
        "schema_dir" => $schema,
        "data_dir" => $data,
        "moodledata_dir" => $moodledata,
        "manifest_file" => $manifest,
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
    $direct = "{$root}/{$basename}";
    if (is_dir($direct) && $predicate($direct)) {
        return $direct;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
    );
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
 * Restores database tables and rows using the backup schema/*.json + data/*.csv.
 *
 * This function intentionally creates the Moodle tables from the backup schema.
 * It must not depend on admin/cli/install_database.php having created native tables.
 *
 * @param string $schemadir
 * @param string $datadir
 * @param array $target
 * @return array
 * @throws \Throwable
 */
function restoreDatabaseFromSchemaCsv(string $schemadir, string $datadir, array $target): array {
    global $job;

    $pdo = restorePdo($target);
    $prefix = (string) ($target["dbprefix"] ?? "mdl_");
    $schemafiles = glob(rtrim($schemadir, "/") . '/*.json') ?: [];
    sort($schemafiles);

    if (empty($schemafiles)) {
        throw new RuntimeException("No schema/*.json files found in Kopere backup.");
    }

    $schemas = restoreReadDatabaseRestoreSchemas($schemafiles, $datadir, $prefix);
    if (empty($schemas)) {
        throw new RuntimeException("No valid schema/*.json files found in Kopere backup.");
    }

    $totalRows = 0;
    $createdTables = 0;

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    try {
        appendJobLog($job, "Kopere Dashboard database restore tables.");

        foreach ($schemas as $schema) {
            restoreCreateTableFromSchema($pdo, $schema["target_table"], $schema["schema"]);
            $createdTables++;
        }

        appendJobLog($job, "Kopere Dashboard database restore rows.");
        foreach ($schemas as $schema) {
            if (!is_file($schema["csv_file"])) {
                continue;
            }

            $targetcolumns = restoreReadTargetColumns($pdo, $schema["target_table"]);
            $schemafields = array_values(
                array_filter(
                    restoreSchemaFields($schema["schema"]),
                    static fn($field): bool => is_array($field) && restoreSchemaFieldName($field) !== ""
                )
            );
            $insertfields = [];
            $fieldIndexes = [];
            foreach ($schemafields as $index => $field) {
                $name = restoreSafeColumnName(restoreSchemaFieldName($field));
                if (isset($targetcolumns[$name])) {
                    $field["name"] = $name;
                    $insertfields[] = $field;
                    $fieldIndexes[] = $index;
                }
            }
            if (empty($insertfields)) {
                continue;
            }

            $totalRows += restoreImportCsvRows(
                $pdo,
                $schema["target_table"],
                $insertfields,
                $fieldIndexes,
                $targetcolumns,
                $schema["csv_file"]
            );
        }
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    return ["tables" => count($schemas), "created_tables" => $createdTables, "rows" => $totalRows];
}

/**
 * Reads schema metadata and builds the database restore plan.
 *
 * The restore must create every table before importing any CSV data. This makes
 * sure Moodle core tables, including config, already exist before
 * restoreEnsureMoodleConfigVersion() is executed later in the restore flow.
 *
 * @param array $schemafiles
 * @param string $datadir
 * @param string $prefix
 * @return array
 */
function restoreReadDatabaseRestoreSchemas(array $schemafiles, string $datadir, string $prefix): array {
    $schemas = [];

    foreach ($schemafiles as $schemafile) {
        $schema = json_decode((string) file_get_contents($schemafile), true);
        if (!is_array($schema)) {
            continue;
        }

        $fields = restoreSchemaFields($schema);
        if (empty($fields)) {
            continue;
        }

        $rawtable = $schema["table"] ?? basename($schemafile, ".json");
        $exportname = restoreSafeTableName((string) $rawtable);
        if ($exportname === "") {
            $exportname = restoreSafeTableName(basename($schemafile, ".json"));
        }
        if ($exportname === "") {
            continue;
        }

        $csvfile = rtrim($datadir, "/") . "/" . restoreSafeFileName($exportname) . ".csv";
        if (!is_file($csvfile)) {
            $csvfile = rtrim($datadir, "/") . "/" . basename($schemafile, ".json") . ".csv";
        }

        $schemas[] = [
            "schema" => $schema,
            "export_name" => $exportname,
            "target_table" => restoreTargetTableName($prefix, $exportname),
            "csv_file" => $csvfile,
        ];
    }

    return $schemas;
}

/**
 * Builds the physical table name for the target Moodle prefix.
 *
 * @param string $prefix
 * @param string $exportname
 * @return string
 */
function restoreTargetTableName(string $prefix, string $exportname): string {
    if ($prefix !== "" && str_starts_with($exportname, $prefix)) {
        return $exportname;
    }
    return $prefix . $exportname;
}

/**
 * Creates a database table from one Kopere schema JSON definition.
 *
 * @param PDO $pdo
 * @param string $table
 * @param array $schema
 * @return void
 */
function restoreCreateTableFromSchema(PDO $pdo, string $table, array $schema): void {
    $fields = restoreSchemaFields($schema);
    if (empty($fields)) {
        return;
    }

    $definitions = [];
    foreach ($fields as $field) {
        $column = restoreSchemaColumnSql($field);
        if ($column !== null) {
            $definitions[] = $column;
        }
    }

    if (empty($definitions)) {
        return;
    }

    $keys = restoreSchemaKeysSql($schema, $fields);
    foreach ($keys as $keySql) {
        $definitions[] = $keySql;
    }

    if (!restoreSchemaHasExplicitPrimaryKey($definitions) && empty($schema["keys"])) {
        $primaryFields = restoreSchemaPrimaryFields($fields);
        if (!empty($primaryFields)) {
            $definitions[] = "PRIMARY KEY (" . implode(", ", array_map("restoreQuoteIdentifier", $primaryFields)) . ")";
        }
    }

    foreach (restoreSchemaIndexesSql($schema, $fields) as $indexSql) {
        $definitions[] = $indexSql;
    }

    $pdo->exec("DROP TABLE IF EXISTS " . restoreQuoteIdentifier($table));
    $sql = "CREATE TABLE IF NOT EXISTS " . restoreQuoteIdentifier($table) . " (\n    " . implode(",\n    ", $definitions) .
        "\n) ENGINE=InnoDB";
    $pdo->exec($sql);
}

/**
 * Returns schema columns preserving the exact JSON order.
 *
 * Friendly installation backups may use either columns[] or fields[]. The JSON
 * order is the source of truth for CREATE TABLE and CSV import order.
 *
 * @param array $schema
 * @return array
 */
function restoreSchemaFields(array $schema): array {
    $fields = [];
    if (isset($schema["columns"]) && is_array($schema["columns"])) {
        $fields = $schema["columns"];
    } else if (isset($schema["fields"]) && is_array($schema["fields"])) {
        $fields = $schema["fields"];
    }

    return array_values(
        array_filter(
            $fields,
            static fn($field): bool => is_array($field) && restoreSchemaFieldName($field) !== ""
        )
    );
}

/**
 * Builds one column SQL definition from a schema field.
 *
 * @param array $field
 * @return string|null
 */
function restoreSchemaColumnSql(array $field): ?string {
    $name = restoreSafeColumnName(restoreSchemaFieldName($field));
    if ($name === "") {
        return null;
    }

    $sqltype = restoreSchemaFieldSqlType($field);
    $nullable = restoreSchemaFieldNullable($field);
    $auto = restoreSchemaFieldAutoIncrement($field) ? " AUTO_INCREMENT" : "";
    $nullsql = $nullable && $auto === "" ? "" : " NOT NULL";
    $default = restoreSchemaFieldDefaultSql($field, $sqltype, $nullable, $auto !== "");

    return restoreQuoteIdentifier($name) . " {$sqltype}{$nullsql}{$default}{$auto}";
}

/**
 * Converts friendly_installation/XMLDB metadata into a MySQL/MariaDB type.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaFieldSqlType(array $field): string {
    $type = restoreSchemaLogicalType($field);
    $nativeType = restoreSchemaFieldNativeSqlType($field);

    if ($nativeType !== null && restoreSchemaNativeTypeMatchesLogicalType($nativeType, $type)) {
        return restoreSchemaApplyUnsigned($nativeType, $field);
    }

    $sqltype = match ($type) {
        "int", "integer" => restoreSchemaIntegerType($field),
        "tinyint", "tinyinteger" => "tinyint",
        "smallint", "smallinteger" => "smallint",
        "mediumint", "mediuminteger" => "mediumint",
        "bigint" => "bigint",
        "char", "varchar", "string" => "varchar(" . restoreSchemaVarcharLength($field) . ")",
        "text", "tinytext", "mediumtext", "longtext" => restoreSchemaTextType($field, $type),
        "number", "decimal", "numeric" => restoreSchemaDecimalType($field),
        "float" => "float",
        "double" => "double",
        "binary", "blob" => restoreSchemaBinaryType($field),
        "datetime", "date", "time", "timestamp" => restoreSchemaDateTimeType($type, $nativeType),
        default => null,
    };

    if ($sqltype === null) {
        $sqltype = restoreNormalizeNativeSqlType($type);
    }

    if ($sqltype === null && $nativeType !== null) {
        $sqltype = $nativeType;
    }

    if ($sqltype === null) {
        $sqltype = restoreSchemaLength($field) !== null ? "varchar(" . restoreSchemaVarcharLength($field) . ")" : "longtext";
    }

    return restoreSchemaApplyUnsigned($sqltype, $field);
}

/**
 * Returns the logical XMLDB/friendly_installation type.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaLogicalType(array $field): string {
    $type = restoreSchemaFieldValue($field, ["type", "field_type", "data_type", "DATA_TYPE"]);
    return strtolower(trim((string) (is_scalar($type) ? $type : "text")));
}

/**
 * Reads native MySQL type metadata from a schema field.
 *
 * @param array $field
 * @return string|null
 */
function restoreSchemaFieldNativeSqlType(array $field): ?string {
    foreach (
        ["sql_type", "sqltype", "db_type", "dbtype", "column_type", "columntype", "native_type", "nativetype", "mysql_type", "mysqltype", "Type", "TYPE", "COLUMN_TYPE"]
        as $key
    ) {
        if (!array_key_exists($key, $field) || !is_scalar($field[$key])) {
            continue;
        }
        $type = restoreNormalizeNativeSqlType((string) $field[$key]);
        if ($type !== null) {
            return $type;
        }
    }
    return null;
}

/**
 * Normalizes a native MySQL column type and rejects unsafe strings.
 *
 * @param string $type
 * @return string|null
 */
function restoreNormalizeNativeSqlType(string $type): ?string {
    $type = strtolower(trim($type));
    $type = preg_replace('/\s+/', ' ', $type) ?? $type;
    if ($type === "" || !preg_match('/^[a-z0-9_(), ]+$/', $type)) {
        return null;
    }

    $base = restoreSchemaSqlBaseType($type);
    $allowed = [
        "tinyint", "smallint", "mediumint", "int", "integer", "bigint",
        "decimal", "numeric", "float", "double", "real", "bit", "boolean", "bool",
        "char", "varchar", "binary", "varbinary",
        "tinytext", "text", "mediumtext", "longtext",
        "tinyblob", "blob", "mediumblob", "longblob",
        "date", "datetime", "timestamp", "time", "year", "json",
    ];

    return in_array($base, $allowed, true) ? $type : null;
}

/**
 * Returns the base type from a MySQL type string.
 *
 * @param string $sqltype
 * @return string
 */
function restoreSchemaSqlBaseType(string $sqltype): string {
    $sqltype = strtolower(trim($sqltype));
    return preg_replace('/[^a-z].*$/', '', $sqltype) ?? "";
}

/**
 * Checks if a native type is compatible with the logical schema type.
 *
 * @param string $nativeType
 * @param string $logicalType
 * @return bool
 */
function restoreSchemaNativeTypeMatchesLogicalType(string $nativeType, string $logicalType): bool {
    $base = restoreSchemaSqlBaseType($nativeType);

    return match ($logicalType) {
        "int", "integer" => in_array($base, ["tinyint", "smallint", "mediumint", "int", "integer", "bigint"], true),
        "char", "varchar", "string" => in_array($base, ["char", "varchar"], true),
        "text", "tinytext", "mediumtext", "longtext" => in_array($base, ["tinytext", "text", "mediumtext", "longtext"], true),
        "number", "decimal", "numeric" => in_array($base, ["decimal", "numeric"], true),
        "float" => in_array($base, ["float", "double", "real"], true),
        "double" => in_array($base, ["double", "real", "float"], true),
        "binary", "blob" => in_array($base, ["binary", "varbinary", "tinyblob", "blob", "mediumblob", "longblob"], true),
        "datetime", "date", "time", "timestamp" => in_array($base, ["date", "datetime", "time", "timestamp", "year"], true),
        default => true,
    };
}

/**
 * Returns a schema field value accepting common key case variations.
 *
 * @param array $field
 * @param array $keys
 * @return mixed
 */
function restoreSchemaFieldValue(array $field, array $keys): mixed {
    foreach ($keys as $key) {
        if (array_key_exists($key, $field)) {
            return $field[$key];
        }
    }

    $lowerKeys = array_map(static fn($key): string => strtolower((string) $key), $keys);
    foreach ($field as $key => $value) {
        if (in_array(strtolower((string) $key), $lowerKeys, true)) {
            return $value;
        }
    }

    return null;
}

/**
 * Gets the column name from schema metadata.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaFieldName(array $field): string {
    $name = restoreSchemaFieldValue($field, ["name", "field", "column", "column_name", "Field", "COLUMN_NAME"]);
    return is_scalar($name) ? trim((string) $name) : "";
}

/**
 * Gets the numeric length when it exists.
 *
 * @param array $field
 * @return int|null
 */
function restoreSchemaLength(array $field): ?int {
    $length = restoreSchemaFieldValue($field, ["length", "size", "maxlength", "max_length", "CHARACTER_MAXIMUM_LENGTH"]);
    if (is_numeric($length)) {
        return max(1, (int) $length);
    }
    return null;
}

/**
 * Maps XMLDB int length to the closest MySQL integer type.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaIntegerType(array $field): string {
    $length = restoreSchemaLength($field);
    if ($length === null || $length >= 10) {
        return "bigint";
    }
    if ($length <= 1) {
        return "tinyint";
    }
    if ($length <= 4) {
        return "smallint";
    }
    return "int";
}

/**
 * Returns a safe varchar length.
 *
 * @param array $field
 * @return int
 */
function restoreSchemaVarcharLength(array $field): int {
    return max(1, min(restoreSchemaLength($field) ?? 255, 16383));
}

/**
 * Maps XMLDB text length hints to MySQL text types.
 *
 * @param array $field
 * @param string $logicalType
 * @return string
 */
function restoreSchemaTextType(array $field, string $logicalType): string {
    if (in_array($logicalType, ["tinytext", "mediumtext", "longtext"], true)) {
        return $logicalType;
    }

    $length = strtolower((string) (restoreSchemaFieldValue($field, ["length", "size"]) ?? ""));
    return match ($length) {
        "small", "short" => "text",
        "medium" => "mediumtext",
        default => "longtext",
    };
}

/**
 * Builds a DECIMAL type from schema metadata.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaDecimalType(array $field): string {
    $length = restoreSchemaFieldValue($field, ["length", "precision"]);
    $decimals = restoreSchemaFieldValue($field, ["decimals", "scale"]);

    if (is_string($length) && preg_match('/^\d+\s*,\s*\d+$/', $length)) {
        return "decimal(" . preg_replace('/\s+/', "", $length) . ")";
    }

    if (is_numeric($length) && is_numeric($decimals)) {
        return "decimal(" . max(1, min((int) $length, 65)) . "," . max(0, min((int) $decimals, 30)) . ")";
    }

    if (is_numeric($length)) {
        return "decimal(" . max(1, min((int) $length, 65)) . ")";
    }

    return "decimal(20,10)";
}

/**
 * Maps binary length hints to MySQL blob types.
 *
 * @param array $field
 * @return string
 */
function restoreSchemaBinaryType(array $field): string {
    $length = strtolower((string) (restoreSchemaFieldValue($field, ["length", "size"]) ?? ""));
    return match ($length) {
        "small", "short" => "blob",
        "medium" => "mediumblob",
        default => "longblob",
    };
}

/**
 * Maps datetime/date/time/timestamp logical types.
 *
 * @param string $logicalType
 * @param string|null $nativeType
 * @return string
 */
function restoreSchemaDateTimeType(string $logicalType, ?string $nativeType): string {
    if ($nativeType !== null && restoreSchemaNativeTypeMatchesLogicalType($nativeType, $logicalType)) {
        return $nativeType;
    }

    return match ($logicalType) {
        "date" => "date",
        "time" => "time",
        "timestamp" => "timestamp",
        default => "datetime",
    };
}

/**
 * Applies UNSIGNED only to numeric types when the schema says so.
 *
 * @param string $sqltype
 * @param array $field
 * @return string
 */
function restoreSchemaApplyUnsigned(string $sqltype, array $field): string {
    if (!restoreSchemaFieldUnsigned($field)) {
        return $sqltype;
    }
    if (stripos($sqltype, "unsigned") !== false) {
        return $sqltype;
    }
    if (!preg_match('/\b(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/i', $sqltype)) {
        return $sqltype;
    }
    return $sqltype . " unsigned";
}

/**
 * Determines if a numeric field is UNSIGNED.
 *
 * @param array $field
 * @return bool
 */
function restoreSchemaFieldUnsigned(array $field): bool {
    if (!array_key_exists("unsigned", $field)) {
        return false;
    }
    return restoreSchemaBoolean($field["unsigned"]);
}

/**
 * Determines if a schema field allows NULL.
 *
 * notnull is authoritative. nullable is only a compatibility inverse.
 *
 * @param array $field
 * @return bool
 */
function restoreSchemaFieldNullable(array $field): bool {
    if (array_key_exists("notnull", $field)) {
        return !restoreSchemaBoolean($field["notnull"]);
    }
    if (array_key_exists("nullable", $field)) {
        return restoreSchemaBoolean($field["nullable"]);
    }
    foreach (["null", "allow_null"] as $key) {
        if (array_key_exists($key, $field)) {
            return restoreSchemaBoolean($field[$key]);
        }
    }
    return true;
}

/**
 * Builds a DEFAULT clause when allowed by MySQL/MariaDB.
 *
 * @param array $field
 * @param string $sqltype
 * @param bool $nullable
 * @param bool $autoIncrement
 * @return string
 */
function restoreSchemaFieldDefaultSql(array $field, string $sqltype, bool $nullable, bool $autoIncrement = false): string {
    if ($autoIncrement) {
        return "";
    }

    $hasDefault = array_key_exists("default", $field);
    if (restoreSchemaSqlTypeCannotHaveDefault($sqltype)) {
        return "";
    }

    if (!$hasDefault) {
        return $nullable ? " DEFAULT NULL" : "";
    }

    $default = $field["default"];
    if ($default === null) {
        return $nullable ? " DEFAULT NULL" : "";
    }

    if (is_bool($default)) {
        $default = $default ? "1" : "0";
    }

    $default = (string) $default;
    $upper = strtoupper(trim($default));
    if (in_array($upper, ["CURRENT_TIMESTAMP", "CURRENT_TIMESTAMP()"], true)) {
        return " DEFAULT " . $upper;
    }

    return " DEFAULT " . restorePdoLiteral($default);
}

/**
 * Detects types that must not receive DEFAULT in portable MySQL/MariaDB DDL.
 *
 * @param string $sqltype
 * @return bool
 */
function restoreSchemaSqlTypeCannotHaveDefault(string $sqltype): bool {
    return preg_match('/\b(TEXT|BLOB|TINYTEXT|MEDIUMTEXT|LONGTEXT|TINYBLOB|MEDIUMBLOB|LONGBLOB)\b/i', $sqltype) === 1;
}

/**
 * Determines if a field should be AUTO_INCREMENT.
 *
 * @param array $field
 * @return bool
 */
function restoreSchemaFieldAutoIncrement(array $field): bool {
    foreach (["sequence", "auto_increment"] as $key) {
        if (array_key_exists($key, $field) && restoreSchemaBoolean($field[$key])) {
            return true;
        }
    }
    return false;
}

/**
 * Detects whether the column definitions already include a primary key.
 *
 * @param array $columns
 * @return bool
 */
function restoreSchemaHasExplicitPrimaryKey(array $columns): bool {
    foreach ($columns as $column) {
        if (stripos($column, "PRIMARY KEY") !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Finds primary key fields from schema metadata.
 *
 * @param array $fields
 * @return array
 */
function restoreSchemaPrimaryFields(array $fields): array {
    $primary = [];
    foreach ($fields as $field) {
        $name = restoreSafeColumnName(restoreSchemaFieldName($field));
        if ($name === "") {
            continue;
        }
        if (!empty($field["primary"]) || !empty($field["primary_key"])) {
            $primary[] = $name;
        }
    }
    return $primary;
}

/**
 * Builds PRIMARY/foreign/regular keys from schema keys[].
 *
 * @param array $schema
 * @param array $schemafields
 * @return array
 */
function restoreSchemaKeysSql(array $schema, array $schemafields = []): array {
    if (empty($schema["keys"]) || !is_array($schema["keys"])) {
        return [];
    }

    $keys = [];
    foreach ($schema["keys"] as $key) {
        if (!is_array($key)) {
            continue;
        }
        $fields = restoreSchemaIndexFields($key);
        if (empty($fields)) {
            continue;
        }
        $type = strtolower((string) ($key["type"] ?? ""));
        $name = restoreSafeIndexName((string) ($key["name"] ?? implode("_", $fields)));
        $columns = restoreSchemaIndexColumnsSql($fields, $schemafields);
        if (empty($columns)) {
            continue;
        }

        if ($type === "primary" || $name === "primary") {
            $keys[] = "PRIMARY KEY (" . implode(", ", $columns) . ")";
            continue;
        }

        $unique = ($type === "unique" || !empty($key["unique"])) ? "UNIQUE " : "";
        $keys[] = "{$unique}KEY " . restoreQuoteIdentifier($name) . " (" . implode(", ", $columns) . ")";
    }

    return array_values(array_unique($keys));
}

/**
 * Builds simple index SQL definitions when the schema contains indexes[].
 *
 * @param array $schema
 * @param array $schemafields
 * @return array
 */
function restoreSchemaIndexesSql(array $schema, array $schemafields = []): array {
    $indexes = [];
    $rawindexes = [];
    foreach (["indexes", "indices"] as $key) {
        if (!empty($schema[$key]) && is_array($schema[$key])) {
            $rawindexes = array_merge($rawindexes, $schema[$key]);
        }
    }

    foreach ($rawindexes as $index) {
        if (!is_array($index)) {
            continue;
        }
        $fields = restoreSchemaIndexFields($index);
        if (empty($fields)) {
            continue;
        }
        $name = restoreSafeIndexName((string) ($index["name"] ?? implode("_", $fields)));
        if ($name === "primary") {
            continue;
        }
        $columns = restoreSchemaIndexColumnsSql($fields, $schemafields);
        if (empty($columns)) {
            continue;
        }

        $unique = !empty($index["unique"]) ? "UNIQUE " : "";
        $indexes[] = "{$unique}KEY " . restoreQuoteIdentifier($name) . " (" . implode(", ", $columns) . ")";
    }

    return array_values(array_unique($indexes));
}

/**
 * Reads fields/columns from an index/key definition.
 *
 * @param array $index
 * @return array
 */
function restoreSchemaIndexFields(array $index): array {
    $fields = $index["fields"] ?? $index["columns"] ?? [];
    if (is_string($fields)) {
        $fields = preg_split('/\s*,\s*/', $fields) ?: [];
    }
    if (!is_array($fields)) {
        return [];
    }

    $clean = [];
    foreach ($fields as $field) {
        $name = restoreSafeColumnName((string) $field);
        if ($name !== "") {
            $clean[] = $name;
        }
    }
    return $clean;
}

/**
 * Builds quoted index columns, adding prefix length for TEXT/BLOB columns.
 *
 * @param array $fields
 * @param array $schemafields
 * @return array
 */
function restoreSchemaIndexColumnsSql(array $fields, array $schemafields): array {
    $fieldsByName = [];
    foreach ($schemafields as $field) {
        if (!is_array($field) || restoreSchemaFieldName($field) === "") {
            continue;
        }
        $safeName = restoreSafeColumnName(restoreSchemaFieldName($field));
        if ($safeName !== "") {
            $fieldsByName[$safeName] = $field;
        }
    }

    $columns = [];
    foreach ($fields as $field) {
        $name = restoreSafeColumnName($field);
        if ($name === "") {
            continue;
        }
        $fieldmeta = $fieldsByName[$name] ?? [];
        if (!empty($fieldmeta) && restoreSchemaFieldNeedsIndexPrefix($fieldmeta)) {
            $columns[] = restoreQuoteIdentifier($name) . "(191)";
        } else {
            $columns[] = restoreQuoteIdentifier($name);
        }
    }
    return $columns;
}

/**
 * Detects fields that need an index prefix in MySQL/MariaDB.
 *
 * @param array $field
 * @return bool
 */
function restoreSchemaFieldNeedsIndexPrefix(array $field): bool {
    return restoreSchemaSqlTypeCannotHaveDefault(restoreSchemaFieldSqlType($field));
}

/**
 * Normalizes booleans exported as bool/int/string.
 *
 * @param mixed $value
 * @return bool
 */
function restoreSchemaBoolean(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ["1", "true", "yes", "y", "on"], true);
    }
    return false;
}

/**
 * Quotes a literal for generated DDL default values.
 *
 * @param string $value
 * @return string
 */
function restorePdoLiteral(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
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
function restoreImportCsvRows(
    PDO $pdo, string $table, array $insertfields, array $fieldIndexes, array $targetcolumns, string $csvfile
): int {
    $columns = array_map(static fn(array $field): string => $field["name"], $insertfields);
    $placeholders = implode(", ", array_fill(0, count($columns), "?"));
    $quotedColumns = implode(", ", array_map("restoreQuoteIdentifier", $columns));
    $sql = "INSERT INTO " . restoreQuoteIdentifier($table) . " ({$quotedColumns}) VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);

    $handle = fopen($csvfile, "rb");
    if ($handle === false) {
        throw new RuntimeException("Cannot read CSV file: {$csvfile}");
    }

    $count = 0;
    try {
        $pdo->beginTransaction();
        while (($row = fgetcsv($handle, 0, ";", '"', "\\")) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            if ($count === 0 && restoreCsvRowLooksLikeHeader($row, $insertfields, $fieldIndexes)) {
                continue;
            }
            $values = [];
            foreach ($insertfields as $position => $field) {
                $sourceIndex = $fieldIndexes[$position];
                $name = $field["name"];
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
 * Detects and skips optional CSV header rows.
 *
 * @param array $row
 * @param array $insertfields
 * @param array $fieldIndexes
 * @return bool
 */
function restoreCsvRowLooksLikeHeader(array $row, array $insertfields, array $fieldIndexes): bool {
    if (empty($insertfields)) {
        return false;
    }

    $matches = 0;
    foreach ($insertfields as $position => $field) {
        $sourceIndex = $fieldIndexes[$position];
        $expected = strtolower((string) ($field["name"] ?? ""));
        $actual = strtolower(trim((string) ($row[$sourceIndex] ?? "")));
        if ($expected !== "" && $expected === $actual) {
            $matches++;
        }
    }

    return $matches >= max(1, (int) floor(count($insertfields) * 0.8));
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
    $raw = restoreDecodeFriendlyInstallationValue($raw);

    if ($raw === null || $raw === '\\N') {
        return null;
    }

    $type = strtolower(
        trim(
            (string) (restoreSchemaFieldValue(
                $field,
                ["type", "field_type", "data_type", "DATA_TYPE"]
            ) ?? "text")
        )
    );
    $sqltype = strtolower((string) ($targetcolumn["type"] ?? ""));

    if ($raw === "") {
        return restoreConvertEmptyCsvValue($field, $type, $sqltype);
    }

    if (in_array($type, ["datetime", "date", "time", "timestamp"], true)) {
        return restoreConvertDateTimeCsvValue((string) $raw, $type, $sqltype);
    }

    if (in_array($type, ["boolean", "bool"], true)) {
        return in_array(strtolower((string) $raw), ["1", "true", "yes", "y"], true) ? 1 : 0;
    }

    if (restoreCsvSchemaTypeIsInteger($type) || preg_match('/int/i', $sqltype)) {
        return is_numeric($raw) ? (int) $raw : 0;
    }

    if (restoreCsvSchemaTypeIsDecimal($type)) {
        return is_numeric($raw) ? $raw : "0";
    }

    if ($type === "float" || preg_match('/float|double|real/i', $sqltype)) {
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    return is_string($raw) ? $raw : (string) $raw;
}

/**
 * Decodes one friendly_installation CSV cell when it carries an explicit BASE64 marker.
 *
 * @param mixed $value
 * @return mixed
 */
function restoreDecodeFriendlyInstallationValue(mixed $value): mixed {
    $prefix = "__BASE64__:";

    if (is_string($value) && str_starts_with($value, $prefix)) {
        $encoded = substr($value, strlen($prefix));
        $decoded = base64_decode($encoded, true);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    return $value;
}

/**
 * Converts an empty CSV cell according to the schema/default/nullability rules.
 *
 * @param array $field
 * @param string $type
 * @param string $sqltype
 * @return mixed
 */
function restoreConvertEmptyCsvValue(array $field, string $type, string $sqltype): mixed {
    if (restoreSchemaFieldHasDefault($field)) {
        $default = restoreSchemaFieldDefaultValue($field);
        if ($default === null) {
            return restoreSchemaFieldNullable($field) ? null : restoreFallbackValueBySchemaType($type, $sqltype);
        }
        if (is_bool($default)) {
            $default = $default ? "1" : "0";
        }
        if ((string) $default === "") {
            return restoreEmptyDefaultValueBySchemaType($type, $sqltype);
        }
        return restoreConvertCsvValue((string) $default, $field, ["type" => $sqltype]);
    }

    if (restoreSchemaFieldNullable($field)) {
        return null;
    }

    return restoreFallbackValueBySchemaType($type, $sqltype);
}

/**
 * Returns whether the schema contains an explicit default key, including null or empty string.
 *
 * @param array $field
 * @return bool
 */
function restoreSchemaFieldHasDefault(array $field): bool {
    foreach ($field as $key => $_value) {
        if (strtolower((string) $key) === "default") {
            return true;
        }
    }
    return false;
}

/**
 * Returns the explicit default value from schema metadata.
 *
 * @param array $field
 * @return mixed
 */
function restoreSchemaFieldDefaultValue(array $field): mixed {
    foreach ($field as $key => $value) {
        if (strtolower((string) $key) === "default") {
            return $value;
        }
    }
    return null;
}

/**
 * Converts an explicit empty-string schema default for insertion.
 *
 * @param string $type
 * @param string $sqltype
 * @return string|int|float
 */
function restoreEmptyDefaultValueBySchemaType(string $type, string $sqltype): string|int|float {
    if (restoreCsvSchemaTypeIsInteger($type) || preg_match('/int/i', $sqltype)) {
        return 0;
    }
    if (restoreCsvSchemaTypeIsDecimal($type)) {
        return "0";
    }
    if ($type === "float" || preg_match('/float|double|real/i', $sqltype)) {
        return 0.0;
    }
    return "";
}

/**
 * Gives a safe fallback when an empty CSV cell cannot be NULL and has no default.
 *
 * @param string $type
 * @param string $sqltype
 * @return string|int|float
 */
function restoreFallbackValueBySchemaType(string $type, string $sqltype): string|int|float {
    if (restoreCsvSchemaTypeIsInteger($type) || preg_match('/int/i', $sqltype)) {
        return 0;
    }
    if (restoreCsvSchemaTypeIsDecimal($type)) {
        return "0";
    }
    if ($type === "float" || preg_match('/float|double|real/i', $sqltype)) {
        return 0.0;
    }
    if (in_array($type, ["datetime", "timestamp"], true)) {
        return preg_match('/int|decimal|numeric|float|double/', $sqltype) ? 0 : "1970-01-01 00:00:00";
    }
    if ($type === "date") {
        return "1970-01-01";
    }
    if ($type === "time") {
        return "00:00:00";
    }
    return "";
}

/**
 * Checks if a logical CSV schema type is integer-like.
 *
 * @param string $type
 * @return bool
 */
function restoreCsvSchemaTypeIsInteger(string $type): bool {
    return in_array($type, ["int", "integer", "bigint", "smallint", "mediumint", "tinyint", "tinyinteger"], true);
}

/**
 * Checks if a logical CSV schema type is decimal/number-like.
 *
 * @param string $type
 * @return bool
 */
function restoreCsvSchemaTypeIsDecimal(string $type): bool {
    return in_array($type, ["number", "decimal", "numeric"], true);
}

/**
 * Converts date/time values for the target SQL column type.
 *
 * @param string $raw
 * @param string $type
 * @param string $sqltype
 * @return string|int|false
 */
function restoreConvertDateTimeCsvValue(string $raw, string $type, string $sqltype): string|int|false {
    if (preg_match('/int|decimal|numeric|float|double/', $sqltype)) {
        return is_numeric($raw) ? (int) $raw : (strtotime($raw) ?: 0);
    }

    if ($type === "time") {
        $timestamp = strtotime($raw);
        return $timestamp === false ? $raw : gmdate("H:i:s", $timestamp);
    }

    if ($type === "date") {
        $timestamp = is_numeric($raw) ? (int) $raw : strtotime($raw);
        return $timestamp === false ? $raw : gmdate("Y-m-d", $timestamp);
    }

    $timestamp = is_numeric($raw) ? (int) $raw : strtotime($raw);
    return $timestamp === false ? $raw : gmdate("Y-m-d H:i:s", $timestamp);
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
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcedir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $source = $item->getPathname();
        $relative = ltrim(str_replace('\\', "/", substr($source, strlen(rtrim($sourcedir, DIRECTORY_SEPARATOR)))), "/");
        if ($relative === "" || restoreIsIgnoredMoodledataPath($relative)) {
            continue;
        }
        $dest = rtrim($dataroot, "/") . "/{$relative}";
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
    $relative = trim(str_replace('\\', "/", $relative), "/");
    foreach (["backup", "schema", "data"] as $ignored) {
        if ($relative === $ignored || str_starts_with($relative, "{$ignored}/")) {
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
    $php = (string) ($target["php_bin"] ?? "/usr/bin/php");
    $user = (string) ($target["apache_user"] ?? "apache");
    $moodledir = rtrim((string) ($target["moodle_dir"] ?? ""), "/");
    $webroot = rtrim((string) ($target["webroot"] ?? ""), "/");

    $parts = preg_split('/\s+/', trim($command), 2) ?: [];
    $script = $parts[0] ?? "";
    $args = $parts[1] ?? "";

    $candidates = [
        "{$moodledir}/admin/cli/{$script}",
        "{$webroot}/admin/cli/{$script}",
    ];

    $cli = null;
    foreach ($candidates as $candidate) {
        if ($candidate !== "" && is_file($candidate)) {
            $cli = $candidate;
            break;
        }
    }

    if ($cli === null) {
        throw new RuntimeException("Moodle CLI not found after restore: {$script}");
    }

    $cmd = "sudo -u " . escapeshellarg($user) . " " . escapeshellarg($php) . " " . escapeshellarg($cli);
    if ($args !== "") {
        $cmd .= " {$args}";
    }

    exec($cmd, $output, $exitcode);
    if ($exitcode !== 0) {
        throw new RuntimeException("Moodle CLI failed after restore: " . implode("\n", $output));
    }
}

/**
 * Fixes ownership after restore.
 *
 * @param array $target
 * @return void
 */
function restoreFixPermissions(array $target): void {
    $base = (string) ($target["base_dir"] ?? "");
    if ($base === "" || !is_dir($base)) {
        return;
    }
    $user = (string) ($target["apache_user"] ?? "apache");
    $group = (string) ($target["apache_group"] ?? "apache");
    exec("chown -R " . escapeshellarg("{$user}:{$group}") . " " . escapeshellarg($base));
    exec("chmod -R 777 " . escapeshellarg($base));
}

/**
 * Creates the restore PDO connection.
 *
 * @param array $target
 * @return PDO
 */
function restorePdo(array $target): PDO {
    $dbname = (string) ($target["dbname"] ?? "");
    $user = (string) ($target["dbuser"] ?? "");
    $pass = (string) ($target["dbpass"] ?? "");
    $host = (string) ($target["dbhost"] ?? "localhost");
    if ($dbname === "" || $user === "") {
        throw new RuntimeException("Database credentials are missing for restore.");
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
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
    $stmt = $pdo->query("DESCRIBE " . restoreQuoteIdentifier($table));
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row["Field"]] = [
            "type" => $row["Type"],
            "null" => $row["Null"],
            "default" => $row["Default"] ?? null,
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
    $keys = ["dbname", "dbuser", "dbpass", "dbhost", "prefix", "dataroot"];
    $moodleconfig = [];
    foreach ($keys as $key) {
        if (preg_match('/\$CFG->' . preg_quote($key, "/") . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
            $moodleconfig[$key] = stripcslashes($matches[2]);
        }
    }
    return $moodleconfig;
}

/**
 * Quotes an identifier for MySQL.
 *
 * @param string $identifier
 * @return string
 */
function restoreQuoteIdentifier(string $identifier): string {
    return "`" . str_replace("`", "``", $identifier) . "`";
}

/**
 * Sanitizes table names from schema metadata.
 *
 * @param string $table
 * @return string
 */
function restoreSafeTableName(string $table): string {
    $table = preg_replace('/[^a-zA-Z0-9_]+/', "_", $table);
    return trim($table, "_");
}

/**
 * Sanitizes column names from schema metadata.
 *
 * @param string $column
 * @return string
 */
function restoreSafeColumnName(string $column): string {
    $column = preg_replace('/[^a-zA-Z0-9_]+/', "_", $column);
    return trim($column, "_");
}

/**
 * Sanitizes index names from schema metadata.
 *
 * @param string $index
 * @return string
 */
function restoreSafeIndexName(string $index): string {
    $index = preg_replace('/[^a-zA-Z0-9_]+/', "_", strtolower($index));
    $index = trim($index, "_");
    return substr($index !== "" ? $index : "idx", 0, 60);
}

/**
 * Sanitizes schema/data filenames.
 *
 * @param string $name
 * @return string
 */
function restoreSafeFileName(string $name): string {
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', "_", $name);
    $filename = trim($filename, "_");
    return $filename !== "" ? $filename : "table";
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
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

/**
 * Finds the Kopere backup manifest file.
 *
 * @param string $root
 * @return string|null
 */
function restoreFindManifestFile(string $root): ?string {
    $candidates = [
        "{$root}/manifest.json",
        "{$root}/backup/manifest.json",
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->getFilename() !== "manifest.json") {
            continue;
        }

        $manifest = json_decode((string) file_get_contents($item->getPathname()), true);
        if (is_array($manifest) && ($manifest["type"] ?? "") === "restore_moodle") {
            return $item->getPathname();
        }
    }

    return null;
}

/**
 * Ensures the Moodle config table has the required version row before running upgrade.php.
 *
 * @param array $target
 * @param string|null $manifestfile
 * @return array
 */
function restoreEnsureMoodleConfigVersion(array $target, ?string $manifestfile = null): array {
    $pdo = restorePdo($target);
    $prefix = (string) ($target["dbprefix"] ?? "mdl_");
    $table = restoreTargetTableName($prefix, "config");

    if (!restoreTableExists($pdo, $table)) {
        throw new RuntimeException("Moodle config table was not restored: {$table}");
    }
    $sql = "SELECT " . restoreQuoteIdentifier("value") . " FROM " . restoreQuoteIdentifier($table) . " WHERE " .
        restoreQuoteIdentifier("name") . " = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["version"]);
    $version = $stmt->fetchColumn();
    $versionrowexists = $version !== false;
    if ($versionrowexists && trim((string) $version) !== "") {
        return [
            "found" => true,
            "inserted" => false,
            "version" => (string) $version,
            "source" => "backup_config",
        ];
    }

    $source = "backup_manifest";
    $version = restoreReadBackupMoodleVersion($manifestfile);
    if ($version === "") {
        $source = "target_version_file";
        $versioninfo = restoreReadMoodleVersionFile($target);
        $version = (string) ($versioninfo["version"] ?? "");
    }

    if ($version === "") {
        throw new RuntimeException(
            "Config table does not contain the version and no fallback Moodle version was found. Recreate the backup with the updated Kopere Dashboard backup plugin."
        );
    }

    if ($versionrowexists) {
        $sql = "UPDATE " . restoreQuoteIdentifier($table) . " SET " . restoreQuoteIdentifier("value") . " = ? WHERE " .
            restoreQuoteIdentifier("name") . " = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$version, "version"]);
    } else {
        $sql = "INSERT INTO " . restoreQuoteIdentifier($table) . " (" . restoreQuoteIdentifier("name") . ", " .
            restoreQuoteIdentifier("value") . ") VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["version", $version]);
    }

    return [
        "found" => false,
        "inserted" => true,
        "version" => $version,
        "source" => $source,
    ];
}

/**
 * Checks if a table exists in the current database.
 *
 * @param PDO $pdo
 * @param string $table
 * @return bool
 */
function restoreTableExists(PDO $pdo, string $table): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Reads the Moodle version saved by the backup manifest.
 *
 * @param string|null $manifestfile
 * @return string
 */
function restoreReadBackupMoodleVersion(?string $manifestfile): string {
    if ($manifestfile === null || !is_file($manifestfile)) {
        return "";
    }

    $manifest = json_decode((string) file_get_contents($manifestfile), true);
    if (!is_array($manifest)) {
        return "";
    }

    $version = $manifest["source_moodle"]["version"] ?? $manifest["moodle_version"] ?? "";
    return trim((string) $version);
}

/**
 * Reads Moodle version metadata from version.php in the target codebase.
 *
 * @param array $target
 * @return array
 */
function restoreReadMoodleVersionFile(array $target): array {
    $moodledir = rtrim((string) ($target["moodle_dir"] ?? ""), "/");
    $webroot = rtrim((string) ($target["webroot"] ?? ""), "/");
    $candidates = array_unique(array_filter([
        "{$moodledir}/version.php",
        "{$webroot}/version.php",
    ]));

    foreach ($candidates as $candidate) {
        if (!is_readable($candidate)) {
            continue;
        }

        $content = (string) file_get_contents($candidate);
        $info = [];
        if (preg_match('/\$version\s*=\s*([0-9.]+)\s*;/', $content, $matches)) {
            $info["version"] = $matches[1];
        }
        if (preg_match('/\$release\s*=\s*([\'\"])(.*?)\1\s*;/s', $content, $matches)) {
            $info["release"] = $matches[2];
        }
        if (preg_match('/\$branch\s*=\s*([\'\"])(.*?)\1\s*;/s', $content, $matches)) {
            $info["branch"] = $matches[2];
        }

        if (!empty($info)) {
            return $info;
        }
    }

    return [];
}

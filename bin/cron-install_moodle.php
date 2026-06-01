<?php

use app\JobManager;

$domain = $job["domain"] ?? "";
if (!domainHasDnsRecord($domain)) {
    $message =
        "DNS ainda não configurado para {$domain}. Configure o registro A ou AAAA apontando para este servidor. O cron verificará novamente em 1 minuto.";
    appendJobLog($job, $message, "danger");
    JobManager::markWaitingDns((string) $job["id"], $message);
    echo "Job waiting DNS: {$job["id"]} - {$message}\n";
    exit(0);
}

if (($job["status"] ?? "") === "waiting_dns") {
    appendJobLog($job, "DNS detectado para {$domain}. Continuando instalação.");
}

$job = JobManager::markRunning((string) $job["id"]);
if (!$job) {
    throw new RuntimeException("Cannot mark job as running.");
}

$result = executeInstallJob($job);
if ($result["exitcode"] === 0) {
    JobManager::markDone((string) $job["id"]);
    echo "Job completed: {$job["id"]}\n";
} else {
    JobManager::markFailed((string) $job["id"], $result["message"]);
    echo "Job failed: {$job["id"]} - {$result["message"]}\n";
}

function domainHasDnsRecord(string $domain): bool {
    $domain = trim($domain);
    if ($domain === '') {
        return false;
    }
    if (!function_exists('checkdnsrr')) {
        return true;
    }
    return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

function appendJobLog(array $job, string $message, string $level = 'info'): void {
    $domain = $job['domain'] ?? 'domain';
    $logfile = $job['log_file'] ?? (app_config_path("/logs/install-{$domain}.log"));
    if (!is_dir(dirname($logfile))) {
        mkdir(dirname($logfile), 0750, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($level === 'danger') {
        $line = '<span class="log-danger">' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
    file_put_contents($logfile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function apacheConfPath(string $domain): string {
    $os = detectOperatingSystem();

    return match ($os) {
        'debian' => "/etc/apache2/sites-enabled/{$domain}.conf",
        'redhat' => "/etc/httpd/sites-enabled/{$domain}.conf",
        default => is_dir('/etc/apache2/sites-enabled')
            ? "/etc/apache2/sites-enabled/{$domain}.conf"
            : "/etc/httpd/sites-enabled/{$domain}.conf",
    };
}

function detectOperatingSystem(): string {
    $release = readOsRelease();
    $id = strtolower((string) ($release['ID'] ?? ''));
    $idlike = strtolower((string) ($release['ID_LIKE'] ?? ''));
    $tokens = preg_split('/\s+/', trim("{$id} {$idlike}")) ?: [];

    if (array_intersect($tokens, ['debian', 'ubuntu'])) {
        return 'debian';
    }

    if (array_intersect($tokens, ['rhel', 'fedora', 'centos', 'rocky', 'almalinux'])) {
        return 'redhat';
    }

    return 'unknown';
}

function readOsRelease(): array {
    $files = ['/etc/os-release', '/usr/lib/os-release'];

    foreach ($files as $file) {
        if (is_readable($file)) {
            $release = parse_ini_file($file, false, INI_SCANNER_RAW);
            return is_array($release) ? $release : [];
        }
    }

    return [];
}

function executeInstallJob(array $job): array {
    $domain =$job["domain"];
    $base = "/home/{$domain}";
    $moodledir = "{$base}/moodle";
    $webroot = "{$moodledir}/public";

    $dbname = dbName($domain);
    $dbuser = dbUser($domain);
    $dbpass = bin2hex(random_bytes(10)) . "A#";

    $apacheconf = apacheConfPath($domain);
    $nginxconf = "/etc/nginx/sites-enabled/{$domain}.conf";
    $cronfile = "/etc/cron.d/moodle-{$domain}";
    $configfile = "{$moodledir}/config.php";

    $apacheTemplate = renderTemplateFile(app_config_path("/templates/httpd-site.conf"), [
        "DOMAIN" => $domain,
        "MOODLE_DIR" => $moodledir,
        "WEBROOT" => $webroot,
        "BASE_DIR" => $base,
    ]);

    $nginxTemplate = renderTemplateFile(app_config_path("/templates/nginx-site.conf"), [
        "DOMAIN" => $domain,
        "BASE_DIR" => $base,
    ]);

    $dbengine = strtolower((string) (app_config("db_engine") ?: "mysql"));
    $configtemplatefile = match ($dbengine) {
        "mariadb" => "/templates/config-mariadb.php",
        "mysql", "mysqli" => "/templates/config-mysqli.php",
        default => throw new RuntimeException("Invalid database engine configured: {$dbengine}. Use mariadb or mysql."),
    };

    $configTemplate = renderTemplateFile(app_config_path($configtemplatefile), [
        "DB_NAME" => $dbname,
        "DB_USER" => $dbuser,
        "DB_PASS" => $dbpass,
        "DOMAIN" => $domain,
        "BASE_DIR" => $base,
    ]);

    $script = renderTemplateFile(app_config_path("/templates/install-moodle.sh"), [
        "BASE_DIR" => $base,
        "DOMAIN" => $domain,
        "SITE_FULLNAME" => $job["site_fullname"],
        "ADMIN_USER" => $job["admin_user"],
        "ADMIN_PASS_SH" => sh($job["admin_pass"]),
        "ADMIN_EMAIL" => $job["admin_email"],
        "MOODLE_BRANCH" => $job["moodle_branch"],
        "TEMPLATES_DIR" => app_config_path("/templates"),
        "APACHE_USER" => app_config("apache_user"),
        "APACHE_GROUP" => app_config("apache_group"),
        "APACHE_CONF" => $apacheconf,
        "APACHE_TEMPLATE" => $apacheTemplate,
        "NGINX_CONF" => $nginxconf,
        "NGINX_TEMPLATE" => $nginxTemplate,
        "CONFIG_FILE" => $configfile,
        "CONFIG_FILE_TEMPLATE" => $configTemplate,
        "CRON_FILE" => $cronfile,
        "ISSUE_CERT" => !empty($job["issue_cert"]) ? "1" : "0",
        "PHP_BIN" => app_config("php_bin"),
    ]);

    // Creating MySQL database and user
    createMysqlDatabaseAndUser($dbname, $dbuser, $dbpass);

    $scriptfile = app_config_path("/runtime/scripts/install-{$domain}-{$job["id"]}.sh");
    if (!is_dir(dirname($scriptfile))) {
        mkdir(dirname($scriptfile), 0700, true);
    }
    file_put_contents($scriptfile, $script);
    chmod($scriptfile, 0700);

    $logfile = $job["log_file"] ?? (app_config_path("/logs/install-{$domain}.log"));
    if (!is_dir(dirname($logfile))) {
        mkdir(dirname($logfile), 0750, true);
    }

    $cmd = "/usr/bin/env bash " . escapeshellarg($scriptfile) . " >> " . escapeshellarg($logfile) . " 2>&1";
    exec($cmd, $output, $exitcode);
    @unlink($scriptfile);

    return [
        "exitcode" => $exitcode,
        "message" => $exitcode === 0 ? "OK" : "Installer exited with code {$exitcode}. See log: {$logfile}",
    ];
}

function renderTemplateFile(string $file, array $vars): string {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("Cannot read template: {$file}");
    }
    foreach ($vars as $key => $value) {
        $content = str_replace("{{{$key}}}",$value, $content);
    }
    if (preg_match('/\.php$/i', $file)) {
        $content = str_replace('$', '\$', $content);
    }
    return $content;
}

function sh(string $value): string {
    return escapeshellarg($value);
}

function dbName(string $domain): string {
    $name = preg_replace('/[^a-z0-9]+/', "_", strtolower($domain));
    $name = trim((string) $name, "_");
    return substr($name, 0, 60);
}

function dbUser(string $domain): string {
    $name = preg_replace('/[^a-z0-9]+/', "_", strtolower($domain));
    $name = trim((string) $name, "_");
    return substr($name, 0, 30);
}

function createMysqlDatabaseAndUser(string $dbname, string $dbuser, string $dbpass): void {
    $host = app_config("mysql_admin_host", "localhost");
    $port = app_config("mysql_admin_port", 3306);
    $user = app_config("mysql_admin_user", "root");
    $pass = app_config("mysql_admin_pass", "");

    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $quotedUser = $pdo->quote($dbuser);
    $quotedPass = $pdo->quote($dbpass);

    $sql = "CREATE DATABASE IF NOT EXISTS {$dbname} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ";
    $pdo->exec($sql);

    $sql = "CREATE USER IF NOT EXISTS {$quotedUser}@'localhost' IDENTIFIED BY {$quotedPass} ";
    $pdo->exec($sql);

    $sql = "ALTER USER {$quotedUser}@'localhost' IDENTIFIED BY {$quotedPass} ";
    $pdo->exec($sql);

    $sql = "GRANT ALL PRIVILEGES ON {$dbname}.* TO {$quotedUser}@'localhost' ";
    $pdo->exec($sql);

    $sql = "FLUSH PRIVILEGES ";
    $pdo->exec($sql);
}

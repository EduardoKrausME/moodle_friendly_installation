<?php

// Site discovery and diagnostics for installed Software Moodle™ instances.
namespace app;

use PDO;
use Throwable;

/**
 * Class SiteManager
 */
class SiteManager {
    /**
     * Function all
     *
     * @return array
     */
    public static function all(): array {
        $sites = [];
        $moodledirs = self::discoverMoodleDirs();
        foreach ($moodledirs as $moodledir) {
            $site = self::buildBasicSite($moodledir);
            if ($site != null) {
                $sites[] = $site;
            }
        }

        usort($sites, static function(array $a, array $b): int {
            return strcmp(($a["domain"] ?? ''), ($b["domain"] ?? ''));
        });

        return $sites;
    }

    /**
     * Function get
     *
     * @param string $domain
     * @return array|null
     */
    public static function get(string $domain): ?array {
        foreach (self::all() as $site) {
            if (($site["domain"] ?? '') == $domain) {
                return $site;
            }
        }
        return null;
    }

    /**
     * Function details
     *
     * @param string $domain
     * @return array|null
     */
    public static function details(string $domain): ?array {
        $site = self::get($domain);
        if ($site == null) {
            return null;
        }

        $config = self::readMoodleConfig(($site["config_file"] ?? ''));
        $site["moodle_config"] = self::publicConfig($config);
        $site["diagnostics"] = [
            'nginx' => self::checkWebServerConfig('nginx', $site),
            'httpd' => self::checkWebServerConfig('httpd', $site),
            'dns' => self::checkDns(($site["domain"] ?? '')),
            'ssl' => self::checkSsl(($site["domain"] ?? '')),
            'debug' => self::checkDebugMode($site),
            'feature_flags' => self::checkFeatureFlags($site),
        ];
        $site["database_stats"] = self::readDatabaseStats($config);

        return $site;
    }

    /**
     * Function discoverMoodleDirs
     *
     * @return array
     */
    private static function discoverMoodleDirs(): array {
        $dirs = [];

        $pattern = "/home/*/moodle/config.php";
        $configfiles = glob($pattern) ?: [];
        foreach ($configfiles as $configfile) {
            $moodledir = dirname($configfile);
            if (is_file($configfile)) {
                $dirs[realpath($moodledir) ?: $moodledir] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * Function buildBasicSite
     *
     * @param string $moodledir
     * @return array|null
     */
    private static function buildBasicSite(string $moodledir): ?array {
        $configfile = rtrim($moodledir, '/') . '/config.php';
        if (!is_file($configfile)) {
            return null;
        }

        $config = self::readMoodleConfig($configfile);
        $base = dirname($moodledir);
        $publicroot = is_dir($moodledir . '/public') ? $moodledir . '/public' : $moodledir;
        $wwwroot = $config["wwwroot"] ?? '';
        $domain = basename($base);

        if ($wwwroot != '') {
            $host = parse_url($wwwroot, PHP_URL_HOST);
            if (is_string($host) && $host != '') {
                $domain = $host;
            }
        }

        $domain = strtolower(trim($domain));
        $release = self::readMoodleRelease($moodledir, $publicroot);

        $return = [
            'id' => 'site_' . substr(sha1($moodledir), 0, 16),
            'domain' => $domain,
            'status' => 'active',
            'moodle_branch' => $release["release"] ?: ($release["branch"] ?: ''),
            'moodle_release' => $release["release"],
            'moodle_version' => $release["version"],
            'moodle_branch_number' => $release["branch"],
            'base_dir' => $base,
            'moodle_dir' => $moodledir,
            'webroot' => realpath($publicroot) ?: $publicroot,
            'dataroot' => $config["dataroot"] ?? '',
            'config_file' => $configfile,
            'url' => $wwwroot != '' ? $wwwroot : 'https://' . $domain,
            'created_at' => self::formatFileTime($configfile),
        ];

        if (isset($config["dbname"])) {
            $time = time();
            $signature = hash_hmac('sha256', $time, $config["dbname"]);
            $language = I18n::moodleLanguage();
            $hash = "time={$time}&signature={$signature}&dbname={$config["dbname"]}&lang={$language}";
            $return["sso_url"] =
                ($wwwroot != '' ? rtrim($wwwroot, '/') : 'https://' . $domain) . "/moodle-logar-admin.php?{$hash}";
        }

        return $return;
    }

    /**
     * Function readMoodleConfig
     *
     * @param string $configfile
     * @return array
     */
    private static function readMoodleConfig(string $configfile): array {
        if ($configfile == '' || !is_readable($configfile)) {
            return ['_error' => I18n::get('diagnostic.config_not_found')];
        }

        $content = file_get_contents($configfile);
        if ($content == false) {
            return ['_error' => I18n::get('diagnostic.config_not_readable')];
        }

        $keys = [
            'wwwroot', 'dataroot', 'dbtype', 'dblibrary', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'admin', 'sslproxy',
        ];
        $config = [];
        foreach ($keys as $key) {
            $value = self::readCfgValue($content, $key);
            if ($value != null) {
                $config[$key] = $value;
            }
        }

        foreach (['dbport', 'dbsocket', 'dbcollation'] as $key) {
            $value = self::readArrayValue($content, $key);
            if ($value != null) {
                $config[$key] = $value;
            }
        }

        $config["_file"] = $configfile;
        return $config;
    }

    /**
     * Function publicConfig
     *
     * @param array $config
     * @return array
     */
    private static function publicConfig(array $config): array {
        $public = $config;
        if (array_key_exists('dbpass', $public)) {
            $public["dbpass"] = '********';
        }
        return $public;
    }

    /**
     * Function readCfgValue
     *
     * @param string $content
     * @param string $key
     * @return mixed
     */
    private static function readCfgValue(string $content, string $key): mixed {
        $quoted = preg_quote($key, '/');

        if (preg_match('/\$CFG->' . $quoted . '\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s', $content, $matches)) {
            return stripcslashes($matches[2]);
        }

        if (preg_match('/\$CFG->' . $quoted . '\s*=\s*(true|false|null|[0-9]+)\s*;/i', $content, $matches)) {
            $raw = strtolower($matches[1]);
            return match ($raw) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $raw,
            };
        }

        return null;
    }

    /**
     * Function readArrayValue
     *
     * @param string $content
     * @param string $key
     * @return string|null
     */
    private static function readArrayValue(string $content, string $key): ?string {
        $quoted = preg_quote($key, '/');
        if (preg_match('/[\'\"]' . $quoted . '[\'\"]\s*=>\s*([\'\"])((?:\\\\.|(?!\1).)*)\1/s', $content, $matches)) {
            return stripcslashes($matches[2]);
        }
        return null;
    }

    /**
     * Function readMoodleRelease
     *
     * @param string $moodledir
     * @param string $publicroot
     * @return string[]
     */
    private static function readMoodleRelease(string $moodledir, string $publicroot): array {
        $files = [
            rtrim($publicroot, '/') . '/version.php',
            rtrim($moodledir, '/') . '/version.php',
        ];

        $result = [
            'release' => '',
            'version' => '',
            'branch' => '',
        ];

        foreach ($files as $versionfile) {
            if (!is_file($versionfile) || !is_readable($versionfile)) {
                continue;
            }

            $content = file_get_contents($versionfile);
            if ($content == false) {
                continue;
            }

            foreach (['release', 'version', 'branch'] as $key) {
                if (preg_match('/\$' . $key . '\s*=\s*([\'\"])?([^\'\";]+)\1?\s*;/s', $content, $matches)) {
                    $result[$key] = trim($matches[2]);
                }
            }
            break;
        }

        if ($result["release"] == '' && $result["branch"] == '') {
            $result["release"] = I18n::get('diagnostic.version_not_found');
        }

        return $result;
    }

    /**
     * Function checkWebServerConfig
     *
     * @param string $type
     * @param array $site
     * @return array
     */
    private static function checkWebServerConfig(string $type, array $site): array {
        $domain = $site["domain"] ?? '';
        $label = $type == 'nginx' ? 'NGINX' : 'APACHE';
        $file = self::webServerConfigPath($type, $domain);

        if (!is_file($file)) {
            return [
                'status' => 'danger',
                'label' => I18n::get('status.not_found'),
                'message' => I18n::get('diagnostic.webserver_not_found', ['label' => $label]),
                'path' => $file,
            ];
        }

        if (!is_readable($file)) {
            return [
                'status' => 'warning',
                'label' => I18n::get('status.not_readable'),
                'message' => I18n::get('diagnostic.webserver_not_readable', ['label' => $label]),
                'path' => $file,
            ];
        }

        $content = file_get_contents($file);
        if ($content == false) {
            return [
                'status' => 'warning',
                'label' => I18n::get('status.not_readable'),
                'message' => I18n::get('diagnostic.webserver_read_failed', ['label' => $label]),
                'path' => $file,
            ];
        }

        $hasDomain = stripos($content, $domain) != false;
        $hasRoot = !empty($site["webroot"]) && stripos($content, $site["webroot"]) != false;

        if ($hasDomain) {
            return [
                'status' => $hasRoot || $type == 'nginx' ? 'ok' : 'warning',
                'label' => $hasRoot || $type == 'nginx' ? I18n::get('status.ok') : I18n::get('status.check_root'),
                'message' => $hasRoot || $type == 'nginx'
                    ? I18n::get('diagnostic.webserver_configured', ['label' => $label])
                    : I18n::get('diagnostic.webserver_domain_no_root', ['label' => $label]),
                'path' => $file,
            ];
        }

        return [
            'status' => 'warning',
            'label' => I18n::get('status.check'),
            'message' => I18n::get('diagnostic.webserver_domain_missing', ['label' => $label]),
            'path' => $file,
        ];
    }

    /**
     * Function webServerConfigPath
     *
     * @param string $type
     * @param string $domain
     * @return string
     */
    private static function webServerConfigPath(string $type, string $domain): string {
        if ($type == 'nginx') {
            return "/etc/nginx/sites-enabled/{$domain}.conf";
        }

        return match (self::detectOperatingSystem()) {
            'debian' => "/etc/apache2/sites-enabled/{$domain}.conf",
            'redhat' => "/etc/httpd/sites-enabled/{$domain}.conf",
            default => is_dir('/etc/apache2/sites-enabled')
                ? "/etc/apache2/sites-enabled/{$domain}.conf"
                : "/etc/httpd/sites-enabled/{$domain}.conf",
        };
    }

    /**
     * Function detectOperatingSystem
     *
     * @return string
     */
    private static function detectOperatingSystem(): string {
        $release = self::readOsRelease();
        $id = strtolower((string) ($release["ID"] ?? ''));
        $idlike = strtolower((string) ($release["ID_LIKE"] ?? ''));
        $tokens = preg_split('/\s+/', trim("{$id} {$idlike}")) ?: [];

        if (array_intersect($tokens, ['debian', 'ubuntu'])) {
            return 'debian';
        }

        if (array_intersect($tokens, ['rhel', 'fedora', 'centos', 'rocky', 'almalinux'])) {
            return 'redhat';
        }

        return 'unknown';
    }

    /**
     * Function readOsRelease
     *
     * @return array
     */
    private static function readOsRelease(): array {
        foreach (['/etc/os-release', '/usr/lib/os-release'] as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $release = parse_ini_file($file, false, INI_SCANNER_RAW);
            return is_array($release) ? $release : [];
        }

        return [];
    }

    /**
     * Function checkDns
     *
     * @param string $domain
     * @return array
     */
    private static function checkDns(string $domain): array {
        $records = self::dnsRecords($domain);
        $resolvedIps = array_values(array_unique(array_filter(array_merge($records["A"], $records["AAAA"]))));
        $serverIps = self::serverIps();
        $matches = array_values(array_intersect($resolvedIps, $serverIps));

        if (!$resolvedIps) {
            return [
                'status' => 'danger',
                'label' => I18n::get('status.no_dns'),
                'message' => I18n::get('diagnostic.dns_not_found'),
                'resolved_ips' => [],
                'server_ips' => $serverIps,
                'matches' => [],
            ];
        }

        if ($matches) {
            return [
                'status' => 'ok',
                'label' => I18n::get('status.ok'),
                'message' => I18n::get('diagnostic.dns_ok'),
                'resolved_ips' => $resolvedIps,
                'server_ips' => $serverIps,
                'matches' => $matches,
            ];
        }

        return [
            'status' => 'warning',
            'label' => I18n::get('status.check_ip'),
            'message' => I18n::get('diagnostic.dns_ip_mismatch'),
            'resolved_ips' => $resolvedIps,
            'server_ips' => $serverIps,
            'matches' => [],
        ];
    }

    /**
     * Function dnsRecords
     *
     * @param string $domain
     * @return array|array[]
     */
    private static function dnsRecords(string $domain): array {
        $records = ['A' => [], 'AAAA' => []];
        if ($domain == '') {
            return $records;
        }

        if (function_exists('dns_get_record')) {
            $dns = @dns_get_record($domain, DNS_A + DNS_AAAA);
            if (is_array($dns)) {
                foreach ($dns as $record) {
                    if (($record["type"] ?? '') == 'A' && !empty($record["ip"])) {
                        $records["A"][] = $record["ip"];
                    }
                    if (($record["type"] ?? '') == 'AAAA' && !empty($record["ipv6"])) {
                        $records["AAAA"][] = $record["ipv6"];
                    }
                }
            }
        }

        if (!$records["A"] && function_exists('gethostbynamel')) {
            $fallback = @gethostbynamel($domain);
            if (is_array($fallback)) {
                $records["A"] = $fallback;
            }
        }

        $records["A"] = array_values(array_unique($records["A"]));
        $records["AAAA"] = array_values(array_unique($records["AAAA"]));
        return $records;
    }

    /**
     * Function serverIps
     *
     * @return array
     */
    private static function serverIps(): array {
        $ips = [];

        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ips[] = $_SERVER[$key];
            }
        }

        if (!empty($_SERVER["HTTP_HOST"])) {
            $host = preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);
            $hostIps = @gethostbynamel($host);
            if (is_array($hostIps)) {
                $ips = array_merge($ips, $hostIps);
            }
        }

        if (function_exists('shell_exec')) {
            $output = @shell_exec('hostname -I 2>/dev/null');
            if (is_string($output)) {
                $ips = array_merge($ips, preg_split('/\s+/', trim($output)) ?: []);
            }
        }

        $ips = array_values(array_unique(array_filter(array_map('trim', $ips), static function(string $ip): bool {
            return filter_var($ip, FILTER_VALIDATE_IP) != false;
        })));

        return $ips;
    }

    /**
     * Function checkSsl
     *
     * @param string $domain
     * @return array
     */
    private static function checkSsl(string $domain): array {
        if ($domain == '') {
            return [
                'status' => 'danger',
                'label' => I18n::get('status.no_domain'),
                'message' => I18n::get('diagnostic.empty_domain'),
            ];
        }

        $result = self::readSslCertificate($domain, true);
        $verified = $result["connected"];
        if (!$verified) {
            $fallback = self::readSslCertificate($domain, false);
            if ($fallback["connected"]) {
                $result = $fallback;
            }
        }

        if (!$result["connected"]) {
            return [
                'status' => 'danger',
                'label' => I18n::get('status.ssl_error'),
                'message' => $result["error"] ?: I18n::get('diagnostic.ssl_connect_error'),
            ];
        }

        $cert = $result["certificate"] ?? [];
        $validTo = $cert["validTo_time_t"] ?? 0;
        $validFrom = $cert["validFrom_time_t"] ?? 0;
        $now = time();
        $days = $validTo > 0 ? floor(($validTo - $now) / 86400) : null;

        if ($verified && $validFrom <= $now && $validTo > $now) {
            return [
                'status' => $days != null && $days < 15 ? 'warning' : 'ok',
                'label' => $days != null && $days < 15 ? I18n::get('status.expires_soon') : I18n::get('status.ok'),
                'message' => $days != null ? I18n::get('diagnostic.ssl_valid_days', ['days' => $days]) :
                    I18n::get('diagnostic.ssl_valid'),
                'issuer' => self::certName($cert["issuer"] ?? []),
                'subject' => self::certName($cert["subject"] ?? []),
                'valid_from' => $validFrom ? date('Y-m-d H:i:s', $validFrom) : '',
                'valid_to' => $validTo ? date('Y-m-d H:i:s', $validTo) : '',
                'days_left' => $days,
            ];
        }

        if ($validTo > 0 && $validTo <= $now) {
            return [
                'status' => 'danger',
                'label' => I18n::get('status.expired'),
                'message' => I18n::get('diagnostic.ssl_expired'),
                'issuer' => self::certName($cert["issuer"] ?? []),
                'subject' => self::certName($cert["subject"] ?? []),
                'valid_to' => date('Y-m-d H:i:s', $validTo),
                'days_left' => $days,
            ];
        }

        return [
            'status' => 'warning',
            'label' => I18n::get('status.check'),
            'message' => $verified ? I18n::get('diagnostic.ssl_not_confirmed') :
                I18n::get('diagnostic.ssl_validation_failed'),
            'issuer' => self::certName($cert["issuer"] ?? []),
            'subject' => self::certName($cert["subject"] ?? []),
            'valid_from' => $validFrom ? date('Y-m-d H:i:s', $validFrom) : '',
            'valid_to' => $validTo ? date('Y-m-d H:i:s', $validTo) : '',
            'days_left' => $days,
        ];
    }

    /**
     * Function readSslCertificate
     *
     * @param string $domain
     * @param bool $verify
     * @return array
     */
    private static function readSslCertificate(string $domain, bool $verify): array {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => false,
                'peer_name' => $domain,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$client) {
            return [
                'connected' => false,
                'error' => $errstr ?: ($errno ? I18n::get('status.error') . ' ' . $errno : ''),
                'certificate' => [],
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params["options"]["ssl"]["peer_certificate"] ?? null;
        if (!$cert) {
            return [
                'connected' => true,
                'error' => I18n::get('diagnostic.ssl_read_error'),
                'certificate' => [],
            ];
        }

        $parsed = openssl_x509_parse($cert);
        return [
            'connected' => true,
            'error' => '',
            'certificate' => is_array($parsed) ? $parsed : [],
        ];
    }

    /**
     * Function certName
     *
     * @param array $data
     * @return string
     */
    private static function certName(array $data): string {
        if (!empty($data["CN"])) {
            return $data["CN"];
        }
        if (!empty($data["O"])) {
            return $data["O"];
        }
        return '';
    }

    /**
     * Function readDatabaseStats
     *
     * @param array $config
     * @return array
     */
    private static function readDatabaseStats(array $config): array {
        $host = app_config("mysql_admin_host", "localhost");
        $port = app_config("mysql_admin_port", 3306);
        $user = app_config("mysql_admin_user", "root");
        $pass = app_config("mysql_admin_pass", "");

        if (isset($config["dbname"])) {
            $dsn = "mysql:host={$host};port={$port};dbname={$config["dbname"]};charset=utf8mb4";
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                $items = [
                    'users' => self::countQuery($pdo, "SELECT COUNT(*) FROM mdl_user WHERE deleted = 0 AND id > 1"),
                    'courses' => self::countQuery($pdo, "SELECT COUNT(*) FROM mdl_course WHERE id > 1"),
                    'enrolments' => self::countQuery(
                        $pdo,
                        "SELECT COUNT(*)
                       FROM mdl_user_enrolments ue
                       JOIN mdl_enrol e ON e.id = ue.enrolid
                      WHERE e.courseid > 1"
                    ),
                    'active_enrolments' => self::countQuery(
                        $pdo,
                        "SELECT COUNT(*)
                       FROM mdl_user_enrolments ue
                       JOIN mdl_enrol e ON e.id = ue.enrolid
                      WHERE e.courseid > 1 AND ue.status = 0 AND e.status = 0"
                    ),
                ];

                return [
                    'connected' => true,
                    'error' => '',
                    'items' => $items,
                ];
            } catch (Throwable $e) {
                return [
                    'connected' => false,
                    'error' => $e->getMessage(),
                    'items' => [],
                ];
            }
        }
        return [
            'connected' => false,
            'error' => I18n::get('diagnostic.config_not_readable'),
            'items' => [],
        ];
    }

    /**
     * Function countQuery
     *
     * @param \PDO $pdo
     * @param string $sql
     * @return int
     */
    private static function countQuery(PDO $pdo, string $sql): int {
        $value = $pdo->query($sql)->fetchColumn();
        return $value;
    }

    /**
     * Function formatFileTime
     *
     * @param string $file
     * @return string
     */
    private static function formatFileTime(string $file): string {
        $time = @filemtime($file);
        return $time ? date('Y-m-d H:i:s', $time) : '';
    }

    /**
     * Function setDebugMode
     *
     * @param array $site
     * @param bool $enabled
     * @return array
     */
    public static function setDebugMode(array $site, bool $enabled): array {
        return self::setFeatureFlag($site, 'debug', $enabled);
    }

    /**
     * Function setFeatureFlag
     *
     * @param array $site
     * @param string $flag
     * @param bool $enabled
     * @param string|null $value
     * @return array
     */
    public static function setFeatureFlag(array $site, string $flag, bool $enabled, ?string $value = null): array {
        $definitions = self::featureFlagDefinitions();
        if (!isset($definitions[$flag])) {
            return [
                'ok' => false,
                'message' => I18n::get('diagnostic.flag_invalid'),
            ];
        }

        $definition = $definitions[$flag];
        if (($definition["handler"] ?? '') == 'maintenance') {
            return self::setMaintenanceMode($site, $enabled);
        }

        if (!empty($definition["value_type"]) && $definition["value_type"] == 'email' && $enabled) {
            $email = trim($value);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'ok' => false,
                    'message' => I18n::get('diagnostic.email_redirect_invalid'),
                ];
            }
            return self::writeFeatureFlagFile($site, $definition, true, $email . PHP_EOL);
        }

        return self::writeFeatureFlagFile($site, $definition, $enabled);
    }

    /**
     * Function featureFlagDefinitions
     *
     * @return array[]
     */
    private static function featureFlagDefinitions(): array {
        return [
            'debug' => [
                'label' => I18n::get('feature_flags.debug_label'),
                'file' => 'debug.enable',
                'description' => I18n::get('feature_flags.debug_description'),
                'enabled_label' => I18n::get('status.enabled'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'maintenance' => [
                'label' => I18n::get('feature_flags.maintenance_label'),
                'file' => 'maintenance.enable',
                'description' => I18n::get('feature_flags.maintenance_description'),
                'enabled_label' => I18n::get('status.maintenance'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'danger',
                'handler' => 'maintenance',
                'dangerous' => true,
            ],
            'cron_disable' => [
                'label' => I18n::get('feature_flags.cron_disable_label'),
                'file' => 'cron.disable',
                'description' => I18n::get('feature_flags.cron_disable_description'),
                'enabled_label' => I18n::get('status.paused'),
                'disabled_label' => I18n::get('status.active'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'email_disable' => [
                'label' => I18n::get('feature_flags.email_disable_label'),
                'file' => 'email.disable',
                'description' => I18n::get('feature_flags.email_disable_description'),
                'enabled_label' => I18n::get('status.blocked'),
                'disabled_label' => I18n::get('status.released'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'email_redirect' => [
                'label' => I18n::get('feature_flags.email_redirect_label'),
                'file' => 'email.redirect',
                'description' => I18n::get('feature_flags.email_redirect_description'),
                'enabled_label' => I18n::get('status.redirecting'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
                'value_type' => 'email',
            ],
            'theme_designer' => [
                'label' => I18n::get('feature_flags.theme_designer_label'),
                'file' => 'theme-designer.enable',
                'description' => I18n::get('feature_flags.theme_designer_description'),
                'enabled_label' => I18n::get('status.enabled'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'cache_dev' => [
                'label' => I18n::get('feature_flags.cache_dev_label'),
                'file' => 'cache-dev.enable',
                'description' => I18n::get('feature_flags.cache_dev_description'),
                'enabled_label' => I18n::get('status.no_cache_dev'),
                'disabled_label' => I18n::get('status.normal_cache'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'cron_debug' => [
                'label' => I18n::get('feature_flags.cron_debug_label'),
                'file' => 'cron-debug.enable',
                'description' => I18n::get('feature_flags.cron_debug_description'),
                'enabled_label' => I18n::get('status.enabled'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
            ],
            'slow_sql' => [
                'label' => I18n::get('feature_flags.slow_sql_label'),
                'file' => 'slow-sql.enable',
                'description' => I18n::get('feature_flags.slow_sql_description'),
                'enabled_label' => I18n::get('status.enabled'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'perf' => [
                'label' => I18n::get('feature_flags.perf_label'),
                'file' => 'perf.enable',
                'description' => I18n::get('feature_flags.perf_description'),
                'enabled_label' => I18n::get('status.enabled'),
                'disabled_label' => I18n::get('status.disabled'),
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
        ];
    }

    /**
     * Function checkFeatureFlags
     *
     * @param array $site
     * @return array
     */
    private static function checkFeatureFlags(array $site): array {
        $items = [];
        foreach (self::featureFlagDefinitions() as $key => $definition) {
            $file = self::featureFlagFile($site, $definition["file"]);
            $enabled = $file != '' && is_file($file);
            $value = '';

            if ($enabled && !empty($definition["value_type"]) && is_readable($file)) {
                $value = trim(file_get_contents($file));
            }

            $description = $definition["description"] ?? '';
            if ($key == 'email_redirect' && $enabled && $value != '') {
                $description .= ' ' . I18n::get('diagnostic.email_current', ['email' => $value]);
            }

            $items[$key] = [
                'label' => $definition["label"] ?? $key,
                'description' => $description,
                'path' => $file,
                'enabled' => $enabled,
                'value' => $value,
                'value_type' => $definition["value_type"] ?? '',
                'dangerous' => !empty($definition["dangerous"]),
                'status' => $enabled ? ($definition["enabled_status"] ?? 'warning') : 'ok',
                'status_label' => $enabled ? ($definition["enabled_label"] ?? I18n::get('status.enabled')) :
                    ($definition["disabled_label"] ?? I18n::get('status.disabled')),
            ];
        }

        return $items;
    }

    /**
     * Function writeFeatureFlagFile
     *
     * @param array $site
     * @param array $definition
     * @param bool $enabled
     * @param string|null $content
     * @return array
     */
    private static function writeFeatureFlagFile(array $site, array $definition, bool $enabled, ?string $content = null): array {
        $file = self::featureFlagFile($site, ($definition["file"] ?? ''));
        $label = $definition["label"] ?? 'Flag';
        if ($file == '') {
            return [
                'ok' => false,
                'message' => I18n::get('diagnostic.flag_path_missing'),
            ];
        }

        if ($enabled) {
            $content = $content ?? ('enabled_at=' . date('c') . PHP_EOL);
            if (@file_put_contents($file, $content, LOCK_EX) == false) {
                return [
                    'ok' => false,
                    'message' => I18n::get('diagnostic.flag_create_failed', ['file' => $file]),
                ];
            }
            @chmod($file, 0640);
            return [
                'ok' => true,
                'message' => I18n::get(
                    'diagnostic.flag_enabled', ['label' => $label, 'domain' => ($site["domain"] ?? 'este Moodle')]
                ),
            ];
        }

        if (is_file($file) && !@unlink($file)) {
            return [
                'ok' => false,
                'message' => I18n::get('diagnostic.flag_delete_failed', ['file' => $file]),
            ];
        }

        return [
            'ok' => true,
            'message' => I18n::get('diagnostic.flag_disabled', ['label' => $label, 'domain' => ($site["domain"] ?? 'este Moodle')]),
        ];
    }

    /**
     * Function setMaintenanceMode
     *
     * @param array $site
     * @param bool $enabled
     * @return array
     */
    private static function setMaintenanceMode(array $site, bool $enabled): array {
        $cli = self::moodleCliFile($site, 'maintenance.php');
        if ($cli == '') {
            return [
                'ok' => false,
                'message' => I18n::get('diagnostic.maintenance_cli_missing'),
            ];
        }

        $phpbin = app_config('php_bin') ?: PHP_BINARY ?: '/usr/bin/php';
        $command = escapeshellarg($phpbin) . ' ' . escapeshellarg($cli) . ' ' . ($enabled ? '--enable' : '--disable') . ' 2>&1';
        $output = [];
        $exitcode = 0;
        exec($command, $output, $exitcode);

        if ($exitcode != 0) {
            $message = trim(implode("\n", $output));
            return [
                'ok' => false,
                'message' => I18n::get(
                    'diagnostic.maintenance_failed', ['message' => ($message != '' ? $message : 'exit code ' . $exitcode)]
                ),
            ];
        }

        $definitions = self::featureFlagDefinitions();
        $result = self::writeFeatureFlagFile($site, $definitions["maintenance"], $enabled);
        if (!$result["ok"]) {
            return $result;
        }

        $result["message"] = I18n::get(
            'diagnostic.maintenance_done', [
                'state' => ($enabled ? I18n::get('diagnostic.maintenance_enabled') :
                    I18n::get('diagnostic.maintenance_disabled')), 'domain' => ($site["domain"] ?? 'este Moodle'),
            ]
        );
        return $result;
    }

    /**
     * Function moodleCliFile
     *
     * @param array $site
     * @param string $filename
     * @return string
     */
    private static function moodleCliFile(array $site, string $filename): string {
        $moodledir = rtrim($site["moodle_dir"] ?? '', '/');
        if ($moodledir == '') {
            return '';
        }

        $candidates = [
            $moodledir . '/admin/cli/' . $filename,
            $moodledir . '/public/admin/cli/' . $filename,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Function debugFile
     *
     * @param array $site
     * @return string
     */
    private static function debugFile(array $site): string {
        return self::featureFlagFile($site, 'debug.enable');
    }

    /**
     * Function featureFlagFile
     *
     * @param array $site
     * @param string $filename
     * @return string
     */
    private static function featureFlagFile(array $site, string $filename): string {
        $base = rtrim($site["base_dir"] ?? '', '/');
        $filename = ltrim($filename, '/');
        if ($base == '' || $filename == '') {
            return '';
        }
        return $base . '/' . $filename;
    }

    /**
     * Function checkDebugMode
     *
     * @param array $site
     * @return array
     */
    private static function checkDebugMode(array $site): array {
        $flags = self::checkFeatureFlags($site);
        $debug = $flags["debug"] ?? null;
        if ($debug == null) {
            return [
                'status' => 'muted',
                'label' => '-',
                'path' => '',
                'enabled' => false,
            ];
        }

        return [
            'status' => $debug["status"] ?? 'muted',
            'label' => $debug["status_label"] ?? '-',
            'path' => $debug["path"] ?? '',
            'enabled' => !empty($debug["enabled"]),
        ];
    }
}

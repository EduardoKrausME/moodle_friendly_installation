<?php

// Site discovery and diagnostics for installed Moodle instances.
namespace app;

use PDO;
use Throwable;

class SiteManager {
    public static function all(): array {
        $sites = [];
        foreach (self::discoverMoodleDirs() as $moodledir) {
            $site = self::buildBasicSite($moodledir);
            if ($site !== null) {
                $sites[] = $site;
            }
        }

        usort($sites, static function(array $a, array $b): int {
            return strcmp((string) ($a['domain'] ?? ''), (string) ($b['domain'] ?? ''));
        });

        return $sites;
    }

    public static function get(string $domain): ?array {
        foreach (self::all() as $site) {
            if (($site['domain'] ?? '') === $domain) {
                return $site;
            }
        }
        return null;
    }

    public static function details(string $domain): ?array {
        $site = self::get($domain);
        if ($site === null) {
            return null;
        }

        $config = self::readMoodleConfig((string) ($site['config_file'] ?? ''));
        $site['moodle_config'] = self::publicConfig($config);
        $site['diagnostics'] = [
            'nginx' => self::checkWebServerConfig('nginx', $site),
            'httpd' => self::checkWebServerConfig('httpd', $site),
            'dns' => self::checkDns((string) ($site['domain'] ?? '')),
            'ssl' => self::checkSsl((string) ($site['domain'] ?? '')),
            'debug' => self::checkDebugMode($site),
            'feature_flags' => self::checkFeatureFlags($site),
        ];
        $site['database_stats'] = self::readDatabaseStats($config);

        return $site;
    }

    private static function discoverMoodleDirs(): array {
        $dirs = [];
        $homebase = rtrim((string) (app_config('home_base_dir') ?: '/home'), '/');

        $patterns = [
            $homebase . '/*/moodle/config.php',
            $homebase . '/*/config.php',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $configfile) {
                $moodledir = dirname($configfile);
                if (is_file($configfile)) {
                    $dirs[realpath($moodledir) ?: $moodledir] = true;
                }
            }
        }

        foreach (glob($homebase . '/*/moodle/public/admin/cli/cron.php') ?: [] as $cronfile) {
            $moodledir = dirname($cronfile, 4);
            if (is_file($moodledir . '/config.php')) {
                $dirs[realpath($moodledir) ?: $moodledir] = true;
            }
        }

        foreach (glob($homebase . '/*/moodle/admin/cli/cron.php') ?: [] as $cronfile) {
            $moodledir = dirname($cronfile, 3);
            if (is_file($moodledir . '/config.php')) {
                $dirs[realpath($moodledir) ?: $moodledir] = true;
            }
        }

        return array_keys($dirs);
    }

    private static function buildBasicSite(string $moodledir): ?array {
        $configfile = rtrim($moodledir, '/') . '/config.php';
        if (!is_file($configfile)) {
            return null;
        }

        $config = self::readMoodleConfig($configfile);
        $base = dirname($moodledir);
        $publicroot = is_dir($moodledir . '/public') ? $moodledir . '/public' : $moodledir;
        $wwwroot = (string) ($config['wwwroot'] ?? '');
        $domain = basename($base);

        if ($wwwroot !== '') {
            $host = parse_url($wwwroot, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = $host;
            }
        }

        $domain = strtolower(trim($domain));
        $release = self::readMoodleRelease($moodledir, $publicroot);

        $time = time();
        $signature = hash_hmac('sha256', (string) $time, $config["dbname"]);
        $hash = "time={$time}&signature={$signature}&dbname={$config["dbname"]}";

        return [
            'id' => 'site_' . substr(sha1($moodledir), 0, 16),
            'domain' => $domain,
            'status' => 'active',
            'moodle_branch' => $release['release'] ?: ($release['branch'] ?: ''),
            'moodle_release' => $release['release'],
            'moodle_version' => $release['version'],
            'moodle_branch_number' => $release['branch'],
            'base_dir' => $base,
            'moodle_dir' => $moodledir,
            'webroot' => realpath("{$publicroot}/../.."),
            'dataroot' => (string) ($config['dataroot'] ?? ''),
            'config_file' => $configfile,
            'url' => $wwwroot !== '' ? $wwwroot : 'https://' . $domain,
            'sso_url' => ($wwwroot !== '' ? rtrim($wwwroot, '/') : 'https://' . $domain) . "/moodle-logar-admin.php?{$hash}",
            'created_at' => self::formatFileTime($configfile),
        ];
    }

    private static function readMoodleConfig(string $configfile): array {
        if ($configfile === '' || !is_readable($configfile)) {
            return ['_error' => 'Arquivo config.php não encontrado ou sem permissão de leitura.'];
        }

        $content = file_get_contents($configfile);
        if ($content === false) {
            return ['_error' => 'Não foi possível ler o arquivo config.php.'];
        }

        $keys = [
            'wwwroot', 'dataroot', 'dbtype', 'dblibrary', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'admin', 'sslproxy',
        ];
        $config = [];
        foreach ($keys as $key) {
            $value = self::readCfgValue($content, $key);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        foreach (['dbport', 'dbsocket', 'dbcollation'] as $key) {
            $value = self::readArrayValue($content, $key);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        $config['_file'] = $configfile;
        return $config;
    }

    private static function publicConfig(array $config): array {
        $public = $config;
        if (array_key_exists('dbpass', $public)) {
            $public['dbpass'] = '********';
        }
        return $public;
    }

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
                default => (int) $raw,
            };
        }

        return null;
    }

    private static function readArrayValue(string $content, string $key): ?string {
        $quoted = preg_quote($key, '/');
        if (preg_match('/[\'\"]' . $quoted . '[\'\"]\s*=>\s*([\'\"])((?:\\\\.|(?!\1).)*)\1/s', $content, $matches)) {
            return stripcslashes($matches[2]);
        }
        return null;
    }

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
            if ($content === false) {
                continue;
            }

            foreach (['release', 'version', 'branch'] as $key) {
                if (preg_match('/\$' . $key . '\s*=\s*([\'\"])?([^\'\";]+)\1?\s*;/s', $content, $matches)) {
                    $result[$key] = trim($matches[2]);
                }
            }
            break;
        }

        if ($result['release'] === '' && $result['branch'] === '') {
            $result['release'] = 'version.php não encontrado';
        }

        return $result;
    }

    private static function checkWebServerConfig(string $type, array $site): array {
        $domain = (string) ($site['domain'] ?? '');
        $key = $type === 'nginx' ? 'nginx_sites_enabled' : 'apache_sites_enabled';
        $label = $type === 'nginx' ? 'NGINX' : 'APACHE';
        $dir = rtrim(app_config($key), '/');
        $file = $dir . '/' . $domain . '.conf';

        if (!is_file($file)) {
            return [
                'status' => 'danger',
                'label' => 'Não encontrado',
                'message' => "Arquivo {$label} não encontrado para este domínio.",
                'path' => $file,
            ];
        }

        if (!is_readable($file)) {
            return [
                'status' => 'warning',
                'label' => 'Sem leitura',
                'message' => "Arquivo {$label} existe, mas o painel não tem permissão para ler.",
                'path' => $file,
            ];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [
                'status' => 'warning',
                'label' => 'Sem leitura',
                'message' => "Não foi possível ler o arquivo {$label}.",
                'path' => $file,
            ];
        }

        $hasDomain = stripos($content, $domain) !== false;
        $hasRoot = !empty($site['webroot']) && stripos($content, (string) $site['webroot']) !== false;

        if ($hasDomain) {
            return [
                'status' => $hasRoot || $type === 'nginx' ? 'ok' : 'warning',
                'label' => $hasRoot || $type === 'nginx' ? 'OK' : 'Conferir root',
                'message' => $hasRoot || $type === 'nginx'
                    ? "{$label} configurado para o domínio."
                    : "{$label} contém o domínio, mas o DocumentRoot não foi confirmado no arquivo.",
                'path' => $file,
            ];
        }

        return [
            'status' => 'warning',
            'label' => 'Conferir',
            'message' => "Arquivo {$label} existe, mas o domínio não foi localizado no conteúdo.",
            'path' => $file,
        ];
    }

    private static function checkDns(string $domain): array {
        $records = self::dnsRecords($domain);
        $resolvedIps = array_values(array_unique(array_filter(array_merge($records['A'], $records['AAAA']))));
        $serverIps = self::serverIps();
        $matches = array_values(array_intersect($resolvedIps, $serverIps));

        if (!$resolvedIps) {
            return [
                'status' => 'danger',
                'label' => 'Sem DNS',
                'message' => 'Nenhum registro A ou AAAA foi encontrado.',
                'resolved_ips' => [],
                'server_ips' => $serverIps,
                'matches' => [],
            ];
        }

        if ($matches) {
            return [
                'status' => 'ok',
                'label' => 'OK',
                'message' => 'DNS aponta para um IP conhecido deste servidor.',
                'resolved_ips' => $resolvedIps,
                'server_ips' => $serverIps,
                'matches' => $matches,
            ];
        }

        return [
            'status' => 'warning',
            'label' => 'Conferir IP',
            'message' => 'DNS existe, mas não bateu com os IPs locais/configurados conhecidos pelo painel.',
            'resolved_ips' => $resolvedIps,
            'server_ips' => $serverIps,
            'matches' => [],
        ];
    }

    private static function dnsRecords(string $domain): array {
        $records = ['A' => [], 'AAAA' => []];
        if ($domain === '') {
            return $records;
        }

        if (function_exists('dns_get_record')) {
            $dns = @dns_get_record($domain, DNS_A + DNS_AAAA);
            if (is_array($dns)) {
                foreach ($dns as $record) {
                    if (($record['type'] ?? '') === 'A' && !empty($record['ip'])) {
                        $records['A'][] = (string) $record['ip'];
                    }
                    if (($record['type'] ?? '') === 'AAAA' && !empty($record['ipv6'])) {
                        $records['AAAA'][] = (string) $record['ipv6'];
                    }
                }
            }
        }

        if (!$records['A'] && function_exists('gethostbynamel')) {
            $fallback = @gethostbynamel($domain);
            if (is_array($fallback)) {
                $records['A'] = $fallback;
            }
        }

        $records['A'] = array_values(array_unique($records['A']));
        $records['AAAA'] = array_values(array_unique($records['AAAA']));
        return $records;
    }

    private static function serverIps(): array {
        $ips = [];
        $configured = app_config('server_public_ips') ?: [];
        if (is_string($configured) && $configured !== '') {
            $configured = array_map('trim', explode(',', $configured));
        }
        if (is_array($configured)) {
            $ips = array_merge($ips, $configured);
        }

        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ips[] = (string) $_SERVER[$key];
            }
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
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
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        })));

        return $ips;
    }

    private static function checkSsl(string $domain): array {
        if ($domain === '') {
            return [
                'status' => 'danger',
                'label' => 'Sem domínio',
                'message' => 'Domínio vazio.',
            ];
        }

        $result = self::readSslCertificate($domain, true);
        $verified = $result['connected'];
        if (!$verified) {
            $fallback = self::readSslCertificate($domain, false);
            if ($fallback['connected']) {
                $result = $fallback;
            }
        }

        if (!$result['connected']) {
            return [
                'status' => 'danger',
                'label' => 'Erro SSL',
                'message' => $result['error'] ?: 'Não foi possível conectar na porta 443 com SSL.',
            ];
        }

        $cert = $result['certificate'] ?? [];
        $validTo = (int) ($cert['validTo_time_t'] ?? 0);
        $validFrom = (int) ($cert['validFrom_time_t'] ?? 0);
        $now = time();
        $days = $validTo > 0 ? (int) floor(($validTo - $now) / 86400) : null;

        if ($verified && $validFrom <= $now && $validTo > $now) {
            return [
                'status' => $days !== null && $days < 15 ? 'warning' : 'ok',
                'label' => $days !== null && $days < 15 ? 'Vence em breve' : 'OK',
                'message' => $days !== null ? "Certificado válido. Vence em {$days} dias." : 'Certificado válido.',
                'issuer' => self::certName($cert['issuer'] ?? []),
                'subject' => self::certName($cert['subject'] ?? []),
                'valid_from' => $validFrom ? date('Y-m-d H:i:s', $validFrom) : '',
                'valid_to' => $validTo ? date('Y-m-d H:i:s', $validTo) : '',
                'days_left' => $days,
            ];
        }

        if ($validTo > 0 && $validTo <= $now) {
            return [
                'status' => 'danger',
                'label' => 'Expirado',
                'message' => 'Certificado expirado.',
                'issuer' => self::certName($cert['issuer'] ?? []),
                'subject' => self::certName($cert['subject'] ?? []),
                'valid_to' => date('Y-m-d H:i:s', $validTo),
                'days_left' => $days,
            ];
        }

        return [
            'status' => 'warning',
            'label' => 'Conferir',
            'message' => $verified ? 'SSL respondeu, mas a validade do certificado não foi confirmada.' :
                'Certificado encontrado, mas a validação do hostname/CA falhou.',
            'issuer' => self::certName($cert['issuer'] ?? []),
            'subject' => self::certName($cert['subject'] ?? []),
            'valid_from' => $validFrom ? date('Y-m-d H:i:s', $validFrom) : '',
            'valid_to' => $validTo ? date('Y-m-d H:i:s', $validTo) : '',
            'days_left' => $days,
        ];
    }

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
                'error' => $errstr ?: ($errno ? 'Erro ' . $errno : ''),
                'certificate' => [],
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return [
                'connected' => true,
                'error' => 'Conexão SSL sem certificado legível.',
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

    private static function certName(array $data): string {
        if (!empty($data['CN'])) {
            return (string) $data['CN'];
        }
        if (!empty($data['O'])) {
            return (string) $data['O'];
        }
        return '';
    }

    private static function readDatabaseStats(array $config): array {
        $host = app_config("mysql_admin_host", "localhost");
        $port = app_config("mysql_admin_port", 3306);
        $user = app_config("mysql_admin_user", "root");
        $pass = app_config("mysql_admin_pass", "");

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

    private static function countQuery(PDO $pdo, string $sql): int {
        $value = $pdo->query($sql)->fetchColumn();
        return (int) $value;
    }

    private static function formatFileTime(string $file): string {
        $time = @filemtime($file);
        return $time ? date('Y-m-d H:i:s', $time) : '';
    }

    public static function setDebugMode(array $site, bool $enabled): array {
        return self::setFeatureFlag($site, 'debug', $enabled);
    }

    public static function setFeatureFlag(array $site, string $flag, bool $enabled, ?string $value = null): array {
        $definitions = self::featureFlagDefinitions();
        if (!isset($definitions[$flag])) {
            return [
                'ok' => false,
                'message' => 'Flag inválida ou não suportada.',
            ];
        }

        $definition = $definitions[$flag];
        if (($definition['handler'] ?? '') === 'maintenance') {
            return self::setMaintenanceMode($site, $enabled);
        }

        if (!empty($definition['value_type']) && $definition['value_type'] === 'email' && $enabled) {
            $email = trim((string) $value);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'ok' => false,
                    'message' => 'Informe um e-mail válido para o redirecionamento.',
                ];
            }
            return self::writeFeatureFlagFile($site, $definition, true, $email . PHP_EOL);
        }

        return self::writeFeatureFlagFile($site, $definition, $enabled);
    }

    private static function featureFlagDefinitions(): array {
        return [
            'debug' => [
                'label' => 'Modo debug',
                'file' => 'debug.enable',
                'description' => 'Ativa debug completo, exibição de erros e tempo de execução ilimitado.',
                'enabled_label' => 'Habilitado',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'maintenance' => [
                'label' => 'Modo manutenção',
                'file' => 'maintenance.enable',
                'description' => 'Executa admin/cli/maintenance.php e mantém uma flag visual no painel.',
                'enabled_label' => 'Em manutenção',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'danger',
                'handler' => 'maintenance',
                'dangerous' => true,
            ],
            'cron_disable' => [
                'label' => 'Cron pausado',
                'file' => 'cron.disable',
                'description' => 'Faz o cron instalado pelo painel ignorar este Moodle enquanto o arquivo existir.',
                'enabled_label' => 'Pausado',
                'disabled_label' => 'Ativo',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'email_disable' => [
                'label' => 'Envio de e-mail desabilitado',
                'file' => 'email.disable',
                'description' => 'Define $CFG->noemailever = true para bloquear envios reais.',
                'enabled_label' => 'Bloqueado',
                'disabled_label' => 'Liberado',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'email_redirect' => [
                'label' => 'Redirecionar todos os e-mails',
                'file' => 'email.redirect',
                'description' => 'Grava um e-mail no arquivo e aplica $CFG->divertallemailsto.',
                'enabled_label' => 'Redirecionando',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
                'value_type' => 'email',
            ],
            'theme_designer' => [
                'label' => 'Theme designer mode',
                'file' => 'theme-designer.enable',
                'description' => 'Define $CFG->themedesignermode = true. Use apenas para desenvolvimento de tema.',
                'enabled_label' => 'Habilitado',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'cache_dev' => [
                'label' => 'Cache dev desabilitado',
                'file' => 'cache-dev.enable',
                'description' => 'Desabilita cache de JS, templates Mustache e strings de idioma.',
                'enabled_label' => 'Sem cache dev',
                'disabled_label' => 'Cache normal',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'cron_debug' => [
                'label' => 'Debug do cron',
                'file' => 'cron-debug.enable',
                'description' => 'Define $CFG->showcrondebugging = true para detalhar problemas no cron.',
                'enabled_label' => 'Habilitado',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
            ],
            'slow_sql' => [
                'label' => 'Log de SQL lenta do Moodle',
                'file' => 'slow-sql.enable',
                'description' => 'Ativa dboptions logslow=3 e logerrors=true. Use por pouco tempo em produção.',
                'enabled_label' => 'Habilitado',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
            'perf' => [
                'label' => 'Performance info',
                'file' => 'perf.enable',
                'description' => 'Define MDL_PERF, MDL_PERFDB e MDL_PERFTOLOG para diagnóstico de lentidão.',
                'enabled_label' => 'Habilitado',
                'disabled_label' => 'Desabilitado',
                'enabled_status' => 'warning',
                'dangerous' => true,
            ],
        ];
    }

    private static function checkFeatureFlags(array $site): array {
        $items = [];
        foreach (self::featureFlagDefinitions() as $key => $definition) {
            $file = self::featureFlagFile($site, (string) $definition['file']);
            $enabled = $file !== '' && is_file($file);
            $value = '';

            if ($enabled && !empty($definition['value_type']) && is_readable($file)) {
                $value = trim((string) file_get_contents($file));
            }

            $description = (string) ($definition['description'] ?? '');
            if ($key === 'email_redirect' && $enabled && $value !== '') {
                $description .= ' E-mail atual: ' . $value . '.';
            }

            $items[$key] = [
                'label' => (string) ($definition['label'] ?? $key),
                'description' => $description,
                'path' => $file,
                'enabled' => $enabled,
                'value' => $value,
                'value_type' => (string) ($definition['value_type'] ?? ''),
                'dangerous' => !empty($definition['dangerous']),
                'status' => $enabled ? (string) ($definition['enabled_status'] ?? 'warning') : 'ok',
                'status_label' => $enabled ? (string) ($definition['enabled_label'] ?? 'Habilitado') : (string) ($definition['disabled_label'] ?? 'Desabilitado'),
            ];
        }

        return $items;
    }

    private static function writeFeatureFlagFile(array $site, array $definition, bool $enabled, ?string $content = null): array {
        $file = self::featureFlagFile($site, (string) ($definition['file'] ?? ''));
        $label = (string) ($definition['label'] ?? 'Flag');
        if ($file === '') {
            return [
                'ok' => false,
                'message' => 'Caminho da flag não foi identificado.',
            ];
        }

        if ($enabled) {
            $content = $content ?? ('enabled_at=' . date('c') . PHP_EOL);
            if (@file_put_contents($file, $content, LOCK_EX) === false) {
                return [
                    'ok' => false,
                    'message' => 'Não foi possível criar ' . $file . '. Verifique permissão de escrita em /home/[DOMINIO].',
                ];
            }
            @chmod($file, 0640);
            return [
                'ok' => true,
                'message' => $label . ' habilitado para ' . ($site['domain'] ?? 'este Moodle') . '.',
            ];
        }

        if (is_file($file) && !@unlink($file)) {
            return [
                'ok' => false,
                'message' => 'Não foi possível apagar ' . $file . '. Verifique permissão de escrita em /home/[DOMINIO].',
            ];
        }

        return [
            'ok' => true,
            'message' => $label . ' desabilitado para ' . ($site['domain'] ?? 'este Moodle') . '.',
        ];
    }

    private static function setMaintenanceMode(array $site, bool $enabled): array {
        $cli = self::moodleCliFile($site, 'maintenance.php');
        if ($cli === '') {
            return [
                'ok' => false,
                'message' => 'Não foi possível localizar admin/cli/maintenance.php deste Moodle.',
            ];
        }

        $phpbin = (string) (app_config('php_bin') ?: PHP_BINARY ?: '/usr/bin/php');
        $command = escapeshellarg($phpbin) . ' ' . escapeshellarg($cli) . ' ' . ($enabled ? '--enable' : '--disable') . ' 2>&1';
        $output = [];
        $exitcode = 0;
        exec($command, $output, $exitcode);

        if ($exitcode !== 0) {
            $message = trim(implode("\n", $output));
            return [
                'ok' => false,
                'message' => 'Falha ao executar maintenance.php: ' . ($message !== '' ? $message : 'exit code ' . $exitcode),
            ];
        }

        $definitions = self::featureFlagDefinitions();
        $result = self::writeFeatureFlagFile($site, $definitions['maintenance'], $enabled);
        if (!$result['ok']) {
            return $result;
        }

        $result['message'] = 'Modo manutenção ' . ($enabled ? 'habilitado' : 'desabilitado') . ' via CLI para ' . ($site['domain'] ?? 'este Moodle') . '.';
        return $result;
    }

    private static function moodleCliFile(array $site, string $filename): string {
        $moodledir = rtrim((string) ($site['moodle_dir'] ?? ''), '/');
        if ($moodledir === '') {
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

    private static function debugFile(array $site): string {
        return self::featureFlagFile($site, 'debug.enable');
    }

    private static function featureFlagFile(array $site, string $filename): string {
        $base = rtrim((string) ($site['base_dir'] ?? ''), '/');
        $filename = ltrim($filename, '/');
        if ($base === '' || $filename === '') {
            return '';
        }
        return $base . '/' . $filename;
    }

    private static function checkDebugMode(array $site): array {
        $flags = self::checkFeatureFlags($site);
        $debug = $flags['debug'] ?? null;
        if ($debug === null) {
            return [
                'status' => 'muted',
                'label' => '-',
                'path' => '',
                'enabled' => false,
            ];
        }

        return [
            'status' => (string) ($debug['status'] ?? 'muted'),
            'label' => (string) ($debug['status_label'] ?? '-'),
            'path' => (string) ($debug['path'] ?? ''),
            'enabled' => !empty($debug['enabled']),
        ];
    }
}

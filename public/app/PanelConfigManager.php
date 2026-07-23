<?php
// Reads the base PHP config and stores panel overrides in data/config.json.
namespace app;

use RuntimeException;

/**
 * Class PanelConfigManager
 */
class PanelConfigManager {
    /**
     * @var array<int, string>
     */
    public const array EDITABLE_KEYS = [
        "app_name",
        "base_url",
        "reserved_domains",
        "extra_moodle_config",
    ];

    /**
     * Function baseConfigPath
     *
     * @return string
     */
    public static function baseConfigPath(): string {
        $configpath = dirname(__DIR__) . "/config.php";
        if (file_exists($configpath)) {
            return $configpath;
        }

        return dirname(__DIR__) . "/config-example.php";
    }

    /**
     * Function projectRoot
     *
     * @return string
     */
    public static function projectRoot(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Function jsonPath
     *
     * @return string
     */
    public static function jsonPath(): string {
        return self::projectRoot() . "/data/config.json";
    }

    /**
     * Function baseConfig
     *
     * @return array<string, mixed>
     */
    public static function baseConfig(): array {
        $configpath = self::baseConfigPath();
        if (!file_exists($configpath)) {
            return [];
        }

        $configbase = require $configpath;
        return is_array($configbase) ? $configbase : [];
    }

    /**
     * Function savedConfig
     *
     * @return array<string, mixed>
     */
    public static function savedConfig(): array {
        $saved = JsonStorage::read(self::jsonPath());
        return is_array($saved) ? self::onlyEditable($saved) : [];
    }

    /**
     * Function effectiveConfig
     *
     * @return array<string, mixed>
     */
    public static function effectiveConfig(): array {
        $baseconfig = PanelConfigManager::baseConfig();
        return array_replace($baseconfig, self::savedConfig());
    }

    /**
     * Function onlyEditable
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function onlyEditable(array $config): array {
        $allowed = array_flip(self::EDITABLE_KEYS);
        return array_intersect_key($config, $allowed);
    }

    /**
     * Function fieldsForForm
     *
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    public static function fieldsForForm(array $config): array {
        $fields = [];
        foreach (self::EDITABLE_KEYS as $key) {
            $type = "text";
            $value = self::stringValue($config[$key] ?? "");
            $istextarea = in_array($key, ["reserved_domains", "extra_moodle_config"], true);
            $help = match ($key) {
                "reserved_domains" => t("configuration.reserved_domains_help"),
                "extra_moodle_config" => t("configuration.extra_moodle_config_help"),
                default => "",
            };

            $field = [
                "key" => $key,
                "label" => self::label($key),
                "name" => "config[{$key}]",
                "help" => $help,
                "type" => $type,
                "value" => $value,
                "is_textarea" => $istextarea,
                "is_input" => !$istextarea,
                "is_select" => false,
            ];

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Function normalizePost
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $savedconfig
     * @return array<string, mixed>
     */
    public static function normalizePost(array $input, array $savedconfig): array {
        $posted = $input["config"] ?? [];
        if (!is_array($posted)) {
            throw new RuntimeException("Invalid configuration payload.");
        }

        $newconfig = [];
        foreach (self::EDITABLE_KEYS as $key) {
            $value = $posted[$key] ?? null;

            if ($key === "reserved_domains") {
                $newconfig[$key] = self::normalizeReservedDomains($value);
                continue;
            }

            if ($key === "extra_moodle_config") {
                $newconfig[$key] = self::normalizeExtraMoodleConfig($value);
                continue;
            }

            $newconfig[$key] = trim((string) $value);
        }

        return $newconfig;
    }

    /**
     * Function save
     *
     * @param array<string, mixed> $config
     * @return void
     * @throws \Random\RandomException
     */
    public static function save(array $config): void {
        JsonStorage::write(self::jsonPath(), self::onlyEditable($config));
    }

    /**
     * Function requiresInitialSetup
     *
     * A blank base URL identifies an installation that still needs the
     * DigitalOcean One-Click first-access setup.
     *
     * @return bool
     */
    public static function requiresInitialSetup(): bool {
        $config = self::effectiveConfig();
        return trim((string) ($config["base_url"] ?? "")) === "";
    }

    /**
     * Function saveBaseUrl
     *
     * @param string $baseurl
     * @return string
     * @throws \Random\RandomException
     */
    public static function saveBaseUrl(string $baseurl): string {
        $baseurl = self::normalizeBaseUrl($baseurl);
        $savedconfig = self::savedConfig();
        $savedconfig["base_url"] = $baseurl;
        self::save($savedconfig);
        return $baseurl;
    }

    /**
     * Function detectRequestBaseUrl
     *
     * @return string
     */
    public static function detectRequestBaseUrl(): string {
        $host = trim((string) ($_SERVER["HTTP_HOST"] ?? $_SERVER["SERVER_NAME"] ?? ""));
        if ($host === "" || preg_match("/[\x00-\x20\/\\\\?#@,]/", $host)) {
            return "";
        }

        $scheme = "http";
        $forwardedproto = strtolower(trim(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""))[0]));
        $https = strtolower((string) ($_SERVER["HTTPS"] ?? ""));
        if ($forwardedproto === "https"
            || ($https !== "" && $https !== "off" && $https !== "0")
            || (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443
        ) {
            $scheme = "https";
        }

        try {
            return self::normalizeBaseUrl("{$scheme}://{$host}");
        } catch (\Throwable) {
            return "";
        }
    }

    /**
     * Function normalizeBaseUrl
     *
     * @param string $baseurl
     * @return string
     */
    public static function normalizeBaseUrl(string $baseurl): string {
        $baseurl = trim($baseurl);
        if ($baseurl === "") {
            throw new RuntimeException(t("onboarding.base_url_required"));
        }

        if (!preg_match("#^[a-z][a-z0-9+.-]*://#i", $baseurl)) {
            $candidatehost = preg_replace("#[/:].*$#", "", $baseurl);
            $scheme = filter_var($candidatehost, FILTER_VALIDATE_IP) ? "http" : "https";
            $baseurl = "{$scheme}://{$baseurl}";
        }

        $parts = parse_url($baseurl);
        if (!is_array($parts)) {
            throw new RuntimeException(t("onboarding.base_url_invalid"));
        }

        $scheme = strtolower((string) ($parts["scheme"] ?? ""));
        $host = strtolower(rtrim((string) ($parts["host"] ?? ""), "."));
        $path = (string) ($parts["path"] ?? "");
        $port = isset($parts["port"]) ? (int) $parts["port"] : null;

        if (!in_array($scheme, ["http", "https"], true)
            || $host === ""
            || isset($parts["user"])
            || isset($parts["pass"])
            || isset($parts["query"])
            || isset($parts["fragment"])
            || ($path !== "" && $path !== "/")
            || ($port !== null && ($port < 1 || $port > 65535))
            || (!filter_var($host, FILTER_VALIDATE_IP) && !self::isValidHostname($host))
        ) {
            throw new RuntimeException(t("onboarding.base_url_invalid"));
        }

        $authority = str_contains($host, ":") ? "[{$host}]" : $host;
        $isdefaultport = ($scheme === "http" && $port === 80) || ($scheme === "https" && $port === 443);
        if ($port !== null && !$isdefaultport) {
            $authority .= ":{$port}";
        }

        return "{$scheme}://{$authority}";
    }

    /**
     * Function baseUrlHost
     *
     * @param string $baseurl
     * @return string
     */
    public static function baseUrlHost(string $baseurl): string {
        $host = parse_url($baseurl, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : "";
    }

    /**
     * Function isIpBaseUrl
     *
     * @param string $baseurl
     * @return bool
     */
    public static function isIpBaseUrl(string $baseurl): bool {
        $host = self::baseUrlHost($baseurl);
        return $host !== "" && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Function isHttpsBaseUrl
     *
     * @param string $baseurl
     * @return bool
     */
    public static function isHttpsBaseUrl(string $baseurl): bool {
        return strtolower((string) parse_url($baseurl, PHP_URL_SCHEME)) === "https";
    }

    /**
     * Function isValidHostname
     *
     * @param string $host
     * @return bool
     */
    private static function isValidHostname(string $host): bool {
        if (strlen($host) > 253 || !str_contains($host, ".")) {
            return false;
        }

        return (bool) preg_match(
            "/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/i",
            $host
        );
    }

    /**
     * Function label
     *
     * @param string $key
     * @return string
     */
    private static function label(string $key): string {
        if (class_exists(I18n::class)) {
            $languagekey = "configuration.fields.{$key}";
            $label = I18n::get($languagekey);
            if ($label !== $languagekey) {
                return $label;
            }
        }

        return $key;
    }

    /**
     * Function stringValue
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private static function stringValue(mixed $value): string {
        if ($value === null) {
            return "";
        }

        if (is_array($value)) {
            return implode("\n", array_map("strval", $value));
        }

        if (is_bool($value)) {
            return $value ? "1" : "0";
        }

        return (string) $value;
    }

    /**
     * Function normalizeReservedDomains
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeReservedDomains(mixed $value): array {
        $raw = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $items = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $items[$line] = $line;
        }

        return array_values($items);
    }

    /**
     * Function normalizeExtraMoodleConfig
     *
     * @param mixed $value
     * @return string
     */
    private static function normalizeExtraMoodleConfig(mixed $value): string {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    /**
     * Detect the public IPv4 address used by this server.
     *
     * @return string
     */
    public static function detect_public_ipv4(): string {
        $cached = $_SESSION["install_public_ipv4"] ?? null;
        if (is_array($cached)
            && array_key_exists("ip", $cached)
            && !empty($cached["checked_at"])
            && ((int) $cached["checked_at"] + 900) > time()
        ) {
            return is_string($cached["ip"]) ? $cached["ip"] : "";
        }

        $candidates = [];
        $baseurl = (string) app_config("base_url");
        if ($baseurl != "") {
            $basehost = parse_url($baseurl, PHP_URL_HOST);
            if (is_string($basehost)) {
                $candidates[] = $basehost;
            }
        }

        foreach (["SERVER_ADDR", "LOCAL_ADDR"] as $serverkey) {
            if (!empty($_SERVER[$serverkey]) && is_string($_SERVER[$serverkey])) {
                $candidates[] = $_SERVER[$serverkey];
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                $_SESSION["install_public_ipv4"] = ["ip" => $candidate, "checked_at" => time()];
                return $candidate;
            }
        }

        $endpoints = [
            "https://api.ipify.org",
            "https://ifconfig.me/ip",
        ];

        foreach ($endpoints as $endpoint) {
            $response = "";
            if (function_exists("curl_init")) {
                $curl = curl_init($endpoint);
                if ($curl !== false) {
                    curl_setopt_array($curl, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 2,
                        CURLOPT_TIMEOUT => 4,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_USERAGENT => "Moodle-Friendly-Installation",
                    ]);
                    $result = curl_exec($curl);
                    if (is_string($result)) {
                        $response = $result;
                    }
                    curl_close($curl);
                }
            } else if (filter_var(ini_get("allow_url_fopen"), FILTER_VALIDATE_BOOLEAN)) {
                $context = stream_context_create([
                    "http" => [
                        "timeout" => 4,
                        "user_agent" => "Moodle-Friendly-Installation",
                    ],
                ]);
                $result = @file_get_contents($endpoint, false, $context);
                if (is_string($result)) {
                    $response = $result;
                }
            }

            $candidate = trim($response);
            if (filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                $_SESSION["install_public_ipv4"] = ["ip" => $candidate, "checked_at" => time()];
                return $candidate;
            }
        }

        $_SESSION["install_public_ipv4"] = ["ip" => "", "checked_at" => time()];
        return "";
    }
}

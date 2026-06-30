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
        "default_moodle_branch",
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

        $config = require $configpath;
        return is_array($config) ? $config : [];
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
     * @param array<string, mixed> $baseconfig
     * @return array<string, mixed>
     */
    public static function effectiveConfig(array $baseconfig): array {
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
            $istextarea = $key === "reserved_domains";

            $field = [
                "key" => $key,
                "label" => self::label($key),
                "name" => "config[{$key}]",
                "help" => t("configuration.reserved_domains_help"),
                "type" => $type,
                "value" => $value,
                "is_textarea" => $istextarea,
                "is_input" => !$istextarea,
                "is_select" => false,
            ];

            if ($key == "default_moodle_branch") {
                $field["values"] = MoodleBranchProvider::getInstallBranches();
                $field["is_select"] = true;
            }

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
}

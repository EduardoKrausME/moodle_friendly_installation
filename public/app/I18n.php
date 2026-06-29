<?php
// Lightweight i18n helper used by PHP and Mustache templates.
namespace app;

/**
 * Class I18n
 */
class I18n {
    private const string DEFAULT_LANGUAGE = 'pt_br';
    private const string COOKIE_NAME = 'moodle_friendly_installation_admin_lang';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];
    /** @var string */
    private static string $current = self::DEFAULT_LANGUAGE;

    /**
     * Function init
     *
     * @return void
     */
    public static function init(): void {
        self::$current = self::detectLanguage();

        $requested = isset($_GET["lang"]) && is_string($_GET["lang"]) ? self::normalizeLanguage($_GET["lang"]) : '';
        if ($requested != '' && self::isSupported($requested)) {
            self::$current = $requested;
            if (PHP_SAPI != 'cli') {
                $_SESSION["lang"] = $requested;
                setcookie(self::COOKIE_NAME, $requested, [
                    'expires' => time() + 60 * 60 * 24 * 365,
                    'path' => '/',
                    'secure' => (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != 'off'),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);

                if (($_SERVER["REQUEST_METHOD"] ?? 'GET') == 'GET') {
                    $cleanurl = self::urlWithoutLang();
                    header('Location: ' . $cleanurl);
                    exit;
                }
            }
        }
    }

    /**
     * Function current
     *
     * @return string
     */
    public static function current(): string {
        return self::$current;
    }

    /**
     * Function moodleLanguage
     *
     * @param string|null $language
     * @return string
     */
    public static function moodleLanguage(?string $language = null): string {
        $language = $language === null ? self::$current : self::normalizeLanguage($language);
        return self::isSupported($language) ? $language : self::DEFAULT_LANGUAGE;
    }

    /**
     * Function moodleHubLanguage
     *
     * @param string|null $language
     * @return string
     */
    public static function moodleHubLanguage(?string $language = null): string {
        $language = self::moodleLanguage($language);
        return $language == 'pt_br' ? 'pt' : $language;
    }

    /**
     * Function strings
     *
     * @return array
     */
    public static function strings(): array {
        return self::languageData(self::$current);
    }

    /**
     * Function htmlLang
     *
     * @return string
     */
    public static function htmlLang(): string {
        return (self::languageData(self::$current)["meta"]["html_lang"] ?? 'pt-BR');
    }

    /**
     * Function currentMeta
     *
     * @return array
     */
    public static function currentMeta(): array {
        return self::languageData(self::$current)["meta"] ?? [];
    }

    /**
     * Function get
     *
     * @param string $key
     * @param array $params
     * @return string
     */
    public static function get(string $key, array $params = []): string {
        $value = self::arrayGet(self::languageData(self::$current), $key);
        if (!is_string($value)) {
            $value = self::arrayGet(self::languageData(self::DEFAULT_LANGUAGE), $key);
        }
        if (!is_string($value)) {
            return $key;
        }

        if (!empty($params)) {
            $replace = [];
            foreach ($params as $name => $paramvalue) {
                $replace['{' . $name . '}'] =$paramvalue;
            }
            $value = strtr($value, $replace);
        }

        return $value;
    }

    /**
     * Function languagesForSelector
     *
     * @return array
     */
    public static function languagesForSelector(): array {
        $items = [];
        foreach (self::supportedLanguages() as $code) {
            $meta = self::languageData($code)["meta"] ?? [];
            $current = $code == self::$current;
            $items[] = [
                'code' => $code,
                'name' => $meta["name"] ?? $code,
                'native_name' => $meta["native_name"] ?? ($meta["name"] ?? $code),
                'flag' => $meta["flag"] ?? '',
                'url' => self::urlWithLang($code),
                'selected' => $current,
                'class' => $current ? 'language-option is-active' : 'language-option',
                'aria_current' => $current ? 'page' : 'false',
            ];
        }
        return $items;
    }

    /**
     * Function supportedLanguages
     *
     * @return array
     */
    public static function supportedLanguages(): array {
        $files = glob(__DIR__ . '/lang/*.php') ?: [];
        $languages = [];
        foreach ($files as $file) {
            $languages[] = basename($file, '.php');
        }
        sort($languages);
        if (!in_array(self::DEFAULT_LANGUAGE, $languages, true)) {
            array_unshift($languages, self::DEFAULT_LANGUAGE);
        }
        return array_values(array_unique($languages));
    }

    /**
     * Function detectLanguage
     *
     * @return string
     */
    private static function detectLanguage(): string {
        $sessionlang = isset($_SESSION["lang"]) && is_string($_SESSION["lang"]) ? self::normalizeLanguage($_SESSION["lang"]) : '';
        if ($sessionlang != '' && self::isSupported($sessionlang)) {
            return $sessionlang;
        }

        $cookielang = isset($_COOKIE[self::COOKIE_NAME]) && is_string($_COOKIE[self::COOKIE_NAME]) ? self::normalizeLanguage($_COOKIE[self::COOKIE_NAME]) : '';
        if ($cookielang != '' && self::isSupported($cookielang)) {
            return $cookielang;
        }

        $accepted = isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) && is_string($_SERVER["HTTP_ACCEPT_LANGUAGE"]) ? $_SERVER["HTTP_ACCEPT_LANGUAGE"] : '';
        foreach (explode(',', $accepted) as $part) {
            $language = self::normalizeLanguage(trim(explode(';', $part)[0] ?? ''));
            if ($language != '' && self::isSupported($language)) {
                return $language;
            }
            if (str_starts_with($language, 'pt')) {
                return 'pt_br';
            }
            if (str_starts_with($language, 'es') && self::isSupported('es')) {
                return 'es';
            }
            if (str_starts_with($language, 'en') && self::isSupported('en')) {
                return 'en';
            }
        }

        return self::DEFAULT_LANGUAGE;
    }

    /**
     * Function normalizeLanguage
     *
     * @param string $language
     * @return string
     */
    private static function normalizeLanguage(string $language): string {
        $language = strtolower(trim($language));
        $language = str_replace('-', '_', $language);
        if ($language == 'pt' || str_starts_with($language, 'pt_')) {
            return 'pt_br';
        }
        if (str_starts_with($language, 'en_')) {
            return 'en';
        }
        if (str_starts_with($language, 'es_')) {
            return 'es';
        }
        return preg_replace('/[^a-z0-9_]/', '', $language) ?: '';
    }

    /**
     * Function isSupported
     *
     * @param string $language
     * @return bool
     */
    private static function isSupported(string $language): bool {
        return is_file(__DIR__ . '/lang/' . $language . '.php');
    }

    /**
     * Function languageData
     *
     * @param string $language
     * @return array
     */
    private static function languageData(string $language): array {
        if (isset(self::$cache[$language])) {
            return self::$cache[$language];
        }

        $file = __DIR__ . '/lang/' . $language . '.php';
        if (!is_file($file)) {
            $file = __DIR__ . '/lang/' . self::DEFAULT_LANGUAGE . '.php';
        }

        $data = require $file;
        $data = is_array($data) ? $data : [];
        if ($language != self::DEFAULT_LANGUAGE) {
            $defaultfile = __DIR__ . '/lang/' . self::DEFAULT_LANGUAGE . '.php';
            $defaultdata = is_file($defaultfile) ? require $defaultfile : [];
            $data = array_replace_recursive(is_array($defaultdata) ? $defaultdata : [], $data);
        }
        self::$cache[$language] = $data;
        return self::$cache[$language];
    }

    /**
     * Function arrayGet
     *
     * @param array $data
     * @param string $key
     * @return mixed
     */
    private static function arrayGet(array $data, string $key): mixed {
        $current = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    /**
     * Function urlWithLang
     *
     * @param string $language
     * @return string
     */
    private static function urlWithLang(string $language): string {
        $uri = $_SERVER["REQUEST_URI"] ?? '/';
        $parts = parse_url($uri) ?: [];
        $path = $parts["path"] ?? '/';
        $query = [];
        if (!empty($parts["query"])) {
            parse_str($parts["query"], $query);
        }
        $query["lang"] = $language;
        return $path . '?' . http_build_query($query);
    }

    /**
     * Function urlWithoutLang
     *
     * @return string
     */
    private static function urlWithoutLang(): string {
        $uri = $_SERVER["REQUEST_URI"] ?? '/';
        $parts = parse_url($uri) ?: [];
        $path = $parts["path"] ?? '/';
        $query = [];
        if (!empty($parts["query"])) {
            parse_str($parts["query"], $query);
        }
        unset($query["lang"]);
        $querystring = http_build_query($query);
        return $path . ($querystring != '' ? '?' . $querystring : '');
    }
}

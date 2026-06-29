<?php
// App build settings and artifact discovery.
namespace app;

use RuntimeException;

/**
 * Class AppManager
 */
class AppManager {
    private const ICON_FILENAME = 'logo.png';

    /**
     * Function getSettings
     *
     * @param array $site
     * @return array
     */
    public static function getSettings(array $site): array {
        $domain = self::siteDomain($site);
        $defaults = [
            'domain' => $domain,
            'package_uid' => self::defaultPackageUid($domain),
            'package_name' => self::defaultPackageName($site),
            'statusbarbackgroundcolor' => '#08422a',
            'icon_path' => '',
            'updated_at' => '',
            'updated_by' => '',
        ];

        $settingsfile = self::settingsFile($domain);
        $stored = JsonStorage::read($settingsfile);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge($defaults, $stored);
        if (empty($settings["package_uid"])) {
            $settings["package_uid"] = $defaults["package_uid"];
        }
        if (empty($settings["package_name"])) {
            $settings["package_name"] = $defaults["package_name"];
        }
        if (empty($settings["statusbarbackgroundcolor"])) {
            $settings["statusbarbackgroundcolor"] = $defaults["statusbarbackgroundcolor"];
        }

        $packageuid =$settings["package_uid"];
        $settings["package_uid_locked"] = self::isPackageUidLocked($domain);
        $settings["resource_dir"] = self::resourceDir($packageuid);
        $settings["icon_path"] = self::iconPath($packageuid);
        $settings["has_icon"] = self::hasIcon($packageuid);
        $settings["has_keystore_password"] = self::hasAndroidKeystorePassword($packageuid);
        $settings["has_android_key_files"] = self::hasAndroidKeyFiles($packageuid);

        return $settings;
    }

    /**
     * Function validateSettings
     *
     * @param array $input
     * @param string $domain
     * @param array|null $current
     * @return array
     */
    public static function validateSettings(array $input, string $domain, ?array $current = null): array {
        $errors = [];
        $locked = !empty($current["package_uid_locked"]);
        $currentpackageuid = $current["package_uid"] ?? '';

        $postedpackageuid = strtolower($input["package_uid"] ?? '');
        if ($postedpackageuid == '') {
            $postedpackageuid = $currentpackageuid != '' ? $currentpackageuid : self::defaultPackageUid($domain);
        }

        $packageuid = self::normalizePackageUid($postedpackageuid);
        if ($locked && $currentpackageuid != '') {
            if ($packageuid != $currentpackageuid) {
                $errors["package_uid"] = I18n::get('validation.package_uid_locked', ['value' => $currentpackageuid]);
            }
            $packageuid = $currentpackageuid;
        }

        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $packageuid)) {
            $errors["package_uid"] = I18n::get('validation.package_uid_invalid');
        }

        $packagename = $input["package_name"] ?? '';
        if ($packagename == '') {
            $errors["package_name"] = I18n::get('validation.package_name_required');
        } else if (mb_strlen($packagename) > 80) {
            $errors["package_name"] = I18n::get('validation.package_name_too_long');
        }

        $color = strtoupper($input["statusbarbackgroundcolor"] ?? '');
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $errors["statusbarbackgroundcolor"] = I18n::get('validation.color_invalid');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => [
                'package_uid' => $packageuid,
                'package_name' => $packagename,
                'statusbarbackgroundcolor' => $color,
            ],
        ];
    }

    /**
     * Function validateIconUpload
     *
     * @param array|null $file
     * @param bool $required
     * @return array
     */
    public static function validateIconUpload(?array $file, bool $required): array {
        if (empty($file) || !isset($file["error"]) || $file["error"] == UPLOAD_ERR_NO_FILE) {
            return [
                'valid' => !$required,
                'error' => $required ? I18n::get('validation.icon_required') : '',
            ];
        }

        if ($file["error"] != UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'error' => I18n::get('validation.upload_failed'),
            ];
        }

        $tmp = $file["tmp_name"] ?? '';
        if ($tmp == '' || !is_uploaded_file($tmp)) {
            return [
                'valid' => false,
                'error' => I18n::get('validation.upload_invalid'),
            ];
        }

        $info = @getimagesize($tmp);
        if (!$info || $info[0]  != 1024 || $info[1] != 1024) {
            return [
                'valid' => false,
                'error' => I18n::get('validation.icon_size'),
            ];
        }

        $mime = $info["mime"] ?? '';
        if ($mime != 'image/png') {
            return [
                'valid' => false,
                'error' => I18n::get('validation.icon_png'),
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Function validateKeystorePassword
     *
     * @param string|null $password
     * @param bool $required
     * @return array
     */
    public static function validateKeystorePassword(?string $password, bool $required): array {
        $password = trim($password);
        if (!$required && $password == '') {
            return ['valid' => true, 'error' => '', 'password' => ''];
        }

        if ($password == '') {
            return [
                'valid' => false,
                'error' => I18n::get('validation.keystore_password_required'),
                'password' => '',
            ];
        }

        if (strlen($password) < 6) {
            return [
                'valid' => false,
                'error' => I18n::get('validation.keystore_password_short'),
                'password' => '',
            ];
        }

        return ['valid' => true, 'error' => '', 'password' => $password];
    }

    /**
     * Function saveSettings
     *
     * @param string $domain
     * @param array $data
     * @param array|null $iconfile
     * @return array
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function saveSettings(string $domain, array $data, ?array $iconfile = null): array {
        $current = JsonStorage::read(self::settingsFile($domain), []);
        if (!is_array($current)) {
            $current = [];
        }

        if (!empty($current["package_uid"])) {
            $data["package_uid"] =$current["package_uid"];
        }

        $settings = array_merge($current, $data);
        $packageuid =$settings["package_uid"];
        self::ensureResourceRootWritable();
        self::ensureDir(self::resourceDir($packageuid), 0750);

        if (!empty($iconfile) && isset($iconfile["error"]) && $iconfile["error"] == UPLOAD_ERR_OK) {
            $dest = self::iconPath($packageuid);
            if (!move_uploaded_file($iconfile["tmp_name"], $dest)) {
                throw new RuntimeException(I18n::get('app_errors.icon_save_failed'));
            }
            chmod($dest, 0640);
        }

        if (self::hasIcon($packageuid)) {
            $settings["icon_path"] = self::iconPath($packageuid);
        }

        if (empty($settings["package_uid_locked_at"])) {
            $settings["package_uid_locked_at"] = now_iso();
        }
        $settings["updated_at"] = now_iso();
        $settings["updated_by"] = Auth::user()["username"] ?? 'system';

        JsonStorage::write(self::settingsFile($domain), $settings);

        $settings["package_uid_locked"] = true;
        $settings["resource_dir"] = self::resourceDir($packageuid);
        $settings["has_icon"] = self::hasIcon($packageuid);
        $settings["has_keystore_password"] = self::hasAndroidKeystorePassword($packageuid);
        $settings["has_android_key_files"] = self::hasAndroidKeyFiles($packageuid);
        return $settings;
    }

    /**
     * Function ensureAndroidKeyFiles
     *
     * @param string $packageuid
     * @param string|null $password
     * @return void
     */
    public static function ensureAndroidKeyFiles(string $packageuid, ?string $password = null): void {
        self::ensureResourceRootWritable();
        $resdir = self::resourceDir($packageuid);
        $keydir = self::androidKeyDir($packageuid);
        self::ensureDir($keydir, 0700);

        $keystore = $keydir . '/keystore';
        $passfile = $keydir . '/keystore.txt';
        $buildjson = $keydir . '/build.json';

        if ($password == null || $password == '') {
            if (!is_file($passfile) || !is_readable($passfile)) {
                throw new RuntimeException(I18n::get('app_errors.keystore_password_missing'));
            }
            $password = trim(file_get_contents($passfile));
        }

        $validation = self::validateKeystorePassword($password, true);
        if (!$validation["valid"]) {
            throw new RuntimeException($validation["error"]);
        }
        $password =$validation["password"];

        $mustgeneratekeystore = !is_file($keystore);
        if (!is_file($passfile)) {
            if (is_file($keystore)) {
                @unlink($keystore);
            }
            $mustgeneratekeystore = true;
            file_put_contents($passfile, $password . PHP_EOL, LOCK_EX);
            chmod($passfile, 0600);
        }

        if ($mustgeneratekeystore) {
            self::runKeytool($resdir, $password);
            chmod($keystore, 0600);
        }

        file_put_contents(
            $buildjson,
            json_encode(self::androidBuildConfig('key-android/keystore', $password), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
        chmod($buildjson, 0600);
    }

    /**
     * Function androidBuildConfig
     *
     * @param string $keystore
     * @param string $password
     * @return array[]
     */
    public static function androidBuildConfig(string $keystore, string $password): array {
        return [
            'android' => [
                'release' => [
                    'keystore' => $keystore,
                    'storePassword' => $password,
                    'alias' => 'app',
                    'password' => $password,
                    'keystoreType' => 'pkcs12',
                ],
            ],
        ];
    }

    /**
     * Function buildReadiness
     *
     * @param array $settings
     * @param string $domain
     * @return array
     */
    public static function buildReadiness(array $settings, string $domain): array {
        $missing = [];
        $packageuid = $settings["package_uid"] ?? '';

        if (!self::isResourceRootWritable()) {
            $missing[] = ['message' => I18n::get('app_errors.resource_root_missing')];
        }
        if (empty($settings["package_uid_locked"])) {
            $missing[] = ['message' => I18n::get('app_errors.save_once_package_uid')];
        }
        if ($packageuid == '' || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $packageuid)) {
            $missing[] = ['message' => I18n::get('app_errors.package_uid_valid')];
        }
        if (($settings["package_name"] ?? '') == '') {
            $missing[] = ['message' => I18n::get('validation.package_name_required')];
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', ($settings["statusbarbackgroundcolor"] ?? ''))) {
            $missing[] = ['message' => I18n::get('app_errors.color_required')];
        }
        if (empty($settings["has_icon"])) {
            $missing[] = ['message' => I18n::get('app_errors.icon_missing')];
        }
        if (!self::hasAndroidKeystorePassword($packageuid)) {
            $missing[] = ['message' => I18n::get('app_errors.keystore_missing')];
        } else if (!self::hasAndroidKeyFiles($packageuid)) {
            $missing[] = ['message' => I18n::get('app_errors.key_files_missing')];
        }
        if (self::hasActiveBuildJob($domain)) {
            $missing[] = ['message' => I18n::get('app_errors.build_active')];
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Function moodleConfigTest
     *
     * @param string $domain
     * @return array
     */
    public static function moodleConfigTest(string $domain): array {
        $url = self::moodleConfigTestUrl($domain);
        $result = [
            'url' => $url,
            'valid' => false,
            'has_error' => false,
            'error' => '',
            'has_install_url' => false,
            'install_url' => self::moodleConfigInstallUrl(),
            'warnings' => [],
            'has_warnings' => false,
            'oks' => [],
            'has_oks' => false,
            'versions' => [],
            'has_versions' => false,
        ];

        try {
            $json = self::fetchMoodleConfigTestUrl($url);
        } catch (RuntimeException $e) {
            $result["has_error"] = true;
            $result["error"] = $e->getMessage();
            $result["has_install_url"] = $e->getCode() == 404;
            return $result;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $result["has_error"] = true;
            $result["error"] = I18n::get('app_errors.moodle_config_invalid_json', [
                'message' => json_last_error_msg(),
            ]);
            return $result;
        }

        foreach (self::moodleConfigBooleanChecks() as $key => $description) {
            if (!array_key_exists($key, $data)) {
                $result["warnings"][] = [
                    'key' => $key,
                    'description' => $description,
                    'message' => I18n::get('app_errors.moodle_config_missing_key'),
                    'edit_url' => self::moodleConfigEditUrl($domain, $key),
                    'has_edit_url' => true,
                ];
                continue;
            }

            if ($data[$key] === true) {
                $result["oks"][] = [
                    'key' => $key,
                    'description' => $description,
                    'message' => I18n::get('status.ok'),
                ];
                continue;
            }

            $result["warnings"][] = [
                'key' => $key,
                'description' => $description,
                'message' => I18n::get('app_errors.moodle_config_incorrect'),
                'edit_url' => self::moodleConfigEditUrl($domain, $key),
                'has_edit_url' => true,
            ];
        }

        foreach (self::moodleConfigVersionChecks() as $key => $label) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $result["versions"][] = [
                'key' => $label,
                'value' => $data[$key],
            ];
        }

        $result["has_warnings"] = !empty($result["warnings"]);
        $result["has_oks"] = !empty($result["oks"]);
        $result["has_versions"] = !empty($result["versions"]);
        $result["valid"] = !$result["has_error"] && !$result["has_warnings"];
        return $result;
    }

    /**
     * Function applyMoodleConfigTestToReadiness
     *
     * @param array $readiness
     * @param array $configtest
     * @return array
     */
    public static function applyMoodleConfigTestToReadiness(array $readiness, array $configtest): array {
        if (!empty($configtest["has_error"])) {
            $readiness["missing"][] = [
                'message' => I18n::get('app_errors.moodle_config_test_failed', [
                    'message' => $configtest["error"] ?? '',
                ]),
            ];
        }

        foreach (($configtest["warnings"] ?? []) as $warning) {
            $readiness["missing"][] = [
                'message' => ($warning["key"] ?? '') . ': ' . ($warning["message"] ?? ''),
            ];
        }

        $readiness["valid"] = empty($readiness["missing"]);
        return $readiness;
    }

    /**
     * Function appVersion
     *
     * @return string
     */
    public static function appVersion(): string {
        $configfile = app_config_path('/app-MoodleMobile-V2/config.xml');
        if (!is_readable($configfile)) {
            return '1.0.0';
        }

        $content = file_get_contents($configfile);
        if ($content != false && preg_match('/<widget\b[^>]*\bversion=["\']([^"\']+)["\']/i', $content, $matches)) {
            return $matches[1];
        }

        return '1.0.0';
    }

    /**
     * Function buildFiles
     *
     * @param string $domain
     * @return array
     */
    public static function buildFiles(string $domain): array {
        $dir = self::storageDir($domain);
        $items = [];
        foreach (glob($dir . '/*.{apk,aab}', GLOB_BRACE) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $basename = basename($file);
            $ext = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
            $mtime = filemtime($file) ?: time();
            $items[] = [
                'filename' => $basename,
                'label' => $ext,
                'size' => self::formatBytes(filesize($file) ?: 0),
                'created_at' => date('d/m/Y H:i', $mtime),
                'download_url' => '/app_download.php?domain=' . urlencode($domain) . '&file=' . urlencode($basename),
            ];
        }

        usort($items, static function(array $a, array $b): int {
            return strcmp(($b["created_at"] ?? ''), ($a["created_at"] ?? ''));
        });

        return $items;
    }

    /**
     * Function latestJob
     *
     * @param string $domain
     * @return array|null
     */
    public static function latestJob(string $domain): ?array {
        foreach (JobManager::all() as $job) {
            if (($job["type"] ?? '') == 'app_build' && ($job["domain"] ?? '') == $domain) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Function hasActiveBuildJob
     *
     * @param string $domain
     * @return bool
     */
    public static function hasActiveBuildJob(string $domain): bool {
        foreach (JobManager::all() as $job) {
            if (($job["type"] ?? '') != 'app_build' || ($job["domain"] ?? '') != $domain) {
                continue;
            }
            if (in_array(($job["status"] ?? ''), ['pending', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Function storageDir
     *
     * @param string $domain
     * @return string
     */
    public static function storageDir(string $domain): string {
        $domain = preg_replace('/[^a-z0-9.-]+/', '-', strtolower(trim($domain)));
        $domain = trim($domain, '.-');
        return app_config_path('/data/' . $domain);
    }

    /**
     * Function settingsFile
     *
     * @param string $domain
     * @return string
     */
    public static function settingsFile(string $domain): string {
        return self::storageDir($domain) . '/app-settings.json';
    }

    /**
     * Function resourceDir
     *
     * @param string $packageuid
     * @return string
     */
    public static function resourceDir(string $packageuid): string {
        return app_config_path('/app-MoodleMobile-V2/res/' . self::normalizePackageUid($packageuid));
    }

    /**
     * Function iconPath
     *
     * @param string $packageuid
     * @return string
     */
    public static function iconPath(string $packageuid): string {
        return self::storageDir($packageuid) . '/' . self::ICON_FILENAME;
    }

    /**
     * Function hasIcon
     *
     * @param string $packageuid
     * @return bool
     */
    public static function hasIcon(string $packageuid): bool {
        $path = self::iconPath($packageuid);
        return is_file($path) && is_readable($path);
    }

    /**
     * Function androidKeyDir
     *
     * @param string $packageuid
     * @return string
     */
    public static function androidKeyDir(string $packageuid): string {
        return self::resourceDir($packageuid) . '/key-android';
    }

    /**
     * Function hasAndroidKeystorePassword
     *
     * @param string $packageuid
     * @return bool
     */
    public static function hasAndroidKeystorePassword(string $packageuid): bool {
        $file = self::androidKeyDir($packageuid) . '/keystore.txt';
        return is_file($file) && is_readable($file);
    }

    /**
     * Function hasAndroidKeyFiles
     *
     * @param string $packageuid
     * @return bool
     */
    public static function hasAndroidKeyFiles(string $packageuid): bool {
        $keydir = self::androidKeyDir($packageuid);
        return is_file($keydir . '/keystore')
            && is_readable($keydir . '/keystore')
            && is_file($keydir . '/keystore.txt')
            && is_readable($keydir . '/keystore.txt')
            && is_file($keydir . '/build.json')
            && is_readable($keydir . '/build.json');
    }

    /**
     * Function defaultPackageUid
     *
     * @param string $domain
     * @return string
     */
    public static function defaultPackageUid(string $domain): string {
        $domain = strtolower(trim($domain));
        $parts = array_filter(explode('.', $domain), static fn(string $part): bool => $part != '');
        $clean = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9_]+/', '_', $part);
            $part = trim($part, '_');
            if ($part == '') {
                continue;
            }
            if (!preg_match('/^[a-z]/', $part)) {
                $part = 'app' . $part;
            }
            $clean[] = $part;
        }

        if (count($clean) < 2) {
            $clean = ['app', 'moodle_friendly_installation'];
        }

        return implode('.', $clean);
    }

    /**
     * Function isPackageUidLocked
     *
     * @param string $domain
     * @return bool
     */
    private static function isPackageUidLocked(string $domain): bool {
        $stored = JsonStorage::read(self::settingsFile($domain));
        return is_array($stored) && !empty($stored["package_uid"]);
    }

    /**
     * Function ensureResourceRootWritable
     *
     * @return void
     */
    private static function ensureResourceRootWritable(): void {
        $root = app_config_path('/app-MoodleMobile-V2/res');
        if (!is_dir($root)) {
            mkdir($root, 0750, true);
        }
        if (!is_writable($root)) {
            throw new RuntimeException(I18n::get('app_errors.resource_not_writable'));
        }
    }

    /**
     * Function isResourceRootWritable
     *
     * @return bool
     */
    private static function isResourceRootWritable(): bool {
        $root = app_config_path('/app-MoodleMobile-V2/res');
        return is_dir($root) && is_writable($root);
    }

    /**
     * Function normalizePackageUid
     *
     * @param string $packageuid
     * @return string
     */
    private static function normalizePackageUid(string $packageuid): string {
        $packageuid = strtolower(trim($packageuid));
        $packageuid = preg_replace('/[^a-z0-9_.]+/', '_', $packageuid);
        return trim($packageuid, '._');
    }

    /**
     * Function runKeytool
     *
     * @param string $resdir
     * @param string $password
     * @return void
     */
    private static function runKeytool(string $resdir, string $password): void {
        if (!is_dir($resdir)) {
            self::ensureDir($resdir, 0750);
        }

        $command = 'keytool -genkeypair ' .
            '-v ' .
            '-keystore ' . escapeshellarg('key-android/keystore') . ' ' .
            '-alias ' . escapeshellarg('app') . ' ' .
            '-keyalg RSA ' .
            '-keysize 2048 ' .
            '-validity 10000 ' .
            '-storetype PKCS12 ' .
            '-storepass ' . escapeshellarg($password) . ' ' .
            '-keypass ' . escapeshellarg($password) . ' ' .
            '-dname ' . escapeshellarg('CN=Android, OU=Dev, O=App, L=Sao Paulo, ST=SP, C=BR');

        $script = 'cd ' . escapeshellarg($resdir) . ' && ' . $command . ' 2>&1';
        exec('/usr/bin/env bash -lc ' . escapeshellarg($script), $output, $exitcode);
        if ($exitcode != 0) {
            $message = trim(implode("\n", $output));
            throw new RuntimeException(I18n::get('app_errors.keytool_failed', [
                'message' => $message != '' ? I18n::get('app_errors.keytool_return', ['message' => $message]) : '',
            ]));
        }
    }

    /**
     * Function moodleConfigTestUrl
     *
     * @param string $domain
     * @return string
     */
    private static function moodleConfigTestUrl(string $domain): string {
        return self::moodleConfigBaseUrl($domain) . '/local/kopere_mobile/index.php?action=test-config';
    }

    /**
     * Function moodleConfigBaseUrl
     *
     * @param string $domain
     * @return string
     */
    private static function moodleConfigBaseUrl(string $domain): string {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]+/', '', $domain);
        return 'https://' . $domain;
    }

    /**
     * Function moodleConfigEditUrl
     *
     * @param string $domain
     * @param string $key
     * @return string
     */
    private static function moodleConfigEditUrl(string $domain, string $key): string {
        $baseurl = self::moodleConfigBaseUrl($domain);
        $targets = [
            'is_moodle_cookie_secure' => ['/admin/search.php', ['query' => 'cookiesecure']],
            'allowframembedding' => ['/admin/search.php', ['query' => 'allowframembedding']],
            'enablemobilewebservice' => ['/admin/search.php', ['query' => 'enablemobilewebservice']],
            'external_services_moodle_mobile_app' => ['/admin/settings.php', ['section' => 'externalservices']],
            'is_chrome' => ['/admin/settings.php', ['section' => 'local_kopere_mobile']],
            'check_chrome_version_78' => ['/admin/settings.php', ['section' => 'local_kopere_mobile']],
        ];

        $target = $targets[$key] ?? ['/admin/search.php', ['query' => $key]];
        $query = !empty($target[1]) ? '?' . http_build_query($target[1]) : '';
        return $baseurl . $target[0] . $query;
    }

    /**
     * Function moodleConfigInstallUrl
     *
     * @return string
     */
    private static function moodleConfigInstallUrl(): string {
        return 'https://moodle.org/plugins/local_kopere_mobile';
    }

    /**
     * Function moodleConfigBooleanChecks
     *
     * @return array
     */
    private static function moodleConfigBooleanChecks(): array {
        return [
            'is_moodle_cookie_secure' => I18n::get('app_manager.moodle_config_descriptions.is_moodle_cookie_secure'),
            'allowframembedding' => I18n::get('app_manager.moodle_config_descriptions.allowframembedding'),
            'enablemobilewebservice' => I18n::get('app_manager.moodle_config_descriptions.enablemobilewebservice'),
            'external_services_moodle_mobile_app' => I18n::get('app_manager.moodle_config_descriptions.external_services_moodle_mobile_app'),
            'is_chrome' => I18n::get('app_manager.moodle_config_descriptions.is_chrome'),
            'check_chrome_version_78' => I18n::get('app_manager.moodle_config_descriptions.check_chrome_version_78'),
        ];
    }

    /**
     * Function moodleConfigVersionChecks
     *
     * @return string[]
     */
    private static function moodleConfigVersionChecks(): array {
        return [
            'local_kopere_mobile_version' => 'local_kopere_mobile',
        ];
    }

    /**
     * Function fetchMoodleConfigTestUrl
     *
     * @param string $url
     * @return string
     */
    private static function fetchMoodleConfigTestUrl(string $url): string {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => self::moodleConfigUserAgent(),
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            $body = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($body === false) {
                throw new RuntimeException(I18n::get('app_errors.moodle_config_fetch_failed', ['message' => $error]));
            }
            if ($status == 404) {
                throw new RuntimeException(I18n::get('app_errors.moodle_config_plugin_missing'), 404);
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(I18n::get('app_errors.moodle_config_http_status', ['status' => $status]), $status);
            }
            if (trim($body) == '') {
                throw new RuntimeException(I18n::get('app_errors.moodle_config_empty_response'));
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: " . self::moodleConfigUserAgent() . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($body === false) {
            throw new RuntimeException(I18n::get('app_errors.moodle_config_fetch_failed', ['message' => 'file_get_contents']));
        }
        if ($status == 404) {
            throw new RuntimeException(I18n::get('app_errors.moodle_config_plugin_missing'), 404);
        }
        if ($status != 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException(I18n::get('app_errors.moodle_config_http_status', ['status' => $status]), $status);
        }
        if (trim($body) == '') {
            throw new RuntimeException(I18n::get('app_errors.moodle_config_empty_response'));
        }

        return $body;
    }

    /**
     * Function moodleConfigUserAgent
     *
     * @return string
     */
    private static function moodleConfigUserAgent(): string {
        return 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    /**
     * Function ensureDir
     *
     * @param string $dir
     * @param int $mode
     * @return void
     */
    private static function ensureDir(string $dir, int $mode): void {
        if (!is_dir($dir)) {
            mkdir($dir, $mode, true);
        }
    }

    /**
     * Function defaultPackageName
     *
     * @param array $site
     * @return string
     */
    private static function defaultPackageName(array $site): string {
        $config = $site["moodle_config"] ?? [];
        $domain = self::siteDomain($site);
        return ($config["fullname"] ?? $domain);
    }

    /**
     * Function siteDomain
     *
     * @param array $site
     * @return string
     */
    private static function siteDomain(array $site): string {
        return strtolower( $site["domain"] ?? '');
    }

    /**
     * Function formatBytes
     *
     * @param int $bytes
     * @return string
     */
    private static function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2, ',', '.') . ' KB';
        }
        return $bytes . ' B';
    }
}

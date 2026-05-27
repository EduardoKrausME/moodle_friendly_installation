<?php
// App build settings and artifact discovery.
namespace app;

class AppManager {
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

        $stored = JsonStorage::read(self::settingsFile($domain), []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge($defaults, $stored);
        if (empty($settings['package_uid'])) {
            $settings['package_uid'] = $defaults['package_uid'];
        }
        if (empty($settings['package_name'])) {
            $settings['package_name'] = $defaults['package_name'];
        }
        if (empty($settings['statusbarbackgroundcolor'])) {
            $settings['statusbarbackgroundcolor'] = $defaults['statusbarbackgroundcolor'];
        }

        $settings['has_icon'] = !empty($settings['icon_path']) && is_readable((string) $settings['icon_path']);
        return $settings;
    }

    public static function validateSettings(array $input, string $domain): array {
        $errors = [];

        $packageuid = strtolower(trim((string) ($input['package_uid'] ?? '')));
        if ($packageuid === '') {
            $packageuid = self::defaultPackageUid($domain);
        }
        $packageuid = preg_replace('/[^a-z0-9_.]+/', '_', $packageuid);
        $packageuid = trim((string) $packageuid, '._');

        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $packageuid)) {
            $errors['package_uid'] = 'Package UID inválido. Use algo como com.empresa.app ou o domínio em formato válido.';
        }

        $packagename = trim((string) ($input['package_name'] ?? ''));
        if ($packagename === '') {
            $errors['package_name'] = 'Informe o nome do APP.';
        } else if (mb_strlen($packagename) > 80) {
            $errors['package_name'] = 'O nome do APP deve ter no máximo 80 caracteres.';
        }

        $color = strtoupper(trim((string) ($input['statusbarbackgroundcolor'] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $errors['statusbarbackgroundcolor'] = 'Informe uma cor hexadecimal no formato #RRGGBB.';
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

    public static function validateIconUpload(?array $file, bool $required): array {
        if (empty($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [
                'valid' => !$required,
                'error' => $required ? 'Envie um ícone PNG 1024x1024.' : '',
            ];
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'error' => 'Falha ao receber o arquivo enviado.',
            ];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [
                'valid' => false,
                'error' => 'Upload inválido.',
            ];
        }

        $info = @getimagesize($tmp);
        if (!$info || (int) ($info[0] ?? 0) !== 1024 || (int) ($info[1] ?? 0) !== 1024) {
            return [
                'valid' => false,
                'error' => 'O ícone precisa ser exatamente PNG 1024x1024.',
            ];
        }

        $mime = (string) ($info['mime'] ?? '');
        if ($mime !== 'image/png') {
            return [
                'valid' => false,
                'error' => 'O arquivo precisa ser PNG.',
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    public static function saveSettings(string $domain, array $data, ?array $iconfile = null): array {
        $current = JsonStorage::read(self::settingsFile($domain), []);
        if (!is_array($current)) {
            $current = [];
        }

        $settings = array_merge($current, $data);

        if (!empty($iconfile) && isset($iconfile['error']) && (int) $iconfile['error'] === UPLOAD_ERR_OK) {
            $dest = self::storageDir($domain) . '/app-icon.png';
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0750, true);
            }
            if (!move_uploaded_file((string) $iconfile['tmp_name'], $dest)) {
                throw new \RuntimeException('Não foi possível salvar o ícone enviado.');
            }
            chmod($dest, 0640);
            $settings['icon_path'] = $dest;
        }

        $settings['updated_at'] = now_iso();
        $settings['updated_by'] = Auth::user()['username'] ?? 'system';

        JsonStorage::write(self::settingsFile($domain), $settings);
        return $settings;
    }

    public static function appVersion(): string {
        $configfile = app_config_path('/app-MoodleMobile-V2/config.xml');
        if (!is_readable($configfile)) {
            return '1.0.0';
        }

        $content = file_get_contents($configfile);
        if ($content !== false && preg_match('/<widget\b[^>]*\bversion=["\']([^"\']+)["\']/i', $content, $matches)) {
            return (string) $matches[1];
        }

        return '1.0.0';
    }

    public static function buildFiles(string $domain): array {
        $dir = self::storageDir($domain);
        $items = [];
        foreach (glob($dir . '/*.{apk,aab}', GLOB_BRACE) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $basename = basename($file);
            $ext = strtoupper((string) pathinfo($file, PATHINFO_EXTENSION));
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
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $items;
    }

    public static function latestJob(string $domain): ?array {
        foreach (JobManager::all() as $job) {
            if (($job['type'] ?? '') === 'app_build' && ($job['domain'] ?? '') === $domain) {
                return $job;
            }
        }
        return null;
    }

    public static function hasActiveBuildJob(string $domain): bool {
        foreach (JobManager::all() as $job) {
            if (($job['type'] ?? '') !== 'app_build' || ($job['domain'] ?? '') !== $domain) {
                continue;
            }
            if (in_array((string) ($job['status'] ?? ''), ['pending', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function storageDir(string $domain): string {
        $domain = preg_replace('/[^a-z0-9.-]+/', '-', strtolower(trim($domain)));
        $domain = trim((string) $domain, '.-');
        return app_config_path('/data/' . $domain);
    }

    public static function settingsFile(string $domain): string {
        return self::storageDir($domain) . '/app-settings.json';
    }

    public static function defaultPackageUid(string $domain): string {
        $domain = strtolower(trim($domain));
        $parts = array_filter(explode('.', $domain), static fn(string $part): bool => $part !== '');
        $clean = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9_]+/', '_', $part);
            $part = trim((string) $part, '_');
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^[a-z]/', $part)) {
                $part = 'app' . $part;
            }
            $clean[] = $part;
        }

        if (count($clean) < 2) {
            $clean = ['app', 'mylearn'];
        }

        return implode('.', $clean);
    }

    private static function defaultPackageName(array $site): string {
        $config = $site['moodle_config'] ?? [];
        $domain = self::siteDomain($site);
        return (string) ($config['fullname'] ?? "" ?? $domain);
    }

    private static function siteDomain(array $site): string {
        return strtolower(trim((string) ($site['domain'] ?? '')));
    }

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

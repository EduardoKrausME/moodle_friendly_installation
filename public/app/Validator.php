<?php
// Validation rules for Moodle site provisioning.
namespace app;

class Validator {
    public static function normalizeDomain(string $domain): string {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/\/.*$/', '', $domain);
        return trim($domain, '. ');
    }

    public static function validateInstallRequest(array $input, array $allowedbranches = []): array {
        $errors = [];
        $warnings = [];

        $domain = self::normalizeDomain($input["domain"] ?? '');
        if ($domain == '') {
            $errors["domain"] = I18n::get('validation.domain_required');
        } else if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $errors["domain"] = I18n::get('validation.domain_invalid');
        } else if (in_array($domain, app_config('reserved_domains'), true)) {
            $errors["domain"] = I18n::get('validation.domain_reserved');
        }

        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"));
        foreach ($jobs as $job) {
            if (($job["domain"] ?? '') == $domain && in_array(($job["status"] ?? ''), ['pending', 'waiting_dns', 'running'], true)) {
                $errors["domain"] = I18n::get('validation.domain_pending');
                break;
            }
        }

        if ($domain && empty($errors["domain"]) && function_exists('checkdnsrr') && !checkdnsrr($domain, 'A') &&
            !checkdnsrr($domain, 'AAAA')) {
            $warnings["domain_dns"] =
                I18n::get('validation.dns_warning');
        }

        $sitefullname = $input["site_fullname"];
        $adminuser = $input["admin_user"] ?? app_config('default_admin_user');
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $adminuser)) {
            $errors["admin_user"] = I18n::get('validation.admin_user_invalid');
        }

        $adminpass = $input["admin_pass"] ?? '';
        if (strlen($adminpass) < 8) {
            $errors["admin_pass"] = I18n::get('validation.admin_pass_short');
        }

        $adminemail = $input["admin_email"];
        if (!filter_var($adminemail, FILTER_VALIDATE_EMAIL)) {
            $errors["admin_email"] = I18n::get('validation.admin_email_invalid');
        }

        $branch = $input["moodle_branch"] ?? app_config('default_moodle_branch');
        if (!preg_match('/^MOODLE_(\d+)_STABLE$/', $branch, $branchmatches)) {
            $errors["moodle_branch"] = I18n::get('validation.branch_invalid');
        } else if ($branchmatches[1] < 502) {
            $errors["moodle_branch"] = I18n::get('validation.branch_min');
        } else if (!empty($allowedbranches) && !in_array($branch, $allowedbranches, true)) {
            $errors["moodle_branch"] = I18n::get('validation.branch_unavailable');
        }

        if (!empty($_FILES["kopere_backup_zip"]) && is_array($_FILES["kopere_backup_zip"])) {
            $backuperror = self::validateKopereBackupUpload($_FILES["kopere_backup_zip"]);
            if ($backuperror !== null) {
                $errors["kopere_backup_zip"] = $backuperror;
            }
        }

        $issuecert = !empty($input["issue_cert"]);
        $language = I18n::moodleLanguage(isset($input["language"]) && is_string($input["language"]) ? $input["language"] : I18n::current());

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => [
                'domain' => $domain,
                'site_fullname' => $sitefullname,
                'admin_user' => $adminuser,
                'admin_pass' => $adminpass,
                'admin_email' => $adminemail,
                'moodle_branch' => $branch,
                'issue_cert' => $issuecert,
                'language' => $language,
            ],
        ];
    }

    /**
     * Validates the optional Kopere Dashboard backup ZIP upload.
     *
     * @param array $file
     * @return string|null
     */
    public static function validateKopereBackupUpload(array $file): ?string {
        $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            return I18n::get('validation.kopere_backup_upload_failed');
        }

        $name = isset($file["name"]) && is_string($file["name"]) ? $file["name"] : '';
        $tmpname = isset($file["tmp_name"]) && is_string($file["tmp_name"]) ? $file["tmp_name"] : '';
        if ($tmpname == '' || !is_uploaded_file($tmpname)) {
            return I18n::get('validation.upload_invalid');
        }
        if (!preg_match('/\.zip$/i', $name)) {
            return I18n::get('validation.kopere_backup_zip_required');
        }
        if (!class_exists('ZipArchive')) {
            return I18n::get('validation.zip_not_available');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpname) !== true) {
            return I18n::get('validation.kopere_backup_zip_invalid');
        }

        $hasSchema = false;
        $hasData = false;
        $hasMoodledata = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', $zip->getNameIndex($i));
            if (preg_match('#(^|/)schema/[^/]+\.json$#i', $entry)) {
                $hasSchema = true;
            }
            if (preg_match('#(^|/)data/[^/]+\.csv$#i', $entry)) {
                $hasData = true;
            }
            if (preg_match('#(^|/)moodledata/#i', $entry) || preg_match('#(^|/)(filedir|files|cache|localcache|sessions|temp|trashdir)/#i', $entry)) {
                $hasMoodledata = true;
            }
        }
        $zip->close();

        if (($hasSchema && $hasData) || $hasMoodledata) {
            return null;
        }

        return I18n::get('validation.kopere_backup_zip_unknown');
    }

    /**
     * Stores the optional Kopere Dashboard backup ZIP outside the public webroot.
     *
     * @param array $file
     * @param string $domain
     * @return string|null
     */
    public static function storeKopereBackupUpload(array $file, string $domain): ?string {
        $validationerror = self::validateKopereBackupUpload($file);
        if ($validationerror !== null) {
            if ((int) ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return null;
            }
            throw new \RuntimeException($validationerror);
        }

        if ((int) ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $uploaddir = app_config_path('/data/restore-uploads');
        if (!is_dir($uploaddir)) {
            mkdir($uploaddir, 0750, true);
        }

        $safeDomain = preg_replace('/[^a-z0-9.-]+/', '-', strtolower($domain));
        $destination = $uploaddir . '/' . $safeDomain . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            throw new \RuntimeException(I18n::get('validation.kopere_backup_store_failed'));
        }
        chmod($destination, 0640);
        return $destination;
    }
}

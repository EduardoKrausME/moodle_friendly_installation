<?php
// Validation rules for Moodle site provisioning.
namespace app;

use RuntimeException;
use ZipArchive;

/**
 * Class Validator
 */
class Validator {
    /**
     * Function themeColors
     *
     * @return array<int, array{primary: string, secondary: string}>
     */
    public static function themeColors(): array {
        return [
            [
                "primary" => "#000428",
                "secondary" => "#000d84",
            ],
            [
                "primary" => "#070000",
                "secondary" => "#630000",
            ],
            [
                "primary" => "#314755",
                "secondary" => "#53788f",
            ],
            [
                "primary" => "#314755",
                "secondary" => "#53788f",
            ],
            [
                "primary" => "#007bc3",
                "secondary" => "#20adff",
            ],
            [
                "primary" => "#007fff",
                "secondary" => "#0051a3",
            ],
            [
                "primary" => "#00bf8f",
                "secondary" => "#1cffc6",
            ],
            [
                "primary" => "#00c3b0",
                "secondary" => "#20ffe9",
            ],
            [
                "primary" => "#30e8bf",
                "secondary" => "#13a988",
            ],
            [
                "primary" => "#83a4d4",
                "secondary" => "#4172bb",
            ],
            [
                "primary" => "#7303c0",
                "secondary" => "#a323fc",
            ],
            [
                "primary" => "#8000ff",
                "secondary" => "#5200a3",
            ],
            [
                "primary" => "#86377b",
                "secondary" => "#bc5caf",
            ],
            [
                "primary" => "#b21f1f",
                "secondary" => "#e04d4d",
            ],
            [
                "primary" => "#c10f41",
                "secondary" => "#f03c6e",
            ],
            [
                "primary" => "#d12924",
                "secondary" => "#e66f6b",
            ],
            [
                "primary" => "#fc354c",
                "secondary" => "#d2031b",
            ],
            [
                "primary" => "#ff0000",
                "secondary" => "#a30000",
            ],
            [
                "primary" => "#ff007f",
                "secondary" => "#a30051",
            ],
            [
                "primary" => "#ff00ff",
                "secondary" => "#a300a3",
            ],
            [
                "primary" => "#f55ff2",
                "secondary" => "#ea0fe5",
            ],
            [
                "primary" => "#fd81b5",
                "secondary" => "#fc2780",
            ],
            [
                "primary" => "#ff512f",
                "secondary" => "#d22200",
            ],
            [
                "primary" => "#e65c00",
                "secondary" => "#ff8e43",
            ],
            [
                "primary" => "#ff8000",
                "secondary" => "#a35200",
            ],
            [
                "primary" => "#c99b10",
                "secondary" => "#f0c645",
            ],
            [
                "primary" => "#997540",
                "secondary" => "#c4a271",
            ],
        ];
    }

    /**
     * Function resolveThemePaletteInput
     *
     * @param mixed $value
     * @return array{valid: bool, index: int, primary: string, secondary: string}
     */
    public static function resolveThemePaletteInput(mixed $value): array {
        $colors = self::themeColors();
        $index = is_scalar($value) ? (int) $value : 0;

        if (!array_key_exists($index, $colors)) {
            $index = 0;
            return [
                "valid" => false,
                "index" => $index,
                "primary" => $colors[$index]["primary"],
                "secondary" => $colors[$index]["secondary"],
            ];
        }

        return [
            "valid" => true,
            "index" => $index,
            "primary" => $colors[$index]["primary"],
            "secondary" => $colors[$index]["secondary"],
        ];
    }

    /**
     * Function normalizeDomain
     *
     * @param string $domain
     * @return string
     */
    public static function normalizeDomain(string $domain): string {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', "", $domain);
        $domain = preg_replace('/\/.*$/', "", $domain);
        return trim($domain, ". ");
    }

    /**
     * Function validateInstallRequest
     *
     * @param array $input
     * @param array $allowedbranches
     * @return array
     */
    public static function validateInstallRequest(array $input, array $allowedbranches = []): array {
        $errors = [];
        $warnings = [];

        $domain = self::normalizeDomain($input["domain"] ?? "");
        if ($domain == "") {
            $errors["domain"] = I18n::get("validation.domain_required");
        } else if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $errors["domain"] = I18n::get("validation.domain_invalid");
        } else if (in_array($domain, app_config("reserved_domains"), true)) {
            $errors["domain"] = I18n::get("validation.domain_reserved");
        } else if (file_exists("/home/{$domain}") || is_link("/home/{$domain}")) {
            $errors["domain"] = I18n::get("validation.domain_path_exists");
        }

        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"));
        foreach ($jobs as $job) {
            if (($job["domain"] ?? "") == $domain && in_array(($job["status"] ?? ""), ["pending", "waiting_dns", "running"], true)) {
                $errors["domain"] = I18n::get("validation.domain_pending");
                break;
            }
        }

        if ($domain && empty($errors["domain"]) && function_exists("checkdnsrr") && !checkdnsrr($domain, "A") &&
            !checkdnsrr($domain, "AAAA")) {
            $warnings["domain_dns"] =
                I18n::get("validation.dns_warning");
        }

        $sitefullname = $input["site_fullname"];
        $adminuser = $input["admin_user"] ?? app_config("default_admin_user");
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $adminuser)) {
            $errors["admin_user"] = I18n::get("validation.admin_user_invalid");
        }

        $adminpass = $input["admin_pass"] ?? "";
        if (strlen($adminpass) < 8) {
            $errors["admin_pass"] = I18n::get("validation.admin_pass_short");
        }

        $adminemail = $input["admin_email"];
        if (!filter_var($adminemail, FILTER_VALIDATE_EMAIL)) {
            $errors["admin_email"] = I18n::get("validation.admin_email_invalid");
        }

        $branch = $input["moodle_branch"];
        if (!preg_match('/^MOODLE_(\d+)_STABLE$/', $branch, $branchmatches)) {
            $errors["moodle_branch"] = I18n::get("validation.branch_invalid");
        } else if ($branchmatches[1] < 502) {
            $errors["moodle_branch"] = I18n::get("validation.branch_min");
        } else if (!empty($allowedbranches) && !in_array($branch, $allowedbranches, true)) {
            $errors["moodle_branch"] = I18n::get("validation.branch_unavailable");
        }

        $themePalette = self::resolveThemePaletteInput($input["theme_palette"] ?? 0);
        if (!$themePalette["valid"]) {
            $errors["theme_palette"] = "Invalid theme color selection.";
        }

        if (!empty($_FILES["kopere_backup_zip"]) && is_array($_FILES["kopere_backup_zip"])) {
            $backuperror = self::validateKopereBackupUpload($_FILES["kopere_backup_zip"]);
            if ($backuperror !== null) {
                $errors["kopere_backup_zip"] = $backuperror;
            }
        }

        $issuecert = !empty($input["issue_cert"]);
        $language = I18n::moodleLanguage(isset($input["language"]) && is_string($input["language"])
            ? $input["language"]
            : I18n::current());

        return [
            "valid" => empty($errors),
            "errors" => $errors,
            "warnings" => $warnings,
            "data" => [
                "domain" => $domain,
                "site_fullname" => $sitefullname,
                "admin_user" => $adminuser,
                "admin_pass" => $adminpass,
                "admin_email" => $adminemail,
                "moodle_branch" => $branch,
                "issue_cert" => $issuecert,
                "language" => $language,
                "theme_palette" => $themePalette["index"],
                "theme_primary" => $themePalette["primary"],
                "theme_secondary" => $themePalette["secondary"],
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
            return I18n::get("validation.kopere_backup_upload_failed");
        }

        $name = isset($file["name"]) && is_string($file["name"]) ? $file["name"] : "";
        $tmpname = isset($file["tmp_name"]) && is_string($file["tmp_name"]) ? $file["tmp_name"] : "";
        if ($tmpname == "" || !is_uploaded_file($tmpname)) {
            return I18n::get("validation.upload_invalid");
        }
        if (!preg_match('/\.zip$/i', $name)) {
            return I18n::get("validation.kopere_backup_zip_required");
        }
        if (!class_exists("ZipArchive")) {
            return I18n::get("validation.zip_not_available");
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpname) !== true) {
            return I18n::get("validation.kopere_backup_zip_invalid");
        }

        $hasSchema = false;
        $hasData = false;
        $hasMoodledata = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', "/", $zip->getNameIndex($i));
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

        return I18n::get("validation.kopere_backup_zip_unknown");
    }

    /**
     * Stores the optional Kopere Dashboard backup ZIP outside the public webroot.
     *
     * @param array $file
     * @param string $domain
     * @return string|null
     * @throws \Random\RandomException
     */
    public static function storeKopereBackupUpload(array $file, string $domain): ?string {
        $validationerror = self::validateKopereBackupUpload($file);
        if ($validationerror !== null) {
            if ((int) ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return null;
            }
            throw new RuntimeException($validationerror);
        }

        if ((int) ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $uploaddir = app_config_path("/data/restore-uploads");
        if (!is_dir($uploaddir)) {
            mkdir($uploaddir, 0750, true);
        }

        $safeDomain = preg_replace('/[^a-z0-9.-]+/', "-", strtolower($domain));
        $destination = "{$uploaddir}/{$safeDomain}-" . date("Ymd-His") . "-" . bin2hex(random_bytes(4)) . ".zip";
        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            throw new RuntimeException(I18n::get("validation.kopere_backup_store_failed"));
        }
        chmod($destination, 0640);
        return $destination;
    }
}

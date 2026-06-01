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

        $domain = self::normalizeDomain($input['domain'] ?? '');
        if ($domain == '') {
            $errors['domain'] = I18n::get('validation.domain_required');
        } else if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $errors['domain'] = I18n::get('validation.domain_invalid');
        } else if (in_array($domain, app_config('reserved_domains'), true)) {
            $errors['domain'] = I18n::get('validation.domain_reserved');
        }

        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"));
        foreach ($jobs as $job) {
            if (($job['domain'] ?? '') == $domain && in_array(($job['status'] ?? ''), ['pending', 'waiting_dns', 'running'], true)) {
                $errors['domain'] = I18n::get('validation.domain_pending');
                break;
            }
        }

        if ($domain && empty($errors['domain']) && function_exists('checkdnsrr') && !checkdnsrr($domain, 'A') &&
            !checkdnsrr($domain, 'AAAA')) {
            $warnings['domain_dns'] =
                I18n::get('validation.dns_warning');
        }

        $sitefullname = $input['site_fullname'];
        $adminuser = $input['admin_user'] ?? app_config('default_admin_user');
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $adminuser)) {
            $errors['admin_user'] = I18n::get('validation.admin_user_invalid');
        }

        $adminpass = $input['admin_pass'] ?? '';
        if (strlen($adminpass) < 8) {
            $errors['admin_pass'] = I18n::get('validation.admin_pass_short');
        }

        $adminemail = $input['admin_email'];
        if (!filter_var($adminemail, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = I18n::get('validation.admin_email_invalid');
        }

        $branch = $input['moodle_branch'] ?? app_config('default_moodle_branch');
        if (!preg_match('/^MOODLE_(\d+)_STABLE$/', $branch, $branchmatches)) {
            $errors['moodle_branch'] = I18n::get('validation.branch_invalid');
        } else if ($branchmatches[1] < 502) {
            $errors['moodle_branch'] = I18n::get('validation.branch_min');
        } else if (!empty($allowedbranches) && !in_array($branch, $allowedbranches, true)) {
            $errors['moodle_branch'] = I18n::get('validation.branch_unavailable');
        }

        $issuecert = !empty($input['issue_cert']);

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
            ],
        ];
    }
}

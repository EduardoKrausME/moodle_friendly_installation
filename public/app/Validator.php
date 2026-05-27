<?php
// Validation rules for Moodle site provisioning.
namespace app;

class Validator {
    public static function normalizeDomain(string $domain): string {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/\/.*$/', '', $domain);
        return trim((string) $domain, '. ');
    }

    public static function validateInstallRequest(array $input, array $allowedbranches = []): array {
        $errors = [];
        $warnings = [];

        $domain = self::normalizeDomain((string) ($input['domain'] ?? ''));
        if ($domain === '') {
            $errors['domain'] = 'Informe o domínio.';
        } else if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $errors['domain'] = 'Domínio inválido. Use apenas domínio, sem http:// e sem caminho.';
        } else if (in_array($domain, app_config('reserved_domains'), true)) {
            $errors['domain'] = 'Este domínio está reservado para o painel.';
        }

        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"), []);
        foreach ($jobs as $job) {
            if (($job['domain'] ?? '') === $domain && in_array(($job['status'] ?? ''), ['pending', 'waiting_dns', 'running'], true)) {
                $errors['domain'] = 'Já existe uma instalação pendente ou em execução para este domínio.';
                break;
            }
        }

        if ($domain && empty($errors['domain']) && function_exists('checkdnsrr') && !checkdnsrr($domain, 'A') &&
            !checkdnsrr($domain, 'AAAA')) {
            $warnings['domain_dns'] =
                'Não encontrei registro A/AAAA para o domínio. O Certbot pode falhar se o DNS ainda não apontar para este servidor.';
        }

        $sitefullname = trim((string) ($input['site_fullname'] ?? ''));
        if ($sitefullname === '') {
            $sitefullname = app_config('default_site_fullname_prefix') . ' - ' . $domain;
        }

        $adminuser = trim((string) ($input['admin_user'] ?? app_config('default_admin_user')));
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $adminuser)) {
            $errors['admin_user'] = 'Usuário admin inválido. Use 3 a 32 caracteres, começando por letra.';
        }

        $adminpass = (string) ($input['admin_pass'] ?? '');
        if (strlen($adminpass) < 8) {
            $errors['admin_pass'] = 'A senha do admin do Moodle precisa ter ao menos 8 caracteres.';
        }

        $adminemail = trim((string) ($input['admin_email'] ?? app_config('default_admin_email')));
        if (!filter_var($adminemail, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'E-mail admin inválido.';
        }

        $branch = trim((string) ($input['moodle_branch'] ?? app_config('default_moodle_branch')));
        if (!preg_match('/^MOODLE_(\d+)_STABLE$/', $branch, $branchmatches)) {
            $errors['moodle_branch'] = 'Branch inválida.';
        } else if ((int) $branchmatches[1] < 502) {
            $errors['moodle_branch'] = 'Selecione uma branch Moodle 502 ou superior.';
        } else if (!empty($allowedbranches) && !in_array($branch, $allowedbranches, true)) {
            $errors['moodle_branch'] = 'Selecione uma branch Moodle disponível.';
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

    public static function dbSafeName(string $domain, string $prefix): string {
        $name = preg_replace('/[^a-z0-9]+/', '_', strtolower($domain));
        $name = trim((string) $name, '_');
        return substr($prefix . '_' . $name, 0, 48);
    }
}

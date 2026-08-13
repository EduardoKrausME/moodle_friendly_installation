<?php

namespace app;

use RuntimeException;
use Throwable;

/**
 * Handles asynchronous panel-domain changes requested by the web UI and
 * executed with root privileges by bin/cron-root-runner.php.
 */
class PanelDomainManager {
    private const array ACTIVE_STATUSES = ["pending", "waiting_dns", "running"];

    /**
     * Creates or reuses a domain activation request.
     *
     * @param string $baseurl
     * @param string $source
     * @return array
     */
    public static function request(string $baseurl, string $source): array {
        $requestedurl = self::canonicalDomainUrl($baseurl);
        $domain = PanelConfigManager::baseUrlHost($requestedurl);
        $publicip = PanelConfigManager::detect_public_ipv4();
        if ($publicip === "") {
            throw new RuntimeException(self::message("public_ip_unavailable"));
        }

        $current = self::state();
        if (($current["domain"] ?? "") === $domain
            && (($current["requested_url"] ?? "") === $requestedurl)
            && (in_array(($current["status"] ?? ""), self::ACTIVE_STATUSES, true)
                || ($current["status"] ?? "") === "done")
        ) {
            return self::publicState($current);
        }

        $job = [
            "id" => "panel_domain_" . bin2hex(random_bytes(8)),
            "type" => "panel_domain",
            "status" => "pending",
            "step" => "pending",
            "domain" => $domain,
            "requested_url" => $requestedurl,
            "previous_base_url" => (string) (app_config("base_url") ?? ""),
            "public_ip" => $publicip,
            "source" => in_array($source, ["onboarding", "configuration"], true) ? $source : "configuration",
            "message" => self::message("queued"),
            "completed_steps" => ["request_received", "public_ip_detected"],
            "step_messages" => [
                "request_received" => self::message("queued"),
                "public_ip_detected" => self::message("public_ip_detected", ["ip" => $publicip]),
            ],
            "created_at" => now_iso(),
            "updated_at" => now_iso(),
            "requested_by" => Auth::check() ? (Auth::user()["username"] ?? "admin") : "initial-setup",
        ];
        self::writeState($job);
        return self::publicState($job);
    }

    /**
     * Returns the currently stored domain job.
     *
     * @return array
     */
    public static function state(): array {
        $state = JsonStorage::read(self::statePath());
        return is_array($state) ? $state : [];
    }

    /**
     * Returns one job only when its id matches.
     *
     * @param string $id
     * @return array
     */
    public static function status(string $id): array {
        $state = self::state();
        if ($id === "" || ($state["id"] ?? "") !== $id) {
            throw new RuntimeException(self::message("job_not_found"));
        }
        return self::publicState($state);
    }

    /**
     * Confirms that the privileged domain job completed for this exact domain.
     *
     * @param string $id
     * @param string $baseurl
     * @return bool
     */
    public static function isCompletedFor(string $id, string $baseurl): bool {
        if ($id === "") {
            return false;
        }

        try {
            $requestedurl = self::canonicalDomainUrl($baseurl);
        } catch (Throwable) {
            return false;
        }

        $state = self::state();
        return ($state["id"] ?? "") === $id
            && ($state["status"] ?? "") === "done"
            && ($state["requested_url"] ?? "") === $requestedurl;
    }

    /**
     * Returns a canonical HTTPS URL for a domain-only panel address.
     *
     * @param string $baseurl
     * @return string
     */
    public static function canonicalDomainUrl(string $baseurl): string {
        $normalized = PanelConfigManager::normalizeBaseUrl($baseurl);
        $host = PanelConfigManager::baseUrlHost($normalized);
        if ($host === "" || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException(self::message("domain_required"));
        }

        $port = parse_url($normalized, PHP_URL_PORT);
        if ($port !== null && !in_array((int) $port, [80, 443], true)) {
            throw new RuntimeException(self::message("custom_port_not_supported"));
        }

        return "https://{$host}";
    }

    /**
     * Executes the pending privileged request. Called once per minute by root cron.
     * Waiting for DNS does not block the normal Moodle job queue.
     *
     * @return array|null
     */
    public static function runPendingAsRoot(): ?array {
        $state = self::state();
        if (empty($state) || !in_array(($state["status"] ?? ""), self::ACTIVE_STATUSES, true)) {
            return null;
        }

        if (function_exists("posix_geteuid") && posix_geteuid() !== 0) {
            throw new RuntimeException("Panel domain provisioning must run as root.");
        }

        $domain = (string) ($state["domain"] ?? "");
        $publicip = (string) ($state["public_ip"] ?? "");
        if ($domain === "" || $publicip === "") {
            self::fail($state, "Invalid domain provisioning state.");
            return self::state();
        }

        $dns = self::checkDns($domain, $publicip);
        if (!$dns["ready"]) {
            $state["status"] = "waiting_dns";
            $state["step"] = "waiting_dns";
            $state["message"] = $dns["message"];
            $state["dns"] = $dns;
            $state["step_messages"]["dns_validated"] = $dns["message"];
            $state["last_dns_check_at"] = now_iso();
            if (empty($state["waiting_dns_since"])) {
                $state["waiting_dns_since"] = now_iso();
            }
            self::writeState($state);
            return self::publicState($state);
        }

        $state = self::markCompleted($state, "dns_validated", $dns["message"]);

        $backupdir = app_config_path("/data/runtime/panel-domain/{$state["id"]}");
        $paths = self::managedPaths();

        try {
            self::updateStep($state, "configuring_webserver", self::message("configuring_webserver"));
            self::backupManagedFiles($paths, $backupdir);

            self::writeIpNginxConfig($paths["nginx_ip"], $publicip);
            self::writeDomainNginxConfig($paths["nginx_domain"], $domain);
            self::updateApachePanelConfig($paths["apache"], $domain, $publicip);
            $state = self::markCompleted($state, "configuring_webserver", self::message("configuring_webserver_done"));

            self::updateStep($state, "testing_webserver", self::message("testing_webserver"));
            self::testNginx();
            self::testApache();
            self::reloadWebServers();
            $state = self::markCompleted($state, "testing_webserver", self::message("testing_webserver_done"));

            self::updateStep($state, "issuing_certificate", self::message("issuing_certificate"));
            self::issueCertificate($domain);
            $state = self::markCompleted($state, "issuing_certificate", self::message("issuing_certificate_done"));

            self::updateStep($state, "activating_domain", self::message("activating_domain"));
            self::testNginx();
            self::reloadNginx();
            $state = self::markCompleted($state, "activating_domain", self::message("activating_domain_done"));

            $state = self::state();
            $state["status"] = "done";
            $state["step"] = "done";
            $state["result_url"] = "https://{$domain}";
            $state["message"] = self::message("done");
            $state["finished_at"] = now_iso();
            $state["dns"] = $dns;
            self::writeState($state);
        } catch (Throwable $e) {
            self::restoreManagedFiles($paths, $backupdir);
            try {
                self::testNginx();
                self::testApache();
                self::reloadWebServers();
            } catch (Throwable $rollbackerror) {
                $e = new RuntimeException(
                    $e->getMessage() . " | Rollback: " . $rollbackerror->getMessage(),
                    0,
                    $e
                );
            }
            self::fail(self::state(), $e->getMessage());
        }

        return self::publicState(self::state());
    }

    /**
     * @param array $state
     * @param string $step
     * @param string $message
     * @return void
     */
    private static function updateStep(array $state, string $step, string $message): void {
        $latest = self::state();
        if (($latest["id"] ?? "") === ($state["id"] ?? "")) {
            $state = $latest;
        }
        $state["status"] = "running";
        $state["step"] = $step;
        $state["message"] = $message;
        if (!isset($state["step_messages"]) || !is_array($state["step_messages"])) {
            $state["step_messages"] = [];
        }
        $state["step_messages"][$step] = $message;
        if (empty($state["started_at"])) {
            $state["started_at"] = now_iso();
        }
        self::writeState($state);
    }

    /**
     * Marks one checklist item as completed while preserving its message.
     *
     * @param array $state
     * @param string $step
     * @param string $message
     * @return array
     */
    private static function markCompleted(array $state, string $step, string $message): array {
        $latest = self::state();
        if (($latest["id"] ?? "") === ($state["id"] ?? "")) {
            $state = $latest;
        }
        if (!isset($state["completed_steps"]) || !is_array($state["completed_steps"])) {
            $state["completed_steps"] = [];
        }
        if (!in_array($step, $state["completed_steps"], true)) {
            $state["completed_steps"][] = $step;
        }
        if (!isset($state["step_messages"]) || !is_array($state["step_messages"])) {
            $state["step_messages"] = [];
        }
        $state["step_messages"][$step] = $message;
        self::writeState($state);
        return $state;
    }

    /**
     * @param array $state
     * @param string $message
     * @return void
     */
    private static function fail(array $state, string $message): void {
        $state["status"] = "failed";
        $state["failed_step"] = (string) ($state["step"] ?? "");
        $state["step"] = "failed";
        $state["message"] = $message;
        if (!isset($state["step_messages"]) || !is_array($state["step_messages"])) {
            $state["step_messages"] = [];
        }
        if ($state["failed_step"] !== "") {
            $state["step_messages"][$state["failed_step"]] = $message;
        }
        $state["error"] = $message;
        $state["finished_at"] = now_iso();
        self::writeState($state);
    }

    /**
     * @param string $domain
     * @param string $publicip
     * @return array
     */
    private static function checkDns(string $domain, string $publicip): array {
        $arecords = @dns_get_record($domain, DNS_A);
        $aaaarecords = @dns_get_record($domain, DNS_AAAA);
        $arecords = is_array($arecords) ? $arecords : [];
        $aaaarecords = is_array($aaaarecords) ? $aaaarecords : [];

        $ipv4 = [];
        foreach ($arecords as $record) {
            if (!empty($record["ip"]) && is_string($record["ip"])) {
                $ipv4[] = $record["ip"];
            }
        }
        $ipv4 = array_values(array_unique($ipv4));

        $ipv6 = [];
        foreach ($aaaarecords as $record) {
            if (!empty($record["ipv6"]) && is_string($record["ipv6"])) {
                $ipv6[] = strtolower($record["ipv6"]);
            }
        }
        $ipv6 = array_values(array_unique($ipv6));

        if (!in_array($publicip, $ipv4, true)) {
            return [
                "ready" => false,
                "a" => $ipv4,
                "aaaa" => $ipv6,
                "expected_ipv4" => $publicip,
                "message" => empty($ipv4)
                    ? self::message("dns_missing", ["domain" => $domain, "ip" => $publicip])
                    : self::message("dns_mismatch", [
                        "domain" => $domain,
                        "expected" => $publicip,
                        "received" => implode(", ", $ipv4),
                    ]),
            ];
        }

        if (!empty($ipv6)) {
            $localipv6 = self::localGlobalIpv6();
            foreach ($ipv6 as $address) {
                if (!in_array(strtolower($address), $localipv6, true)) {
                    return [
                        "ready" => false,
                        "a" => $ipv4,
                        "aaaa" => $ipv6,
                        "expected_ipv4" => $publicip,
                        "local_ipv6" => $localipv6,
                        "message" => self::message("dns_aaaa_mismatch", ["received" => implode(", ", $ipv6)]),
                    ];
                }
            }
        }

        return [
            "ready" => true,
            "a" => $ipv4,
            "aaaa" => $ipv6,
            "expected_ipv4" => $publicip,
            "message" => self::message("dns_ready"),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function localGlobalIpv6(): array {
        $ipbinary = self::binary(["/usr/sbin/ip", "/usr/bin/ip", "/sbin/ip", "/bin/ip"]);
        if ($ipbinary === "") {
            return [];
        }

        [$code, $output] = self::runCommand([$ipbinary, "-6", "-o", "addr", "show", "scope", "global"], false);
        if ($code !== 0) {
            return [];
        }

        $addresses = [];
        if (preg_match_all('/\binet6\s+([^\s\/]+)\/\d+/i', $output, $matches)) {
            foreach ($matches[1] as $address) {
                $addresses[] = strtolower($address);
            }
        }
        return array_values(array_unique($addresses));
    }

    /**
     * @return array{nginx_domain:string,nginx_ip:string,apache:string}
     */
    private static function managedPaths(): array {
        $apache = is_dir("/etc/apache2")
            ? "/etc/apache2/sites-enabled/admin.moodle.conf"
            : "/etc/httpd/sites-enabled/admin.moodle.conf";

        return [
            "nginx_domain" => "/etc/nginx/sites-enabled/admin.moodle.conf",
            "nginx_ip" => "/etc/nginx/sites-enabled/admin.moodle-ip.conf",
            "apache" => $apache,
        ];
    }

    /**
     * @param array $paths
     * @param string $backupdir
     * @return void
     */
    private static function backupManagedFiles(array $paths, string $backupdir): void {
        if (!is_dir($backupdir) && !mkdir($backupdir, 0700, true) && !is_dir($backupdir)) {
            throw new RuntimeException("Cannot create panel domain backup directory: {$backupdir}");
        }

        foreach ($paths as $key => $path) {
            $backup = "{$backupdir}/{$key}.conf";
            $missing = "{$backup}.missing";

            // Keep the very first snapshot. If the root process is interrupted
            // halfway through provisioning, the next cron run must still be able
            // to roll back to the configuration that existed before the request.
            if (is_file($backup) || is_file($missing)) {
                continue;
            }

            if (is_file($path)) {
                if (!copy($path, $backup)) {
                    throw new RuntimeException("Cannot backup {$path}");
                }
            } else {
                file_put_contents($missing, "missing\n");
            }
        }
    }

    /**
     * @param array $paths
     * @param string $backupdir
     * @return void
     */
    private static function restoreManagedFiles(array $paths, string $backupdir): void {
        foreach ($paths as $key => $path) {
            $backup = "{$backupdir}/{$key}.conf";
            $missing = "{$backup}.missing";
            if (is_file($backup)) {
                @copy($backup, $path);
            } else if (is_file($missing)) {
                @unlink($path);
            }
        }
    }

    /**
     * Keeps the server reachable directly by its public IPv4 address over HTTP.
     * This vhost is deliberately not redirected to HTTPS by Certbot.
     *
     * @param string $path
     * @param string $publicip
     * @return void
     */
    private static function writeIpNginxConfig(string $path, string $publicip): void {
        $config = <<<NGINX
server {
    listen 80 default_server;
    server_name _ {$publicip};

    client_max_body_size 128m;

    location / {
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_hide_header X-Powered-By;
        proxy_pass http://127.0.0.1:8080;
    }
}
NGINX;
        self::writeSystemFile($path, $config . "\n");
    }

    /**
     * @param string $path
     * @param string $domain
     * @return void
     */
    private static function writeDomainNginxConfig(string $path, string $domain): void {
        $config = <<<NGINX
server {
    listen 80;
    server_name {$domain};

    client_max_body_size 128m;

    location / {
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_hide_header X-Powered-By;
        proxy_pass http://127.0.0.1:8080;
    }
}
NGINX;
        self::writeSystemFile($path, $config . "\n");
    }

    /**
     * @param string $path
     * @param string $domain
     * @param string $publicip
     * @return void
     */
    private static function updateApachePanelConfig(string $path, string $domain, string $publicip): void {
        if (!is_readable($path)) {
            throw new RuntimeException("Apache panel virtual host not found: {$path}");
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === "") {
            throw new RuntimeException("Cannot read Apache panel virtual host: {$path}");
        }

        if (preg_match('/^\s*ServerName\s+.*$/mi', $content)) {
            $content = preg_replace('/^\s*ServerName\s+.*$/mi', "    ServerName {$domain}", $content, 1);
        } else {
            $content = preg_replace('/(<VirtualHost[^>]+>\s*)/i', "$1\n    ServerName {$domain}\n", $content, 1);
        }

        if (preg_match('/^\s*ServerAlias\s+.*$/mi', $content)) {
            $content = preg_replace(
                '/^\s*ServerAlias\s+.*$/mi',
                "    ServerAlias {$publicip} localhost 127.0.0.1",
                $content,
                1
            );
        } else {
            $content = preg_replace(
                '/(^\s*ServerName\s+.*$)/mi',
                "$1\n    ServerAlias {$publicip} localhost 127.0.0.1",
                $content,
                1
            );
        }

        self::writeSystemFile($path, $content);
    }

    /**
     * @param string $path
     * @param string $content
     * @return void
     */
    private static function writeSystemFile(string $path, string $content): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException("Directory does not exist: {$dir}");
        }
        $tmp = "{$path}.panel-domain.tmp";
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$tmp}");
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace {$path}");
        }
    }

    /**
     * @return void
     */
    private static function testNginx(): void {
        $binary = self::binary(["/usr/sbin/nginx", "/usr/bin/nginx", "/sbin/nginx", "/bin/nginx"]);
        if ($binary === "") {
            throw new RuntimeException("NGINX binary not found.");
        }
        self::runCommand([$binary, "-t"]);
    }

    /**
     * @return void
     */
    private static function testApache(): void {
        $binary = self::binary([
            "/usr/sbin/apache2ctl",
            "/usr/sbin/apachectl",
            "/usr/bin/apache2ctl",
            "/usr/sbin/httpd",
        ]);
        if ($binary === "") {
            throw new RuntimeException("Apache control binary not found.");
        }
        self::runCommand([$binary, str_ends_with($binary, "httpd") ? "-t" : "configtest"]);
    }

    /**
     * @return void
     */
    private static function reloadWebServers(): void {
        self::reloadApache();
        self::reloadNginx();
    }

    /**
     * @return void
     */
    private static function reloadApache(): void {
        $service = is_dir("/etc/apache2") ? "apache2" : "httpd";
        self::systemctl("reload", $service);
    }

    /**
     * @return void
     */
    private static function reloadNginx(): void {
        self::systemctl("reload", "nginx");
    }

    /**
     * @param string $action
     * @param string $service
     * @return void
     */
    private static function systemctl(string $action, string $service): void {
        $binary = self::binary(["/usr/bin/systemctl", "/bin/systemctl"]);
        if ($binary === "") {
            throw new RuntimeException("systemctl not found.");
        }
        self::runCommand([$binary, $action, $service]);
    }

    /**
     * @param string $domain
     * @return void
     */
    private static function issueCertificate(string $domain): void {
        $certbot = self::binary(["/usr/bin/certbot", "/usr/local/bin/certbot", "/bin/certbot"]);
        if ($certbot === "") {
            throw new RuntimeException("Certbot not found.");
        }

        self::runCommand([
            $certbot,
            "--nginx",
            "-d",
            $domain,
            "--non-interactive",
            "--agree-tos",
            "--register-unsafely-without-email",
            "--redirect",
            "--keep-until-expiring",
        ]);
    }

    /**
     * @param array<int, string> $candidates
     * @return string
     */
    private static function binary(array $candidates): string {
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return "";
    }

    /**
     * @param array<int, string> $arguments
     * @param bool $throw
     * @return array{0:int,1:string}
     */
    private static function runCommand(array $arguments, bool $throw = true): array {
        $command = implode(" ", array_map("escapeshellarg", $arguments)) . " 2>&1";
        $lines = [];
        $code = 0;
        exec($command, $lines, $code);
        $output = trim(implode("\n", $lines));
        if ($throw && $code !== 0) {
            throw new RuntimeException($output !== "" ? $output : "Command failed with exit code {$code}.");
        }
        return [$code, $output];
    }

    /**
     * @return string
     */
    private static function statePath(): string {
        return app_config_path("/data/panel-domain-job.json");
    }

    /**
     * @param array $state
     * @return void
     */
    private static function writeState(array $state): void {
        $state["updated_at"] = now_iso();
        JsonStorage::write(self::statePath(), $state);
        $file = self::statePath();
        $group = (string) (app_config("apache_group") ?: "");
        if ($group !== "") {
            @chgrp(dirname($file), $group);
            @chgrp($file, $group);
        }
        @chmod(dirname($file), 0770);
        @chmod($file, 0660);
    }

    /**
     * @param array $state
     * @return array
     */
    private static function publicState(array $state): array {
        return [
            "id" => (string) ($state["id"] ?? ""),
            "status" => (string) ($state["status"] ?? ""),
            "step" => (string) ($state["step"] ?? ""),
            "domain" => (string) ($state["domain"] ?? ""),
            "public_ip" => (string) ($state["public_ip"] ?? ""),
            "requested_url" => (string) ($state["requested_url"] ?? ""),
            "result_url" => (string) ($state["result_url"] ?? ""),
            "message" => (string) ($state["message"] ?? ""),
            "error" => (string) ($state["error"] ?? ""),
            "dns" => is_array($state["dns"] ?? null) ? $state["dns"] : [],
            "completed_steps" => is_array($state["completed_steps"] ?? null) ? array_values($state["completed_steps"]) : [],
            "step_messages" => is_array($state["step_messages"] ?? null) ? $state["step_messages"] : [],
            "failed_step" => (string) ($state["failed_step"] ?? ""),
            "created_at" => (string) ($state["created_at"] ?? ""),
            "updated_at" => (string) ($state["updated_at"] ?? ""),
        ];
    }

    /**
     * Small localized message set kept here so the new async endpoint does not
     * introduce untranslated strings in the existing en/es/pt_br language files.
     *
     * @param string $key
     * @param array<string, string> $params
     * @return string
     */
    public static function message(string $key, array $params = []): string {
        $strings = [
            "en" => [
                "queued" => "Domain change queued. The root cron will validate DNS and configure SSL.",
                "public_ip_detected" => "Public server IP detected: {ip}.",
                "configuring_webserver_done" => "Apache and NGINX configured while preserving direct IP access.",
                "testing_webserver_done" => "Apache and NGINX configuration tests passed.",
                "issuing_certificate_done" => "Let's Encrypt SSL certificate issued successfully.",
                "activating_domain_done" => "HTTPS activated for the new panel domain.",
                "checklist_title" => "Domain and SSL verification",
                "check_request_received" => "Change request received",
                "check_public_ip" => "Public server IP detected",
                "check_dns" => "DNS points to this server",
                "check_webserver_config" => "Apache and NGINX configured",
                "check_webserver_test" => "Web server configuration validated",
                "check_certificate" => "SSL certificate issued",
                "check_activation" => "HTTPS activated",
                "job_not_found" => "Domain change request not found.",
                "domain_required" => "Enter a domain name to enable automatic HTTPS.",
                "custom_port_not_supported" => "Custom ports are not supported for automatic HTTPS.",
                "public_ip_unavailable" => "The server public IPv4 address could not be detected.",
                "dns_missing" => "Waiting for DNS. Create an A record for {domain} pointing to {ip}.",
                "dns_mismatch" => "Waiting for DNS. {domain} resolves to {received}, but this server uses {expected}.",
                "dns_aaaa_mismatch" => "The AAAA record points to {received}, which is not an IPv6 address of this server. Fix or remove the AAAA record before SSL is issued.",
                "dns_ready" => "DNS points to this server.",
                "configuring_webserver" => "DNS confirmed. Configuring Apache and NGINX while keeping IP access available.",
                "testing_webserver" => "Testing Apache and NGINX configuration.",
                "issuing_certificate" => "Web server configuration is valid. Issuing the Let's Encrypt certificate.",
                "activating_domain" => "Certificate issued. Activating HTTPS for the domain.",
                "done" => "Domain and HTTPS are ready. Direct HTTP access by server IP remains available.",
                "not_ready" => "The new domain has not finished DNS and SSL processing yet.",
                "automatic_intro" => "When you use a domain, the panel queues the change for the root cron. It checks that DNS points to this server, configures Apache/NGINX, issues the Let's Encrypt certificate and only then saves the new address.",
                "ip_access_note" => "The server IP always remains available over HTTP as an emergency panel access path.",
            ],
            "es" => [
                "queued" => "El cambio de dominio fue encolado. El cron root validará el DNS y configurará el SSL.",
                "public_ip_detected" => "IP pública del servidor detectada: {ip}.",
                "configuring_webserver_done" => "Apache y NGINX configurados manteniendo el acceso directo por IP.",
                "testing_webserver_done" => "Las pruebas de configuración de Apache y NGINX finalizaron correctamente.",
                "issuing_certificate_done" => "Certificado SSL Let's Encrypt emitido correctamente.",
                "activating_domain_done" => "HTTPS activado para el nuevo dominio del panel.",
                "checklist_title" => "Verificación de dominio y SSL",
                "check_request_received" => "Solicitud de cambio recibida",
                "check_public_ip" => "IP pública del servidor detectada",
                "check_dns" => "DNS apunta a este servidor",
                "check_webserver_config" => "Apache y NGINX configurados",
                "check_webserver_test" => "Configuración de los servidores validada",
                "check_certificate" => "Certificado SSL emitido",
                "check_activation" => "HTTPS activado",
                "job_not_found" => "No se encontró la solicitud de cambio de dominio.",
                "domain_required" => "Informe un dominio para activar HTTPS automáticamente.",
                "custom_port_not_supported" => "Los puertos personalizados no son compatibles con el HTTPS automático.",
                "public_ip_unavailable" => "No fue posible detectar la IPv4 pública del servidor.",
                "dns_missing" => "Esperando DNS. Cree un registro A para {domain} apuntando a {ip}.",
                "dns_mismatch" => "Esperando DNS. {domain} resuelve a {received}, pero este servidor usa {expected}.",
                "dns_aaaa_mismatch" => "El registro AAAA apunta a {received}, que no es una IPv6 de este servidor. Corrija o elimine el AAAA antes de emitir el SSL.",
                "dns_ready" => "El DNS apunta a este servidor.",
                "configuring_webserver" => "DNS confirmado. Configurando Apache y NGINX y manteniendo disponible el acceso por IP.",
                "testing_webserver" => "Probando la configuración de Apache y NGINX.",
                "issuing_certificate" => "La configuración web es válida. Emitiendo el certificado Let's Encrypt.",
                "activating_domain" => "Certificado emitido. Activando HTTPS para el dominio.",
                "done" => "El dominio y HTTPS están listos. El acceso HTTP directo por IP continúa disponible.",
                "not_ready" => "El nuevo dominio todavía no terminó el procesamiento de DNS y SSL.",
                "automatic_intro" => "Al usar un dominio, el panel encola el cambio para el cron root. Valida que el DNS apunte a este servidor, configura Apache/NGINX, emite el certificado Let's Encrypt y solo después guarda la nueva dirección.",
                "ip_access_note" => "La IP del servidor siempre permanece disponible por HTTP como acceso de emergencia al panel.",
            ],
            "pt_br" => [
                "queued" => "Troca de domínio enfileirada. O cron root vai validar o DNS e configurar o SSL.",
                "public_ip_detected" => "IP público do servidor detectado: {ip}.",
                "configuring_webserver_done" => "Apache e NGINX configurados mantendo o acesso direto pelo IP.",
                "testing_webserver_done" => "Os testes das configurações do Apache e do NGINX foram concluídos com sucesso.",
                "issuing_certificate_done" => "Certificado SSL Let's Encrypt emitido com sucesso.",
                "activating_domain_done" => "HTTPS ativado para o novo domínio do painel.",
                "checklist_title" => "Verificação do domínio e SSL",
                "check_request_received" => "Solicitação de troca recebida",
                "check_public_ip" => "IP público do servidor detectado",
                "check_dns" => "DNS aponta para este servidor",
                "check_webserver_config" => "Apache e NGINX configurados",
                "check_webserver_test" => "Configurações dos servidores validadas",
                "check_certificate" => "Certificado SSL emitido",
                "check_activation" => "HTTPS ativado",
                "job_not_found" => "Solicitação de troca de domínio não encontrada.",
                "domain_required" => "Informe um domínio para ativar o HTTPS automaticamente.",
                "custom_port_not_supported" => "Portas personalizadas não são suportadas no HTTPS automático.",
                "public_ip_unavailable" => "Não foi possível detectar o IPv4 público deste servidor.",
                "dns_missing" => "Aguardando DNS. Crie um registro A para {domain} apontando para {ip}.",
                "dns_mismatch" => "Aguardando DNS. {domain} resolve para {received}, mas este servidor usa {expected}.",
                "dns_aaaa_mismatch" => "O registro AAAA aponta para {received}, que não é um IPv6 deste servidor. Corrija ou remova o AAAA antes da emissão do SSL.",
                "dns_ready" => "O DNS já aponta para este servidor.",
                "configuring_webserver" => "DNS confirmado. Configurando Apache e NGINX sem remover o acesso pelo IP.",
                "testing_webserver" => "Testando as configurações do Apache e do NGINX.",
                "issuing_certificate" => "Configuração dos servidores válida. Emitindo o certificado Let's Encrypt.",
                "activating_domain" => "Certificado emitido. Ativando o HTTPS do domínio.",
                "done" => "Domínio e HTTPS prontos. O acesso HTTP direto pelo IP do servidor continua disponível.",
                "not_ready" => "O novo domínio ainda não concluiu o processamento de DNS e SSL.",
                "automatic_intro" => "Ao usar um domínio, o painel envia a alteração para o cron root. Ele confirma que o DNS aponta para este servidor, configura Apache e NGINX, emite o certificado Let's Encrypt e só então libera o novo endereço para ser salvo.",
                "ip_access_note" => "O IP do servidor continua sempre disponível por HTTP como caminho de emergência para acessar o painel.",
            ],
        ];

        $language = I18n::current();
        if (!isset($strings[$language])) {
            $language = "en";
        }
        $message = $strings[$language][$key] ?? $strings["en"][$key] ?? $key;
        foreach ($params as $name => $value) {
            $message = str_replace("{{$name}}", $value, $message);
        }
        return $message;
    }
}

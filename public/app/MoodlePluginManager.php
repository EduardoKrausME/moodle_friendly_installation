<?php

namespace app;

use RuntimeException;
use Throwable;

class MoodlePluginManager {
    private const WEEK_SECONDS = 604800;

    /**
     * Returns Git-cloned Moodle plugins installed in this site.
     *
     * @param array $site
     * @param bool $checkremote
     * @return array
     */
    public static function installed(array $site, bool $checkremote = true): array {
        $webroot = self::webroot($site);
        $plugins = [];

        foreach (self::gitPluginPaths($webroot) as $path) {
            $version = self::readVersionFile($path . "/version.php");
            if (empty($version["component"])) {
                continue;
            }

            try {
                $expected = self::componentTarget($webroot, $version["component"]);
            } catch (Throwable) {
                continue;
            }

            if (self::normalizePath($expected) !== self::normalizePath($path)) {
                continue;
            }

            $plugin = [
                "component" => $version["component"],
                "version" => $version["version"] ?? "",
                "release" => $version["release"] ?? "",
                "path" => $path,
                "relative_path" => ltrim(substr($path, strlen(rtrim($webroot, "/"))), "/"),
                "remote" => self::gitValue($path, "config --get remote.origin.url"),
                "branch" => self::gitValue($path, "rev-parse --abbrev-ref HEAD"),
                "local_commit" => self::gitValue($path, "rev-parse HEAD"),
                "remote_commit" => "",
                "remote_version" => "",
                "remote_release" => "",
                "update_available" => false,
                "check_error" => "",
            ];

            if ($checkremote) {
                $plugin = array_merge($plugin, self::remoteStatus($plugin));
            }

            $plugins[] = $plugin;
        }

        usort($plugins, static fn(array $a, array $b): int => strcmp($a["component"], $b["component"]));

        if ($checkremote && !empty($site["domain"])) {
            self::saveSiteState((string) $site["domain"], $plugins);
        }

        return $plugins;
    }

    /**
     * Installs one public GitHub Moodle plugin after validating its remote version.php.
     *
     * @param array $site
     * @param string $giturl
     * @return array
     */
    public static function install(array $site, string $giturl): array {
        $webroot = self::webroot($site);
        $remote = self::inspectRemoteVersion($giturl);
        $component = $remote["component"];
        $target = self::componentTarget($webroot, $component);

        if (file_exists($target)) {
            throw new RuntimeException("O destino do plugin já existe: {$target}");
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException("Não foi possível criar o diretório do tipo de plugin: {$parent}");
        }
        if (!is_writable($parent)) {
            throw new RuntimeException("O Apache não possui permissão de escrita em: {$parent}");
        }

        $previousumask = umask(0002);
        try {
            $command = "timeout 180s git clone --depth 1 --branch " . escapeshellarg($remote["branch"]) . " " .
                escapeshellarg($remote["clone_url"]) . " " . escapeshellarg($target) . " 2>&1";
            [$exitcode, $output] = self::run($command);
            if ($exitcode !== 0) {
                self::removeDirectory($target);
                throw new RuntimeException("Falha no git clone: " . trim($output));
            }
        } finally {
            umask($previousumask);
        }

        $installed = self::readVersionFile($target . "/version.php");
        if (($installed["component"] ?? "") !== $component) {
            self::removeDirectory($target);
            throw new RuntimeException("O component do version.php clonado não confere com o arquivo validado antes da instalação.");
        }

        self::grantWebServerWriteAccess($target);

        return [
            "component" => $component,
            "path" => $target,
            "branch" => $remote["branch"],
            "admin_url" => self::siteAdminUrl($site),
        ];
    }

    /**
     * Updates one installed Git plugin by making the local checkout match the remote branch.
     *
     * A normal git pull --ff-only fails when the remote branch was force-pushed and the
     * histories diverged. Fetching with --force and resetting to FETCH_HEAD supports that
     * case and is appropriate here because these directories are managed Git clones.
     *
     * @param array $site
     * @param string $component
     * @return array
     */
    public static function update(array $site, string $component): array {
        $plugin = self::findInstalledPlugin($site, $component, true);
        $path = $plugin["path"];
        $remote = trim((string) ($plugin["remote"] ?? ""));
        $branch = trim((string) ($plugin["branch"] ?? ""));

        if (!empty($plugin["check_error"])) {
            throw new RuntimeException("Não foi possível validar o version.php remoto: " . $plugin["check_error"]);
        }
        if (empty($plugin["update_available"])) {
            throw new RuntimeException("O \$plugin->version local é igual ao version.php remoto. Não há atualização para aplicar.");
        }
        if ($remote === "" || $branch === "" || $branch === "HEAD") {
            throw new RuntimeException("Não foi possível identificar o remote e o branch do plugin.");
        }

        if (!is_writable($path)) {
            throw new RuntimeException("O Apache não possui permissão de escrita no plugin: {$path}");
        }

        $fetchcommand = "GIT_TERMINAL_PROMPT=0 timeout 180s git -c safe.directory=" . escapeshellarg($path) .
            " -C " . escapeshellarg($path) . " fetch --force --prune --no-tags " .
            escapeshellarg($remote) . " " . escapeshellarg($branch) . " 2>&1";
        [$fetchexitcode, $fetchoutput] = self::run($fetchcommand);
        if ($fetchexitcode !== 0) {
            throw new RuntimeException("Falha no git fetch: " . trim($fetchoutput));
        }

        $resetcommand = "GIT_TERMINAL_PROMPT=0 timeout 60s git -c safe.directory=" . escapeshellarg($path) .
            " -C " . escapeshellarg($path) . " reset --hard FETCH_HEAD 2>&1";
        [$resetexitcode, $resetoutput] = self::run($resetcommand);
        if ($resetexitcode !== 0) {
            throw new RuntimeException("Falha ao aplicar a versão remota: " . trim($resetoutput));
        }

        self::grantWebServerWriteAccess($path);
        $updated = self::findInstalledPlugin($site, $component, true);

        return [
            "component" => $component,
            "output" => trim($fetchoutput . "\n" . $resetoutput),
            "plugin" => $updated,
            "admin_url" => self::siteAdminUrl($site),
        ];
    }

    /**
     * Performs the weekly remote check for every discovered Moodle site.
     *
     * @param bool $force
     * @return array
     */
    public static function checkAllSitesForUpdates(bool $force = false): array {
        $state = self::readState();
        $last = strtotime((string) ($state["last_weekly_check_at"] ?? ""));
        if (!$force && $last && (time() - $last) < self::WEEK_SECONDS) {
            return $state;
        }

        // Save first so a failed remote host does not make the minute runner retry continuously.
        $state["last_weekly_check_at"] = now_iso();
        $state["last_weekly_status"] = "running";
        $state["last_weekly_message"] = "Checking plugin Git repositories.";
        self::writeState($state);

        $totalupdates = 0;
        $totalsites = 0;
        $errors = [];

        foreach (SiteManager::all() as $site) {
            if (empty($site["domain"]) || empty($site["webroot"])) {
                continue;
            }
            $totalsites++;
            try {
                $plugins = self::installed($site, true);
                foreach ($plugins as $plugin) {
                    if (!empty($plugin["update_available"])) {
                        $totalupdates++;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = ($site["domain"] ?? "site") . ": " . $e->getMessage();
            }
        }

        $state = self::readState();
        $state["last_weekly_finished_at"] = now_iso();
        $state["last_weekly_status"] = empty($errors) ? "checked" : "checked_with_errors";
        $state["last_weekly_message"] = "Checked {$totalsites} site(s); {$totalupdates} plugin update(s) available.";
        $state["last_weekly_updates"] = $totalupdates;
        $state["last_weekly_errors"] = $errors;
        self::writeState($state);

        return $state;
    }

    /**
     * Returns cached update information for one site.
     *
     * @param string $domain
     * @return array
     */
    public static function cachedSiteState(string $domain): array {
        $state = self::readState();
        return is_array($state["sites"][$domain] ?? null) ? $state["sites"][$domain] : [];
    }

    /**
     * Reads version.php from GitHub before cloning and determines the Moodle plugin type.
     *
     * @param string $giturl
     * @return array
     */
    public static function inspectRemoteVersion(string $giturl): array {
        $repository = self::parseGithubRepository($giturl);
        $cloneurl = "https://github.com/{$repository["owner"]}/{$repository["repo"]}.git";

        $command = "GIT_TERMINAL_PROMPT=0 timeout 25s git ls-remote --symref " . escapeshellarg($cloneurl) . " HEAD 2>&1";
        [$exitcode, $output] = self::run($command);
        if ($exitcode !== 0) {
            throw new RuntimeException("Não foi possível consultar o repositório Git: " . trim($output));
        }

        if (!preg_match('/ref:\s+refs\/heads\/([^\s]+)\s+HEAD/', $output, $matches)) {
            throw new RuntimeException("Não foi possível identificar o branch padrão do repositório.");
        }
        $branch = trim($matches[1]);
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
            throw new RuntimeException("Branch padrão inválido no repositório.");
        }

        $encodedbranch = implode("/", array_map("rawurlencode", explode("/", $branch)));
        $rawurl = "https://raw.githubusercontent.com/{$repository["owner"]}/{$repository["repo"]}/" .
            $encodedbranch . "/version.php";
        $command = "curl -fsSL --connect-timeout 8 --max-time 20 " . escapeshellarg($rawurl) . " 2>&1";
        [$exitcode, $versionphp] = self::run($command);
        if ($exitcode !== 0 || trim($versionphp) === "") {
            throw new RuntimeException("Não foi possível acessar o version.php na raiz do repositório.");
        }

        $version = self::parseVersionContents($versionphp);
        if (empty($version["component"])) {
            throw new RuntimeException("O version.php não possui um \$plugin->component válido.");
        }

        // This also validates that the component type is supported and returns a safe destination.
        self::componentRelativePath($version["component"]);

        return array_merge($version, [
            "branch" => $branch,
            "clone_url" => $cloneurl,
            "repository" => $repository["owner"] . "/" . $repository["repo"],
        ]);
    }

    /**
     * @param array $site
     * @param string $component
     * @param bool $checkremote
     * @return array
     */
    private static function findInstalledPlugin(array $site, string $component, bool $checkremote = false): array {
        if (!preg_match('/^[a-z][a-z0-9_]*_[a-z][a-z0-9_]*$/', $component)) {
            throw new RuntimeException("Componente de plugin inválido.");
        }

        foreach (self::installed($site, $checkremote) as $plugin) {
            if ($plugin["component"] === $component) {
                return $plugin;
            }
        }
        throw new RuntimeException("Plugin Git não encontrado: {$component}");
    }

    /**
     * @param array $plugin
     * @return array
     */
    private static function remoteStatus(array $plugin): array {
        $remote = trim((string) ($plugin["remote"] ?? ""));
        $branch = trim((string) ($plugin["branch"] ?? ""));
        $path = trim((string) ($plugin["path"] ?? ""));
        $localversion = trim((string) ($plugin["version"] ?? ""));

        $empty = [
            "remote_commit" => "",
            "remote_version" => "",
            "remote_release" => "",
            "update_available" => false,
            "check_error" => "",
        ];

        if ($remote === "" || $branch === "" || $branch === "HEAD" || $path === "") {
            $empty["check_error"] = "Remote, branch ou diretório local não identificado.";
            return $empty;
        }
        if ($localversion === "") {
            $empty["check_error"] = "O version.php local não possui um \$plugin->version numérico.";
            return $empty;
        }

        // Fetch only the remote branch into FETCH_HEAD. This updates Git metadata,
        // but never changes the plugin working tree while checking for updates.
        $command = "GIT_TERMINAL_PROMPT=0 timeout 45s git -c safe.directory=" . escapeshellarg($path) .
            " -C " . escapeshellarg($path) . " fetch --quiet --depth=1 --no-tags " .
            escapeshellarg($remote) . " " . escapeshellarg($branch) . " 2>&1";
        [$exitcode, $output] = self::run($command);
        if ($exitcode !== 0) {
            $empty["check_error"] = trim($output) ?: "Falha ao consultar o Git remoto.";
            return $empty;
        }

        $remotecommit = self::gitValue($path, "rev-parse FETCH_HEAD");
        $showcommand = "GIT_TERMINAL_PROMPT=0 timeout 15s git -c safe.directory=" . escapeshellarg($path) .
            " -C " . escapeshellarg($path) . " show FETCH_HEAD:version.php 2>&1";
        [$showexitcode, $remoteversionphp] = self::run($showcommand);
        if ($showexitcode !== 0 || trim($remoteversionphp) === "") {
            $empty["remote_commit"] = $remotecommit;
            $empty["check_error"] = "O version.php não foi encontrado na raiz do branch remoto.";
            return $empty;
        }

        $remoteversiondata = self::parseVersionContents($remoteversionphp);
        $remotecomponent = trim((string) ($remoteversiondata["component"] ?? ""));
        $remoteversion = trim((string) ($remoteversiondata["version"] ?? ""));
        if ($remotecomponent !== (string) ($plugin["component"] ?? "")) {
            $empty["remote_commit"] = $remotecommit;
            $empty["remote_version"] = $remoteversion;
            $empty["remote_release"] = trim((string) ($remoteversiondata["release"] ?? ""));
            $empty["check_error"] = "O \$plugin->component remoto é diferente do plugin instalado.";
            return $empty;
        }
        if ($remoteversion === "") {
            $empty["remote_commit"] = $remotecommit;
            $empty["check_error"] = "O version.php remoto não possui um \$plugin->version numérico.";
            return $empty;
        }

        return [
            "remote_commit" => $remotecommit,
            "remote_version" => $remoteversion,
            "remote_release" => trim((string) ($remoteversiondata["release"] ?? "")),
            "update_available" => $localversion !== $remoteversion,
            "check_error" => "",
        ];
    }

    /**
     * @param string $domain
     * @param array $plugins
     * @return void
     */
    private static function saveSiteState(string $domain, array $plugins): void {
        $state = self::readState();
        $items = [];
        $updates = 0;

        foreach ($plugins as $plugin) {
            $items[] = [
                "component" => $plugin["component"] ?? "",
                "branch" => $plugin["branch"] ?? "",
                "local_version" => $plugin["version"] ?? "",
                "remote_version" => $plugin["remote_version"] ?? "",
                "local_release" => $plugin["release"] ?? "",
                "remote_release" => $plugin["remote_release"] ?? "",
                "local_commit" => $plugin["local_commit"] ?? "",
                "remote_commit" => $plugin["remote_commit"] ?? "",
                "update_available" => !empty($plugin["update_available"]),
                "check_error" => $plugin["check_error"] ?? "",
            ];
            if (!empty($plugin["update_available"])) {
                $updates++;
            }
        }

        $state["sites"][$domain] = [
            "checked_at" => now_iso(),
            "updates" => $updates,
            "plugins" => $items,
        ];
        self::writeState($state);
    }

    /**
     * @param string $webroot
     * @return array
     */
    private static function gitPluginPaths(string $webroot): array {
        $command = "find " . escapeshellarg($webroot) . " -mindepth 2 -maxdepth 8 -type d -name .git -print 2>/dev/null";
        [$exitcode, $output] = self::run($command);
        if ($exitcode !== 0 && trim($output) === "") {
            return [];
        }

        $paths = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $gitdir) {
            if ($gitdir === "") {
                continue;
            }
            $path = dirname($gitdir);
            if (is_file($path . "/version.php")) {
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    /**
     * @param string $path
     * @return array
     */
    private static function readVersionFile(string $path): array {
        if (!is_readable($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        return is_string($contents) ? self::parseVersionContents($contents) : [];
    }

    /**
     * @param string $contents
     * @return array
     */
    private static function parseVersionContents(string $contents): array {
        $result = ["component" => "", "version" => "", "release" => ""];

        if (preg_match('/\$plugin->component\s*=\s*[\'\"]([a-z][a-z0-9_]*_[a-z][a-z0-9_]*)[\'\"]\s*;/', $contents, $matches)) {
            $result["component"] = $matches[1];
        }
        if (preg_match('/\$plugin->version\s*=\s*([0-9]+)/', $contents, $matches)) {
            $result["version"] = $matches[1];
        }
        if (preg_match('/\$plugin->release\s*=\s*[\'\"]([^\'\"]+)[\'\"]\s*;/', $contents, $matches)) {
            $result["release"] = trim($matches[1]);
        }

        return $result;
    }

    /**
     * @param string $component
     * @return string
     */
    private static function componentRelativePath(string $component): string {
        if (!preg_match('/^[a-z][a-z0-9_]*_[a-z][a-z0-9_]*$/', $component)) {
            throw new RuntimeException("Componente Moodle inválido: {$component}");
        }
        [$type, $name] = explode("_", $component, 2);

        $bases = [
            "mod" => "mod",
            "local" => "local",
            "theme" => "theme",
            "block" => "blocks",
            "auth" => "auth",
            "enrol" => "enrol",
            "filter" => "filter",
            "repository" => "repository",
            "portfolio" => "portfolio",
            "plagiarism" => "plagiarism",
            "report" => "report",
            "webservice" => "webservice",
            "search" => "search/engine",
            "media" => "media/player",
            "message" => "message/output",
            "cachestore" => "cache/stores",
            "fileconverter" => "files/converter",
            "contenttype" => "contentbank/contenttype",
            "customfield" => "customfield/field",
            "dataformat" => "dataformat",
            "calendartype" => "calendar/type",
            "editor" => "lib/editor",
            "tiny" => "lib/editor/tiny/plugins",
            "atto" => "lib/editor/atto/plugins",
            "availability" => "availability/condition",
            "format" => "course/format",
            "gradeexport" => "grade/export",
            "gradeimport" => "grade/import",
            "gradereport" => "grade/report",
            "profilefield" => "user/profile/field",
            "mlbackend" => "lib/mlbackend",
            "antivirus" => "lib/antivirus",
            "logstore" => "admin/tool/log/store",
            "qtype" => "question/type",
            "qbehaviour" => "question/behaviour",
            "qformat" => "question/format",
            "qbank" => "question/bank",
            "tool" => "admin/tool",
            "factor" => "admin/tool/mfa/factor",
            "assignsubmission" => "mod/assign/submission",
            "assignfeedback" => "mod/assign/feedback",
            "quizaccess" => "mod/quiz/accessrule",
            "scormreport" => "mod/scorm/report",
            "workshopform" => "mod/workshop/form",
            "workshopallocation" => "mod/workshop/allocation",
            "workshopeval" => "mod/workshop/eval",
            "booktool" => "mod/book/tool",
            "datafield" => "mod/data/field",
            "datapreset" => "mod/data/preset",
            "ltisource" => "mod/lti/source",
            "paygw" => "payment/gateway",
            "aiprovider" => "ai/provider",
            "smsgateway" => "sms/gateway",
        ];

        if (!isset($bases[$type])) {
            throw new RuntimeException("Tipo de plugin Moodle ainda não suportado para instalação automática: {$type}");
        }

        return $bases[$type] . "/" . $name;
    }

    /**
     * @param string $webroot
     * @param string $component
     * @return string
     */
    private static function componentTarget(string $webroot, string $component): string {
        return rtrim($webroot, "/") . "/" . self::componentRelativePath($component);
    }

    /**
     * @param string $giturl
     * @return array
     */
    private static function parseGithubRepository(string $giturl): array {
        $giturl = trim($giturl);
        $patterns = [
            '~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~',
            '~^git@github\.com:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$~',
            '~^ssh://git@github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $giturl, $matches)) {
                return ["owner" => $matches[1], "repo" => $matches[2]];
            }
        }
        throw new RuntimeException("Informe a URL de um repositório público do GitHub.");
    }

    /**
     * @param string $path
     * @param string $arguments
     * @return string
     */
    private static function gitValue(string $path, string $arguments): string {
        $command = "GIT_TERMINAL_PROMPT=0 timeout 15s git -c safe.directory=" . escapeshellarg($path) .
            " -C " . escapeshellarg($path) . " {$arguments} 2>/dev/null";
        [$exitcode, $output] = self::run($command);
        return $exitcode === 0 ? trim($output) : "";
    }

    /**
     * @param string $target
     * @return void
     */
    private static function grantWebServerWriteAccess(string $target): void {
        if (!is_dir($target)) {
            return;
        }
        @chmod(dirname($target), 0775);
        self::run("chmod -R u+rwX,g+rwX " . escapeshellarg($target) . " 2>/dev/null");
    }

    /**
     * @param string $path
     * @return void
     */
    private static function removeDirectory(string $path): void {
        if ($path === "" || !is_dir($path)) {
            return;
        }
        self::run("rm -rf -- " . escapeshellarg($path));
    }

    /**
     * @param array $site
     * @return string
     */
    private static function webroot(array $site): string {
        $webroot = (string) ($site["webroot"] ?? "");
        if ($webroot === "" || !is_dir($webroot)) {
            throw new RuntimeException("Diretório do Moodle não encontrado.");
        }
        return rtrim(realpath($webroot) ?: $webroot, "/");
    }

    /**
     * Returns a signed Moodle SSO URL that opens Site administration.
     *
     * @param array $site
     * @return string
     */
    public static function siteAdminUrl(array $site): string {
        $ssourl = trim((string) ($site["sso_url"] ?? ""));
        if ($ssourl !== "") {
            $separator = str_contains($ssourl, "?") ? "&" : "?";
            return $ssourl . $separator . "to=" . rawurlencode("/admin/index.php");
        }

        return rtrim((string) ($site["url"] ?? ""), "/") . "/admin/index.php";
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalizePath(string $path): string {
        return rtrim(str_replace("//", "/", $path), "/");
    }

    /**
     * @return string
     */
    private static function stateFile(): string {
        return app_config_path("/data/update/plugin-updates.json");
    }

    /**
     * @return array
     */
    private static function readState(): array {
        $state = JsonStorage::read(self::stateFile());
        return is_array($state) ? $state : [];
    }

    /**
     * @param array $state
     * @return void
     */
    private static function writeState(array $state): void {
        JsonStorage::write(self::stateFile(), $state);
        @chmod(dirname(self::stateFile()), 0777);
        @chmod(self::stateFile(), 0666);
    }

    /**
     * @param string $command
     * @return array{0:int,1:string}
     */
    private static function run(string $command): array {
        $output = [];
        $exitcode = 0;
        exec($command, $output, $exitcode);
        return [$exitcode, implode("\n", $output)];
    }
}

<?php
// Job manager. The web panel creates pending jobs; the root cron runner executes them.
namespace app;

/**
 * Class JobManager
 */
class JobManager {
    /**
     * Function all
     *
     * @return array
     */
    public static function all(): array {
        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"));
        usort(
            $jobs,
            static fn(array $a, array $b): int => strcmp(($b["created_at"] ?? ""), ($a["created_at"] ?? ""))
        );
        return $jobs;
    }

    /**
     * Function createInstallJob
     *
     * @param array $data
     * @return array
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function createInstallJob(array $data): array {
        $hasbackup = !empty($data["kopere_backup_zip"]);
        $type = $hasbackup ? "restore_moodle" : "install_moodle";
        $logprefix = $hasbackup ? "restore" : "install";
        $domain = $data["domain"];
        $base = "/home/{$domain}";
        if (file_exists($base) || is_link($base)) {
            throw new \RuntimeException(I18n::get("validation.domain_path_exists"));
        }

        $job = [
            "id" => self::newId(),
            "type" => $type,
            "status" => "pending",
            "domain" => $data["domain"],
            "installation_path_existed" => false,
            "site_fullname" => $data["site_fullname"],
            "admin_user" => $data["admin_user"],
            "admin_pass" => $data["admin_pass"],
            "admin_email" => $data["admin_email"],
            "moodle_branch" => $data["moodle_branch"],
            "language" => I18n::moodleLanguage(isset($data["language"]) && is_string($data["language"]) ? $data["language"] : I18n::current()),
            "issue_cert" => (bool) $data["issue_cert"],
            "kopere_backup_zip" => $data["kopere_backup_zip"] ?? null,
            "created_at" => now_iso(),
            "updated_at" => now_iso(),
            "created_by" => Auth::user()["username"] ?? "system",
            "log_file" => app_config_path("/data/logs") . "/{$logprefix}-{$data["domain"]}-" . date("Ymd-His") . ".log",
        ];

        if ($hasbackup) {
            $job["restore_mode"] = "schema_restore";
        }

        self::storeJob($job);
        return $job;
    }

    /**
     * Function createAppBuildJob
     *
     * @param array $data
     * @return array
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function createAppBuildJob(array $data): array {
        $job = [
            "id" => self::newId(),
            "type" => "app_build",
            "status" => "pending",
            "domain" => $data["domain"],
            "moodle_url" => $data["moodle_url"] ?? "",
            "package_uid" => $data["package_uid"],
            "package_name" => $data["package_name"],
            "statusbarbackgroundcolor" => $data["statusbarbackgroundcolor"],
            "icon_path" => $data["icon_path"],
            "app_version" => $data["app_version"],
            "created_at" => now_iso(),
            "updated_at" => now_iso(),
            "created_by" => Auth::user()["username"] ?? "system",
            "log_file" => app_config_path("/data/logs") . "/app-build-{$data["domain"]}-" . date("Ymd-His") . ".log",
        ];

        self::storeJob($job);
        return $job;
    }

    /**
     * Creates a low-priority resource collection job unless one is already active.
     *
     * @param array $site
     * @param string|null $createdby
     * @return array
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function createResourceUsageJob(array $site, ?string $createdby = null): array {
        $domain = $site["domain"];
        $activejob = self::activeJob("resource_usage", $domain);
        if ($activejob !== null) {
            return $activejob;
        }

        $job = [
            "id" => self::newId(),
            "type" => "resource_usage",
            "status" => "pending",
            "domain" => $domain,
            "created_at" => now_iso(),
            "updated_at" => now_iso(),
            "created_by" => $createdby ?: (Auth::user()["username"] ?? "system"),
            "log_file" => app_config_path("/data/logs") . "/resources-{$domain}-" . date("Ymd-His") . ".log",
        ];

        self::storeJob($job);
        return $job;
    }

    /**
     * Creates a queued ModSecurity or NGINX cache change.
     *
     * @param array $site
     * @param string $control
     * @param bool $enabled
     * @return array
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function createServerControlJob(array $site, string $control, bool $enabled): array {
        if (!in_array($control, ["cache"], true)) {
            throw new \InvalidArgumentException("Unsupported server control: {$control}");
        }

        $domain = $site["domain"];
        foreach (self::all() as $existing) {
            if (($existing["type"] ?? "") === "server_control"
                && ($existing["domain"] ?? "") === $domain
                && ($existing["control"] ?? "") === $control
                && in_array(($existing["status"] ?? ""), ["pending", "running"], true)
            ) {
                return $existing;
            }
        }

        $job = [
            "id" => self::newId(),
            "type" => "server_control",
            "status" => "pending",
            "domain" => $domain,
            "control" => $control,
            "enabled" => $enabled,
            "created_at" => now_iso(),
            "updated_at" => now_iso(),
            "created_by" => Auth::user()["username"] ?? "system",
            "log_file" => app_config_path("/data/logs") . "/{$control}-{$domain}-" . date("Ymd-His") . ".log",
        ];

        self::storeJob($job);
        return $job;
    }

    /**
     * Returns an active job for a type and domain.
     *
     * @param string $type
     * @param string $domain
     * @return array|null
     */
    public static function activeJob(string $type, string $domain): ?array {
        foreach (self::all() as $job) {
            if (($job["type"] ?? "") === $type
                && ($job["domain"] ?? "") === $domain
                && in_array(($job["status"] ?? ""), ["pending", "running"], true)
            ) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Returns the newest installation or restore job for a domain.
     *
     * @param string $domain
     * @return array|null
     */
    public static function latestInstallationJob(string $domain): ?array {
        foreach (self::all() as $job) {
            if (($job["domain"] ?? "") === $domain
                && in_array(($job["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
            ) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Makes a root-generated job log readable by the panel process.
     *
     * @param string $file
     * @return void
     */
    public static function fixLogPermissions(string $file): void {
        $dir = dirname($file);
        $group = (string) (app_config("apache_group") ?: "");
        if ($group !== "") {
            @chgrp($dir, $group);
            @chgrp($file, $group);
        }
        @chmod($dir, 0750);
        @chmod($file, 0640);
    }

    /**
     * Function updateJob
     *
     * @param string $id
     * @param callable $callback
     * @return array|null
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function updateJob(string $id, callable $callback): ?array {
        $updated = null;
        $jobsfile = app_config_path("/data/jobs.json");
        self::withJobsLock(
            static function() use ($jobsfile, $id, $callback, &$updated): void {
                JsonStorage::update(
                    $jobsfile,
                    static function(array $jobs) use ($id, $callback, &$updated): array {
                        foreach ($jobs as &$job) {
                            if (($job["id"] ?? "") == $id) {
                                $job = $callback($job);
                                $job["updated_at"] = now_iso();
                                $updated = $job;
                                break;
                            }
                        }
                        unset($job);
                        return $jobs;
                    }
                );
                self::fixSharedStoragePermissions($jobsfile);
            }
        );
        return $updated;
    }

    /**
     * Cancels a pending job without allowing a running or finished job to change state.
     *
     * @param string $id
     * @param string $cancelledby
     * @return array{cancelled: bool, job: array|null}
     */
    public static function cancelJob(string $id, string $cancelledby): array {
        $jobsfile = app_config_path("/data/jobs.json");
        $result = ["cancelled" => false, "job" => null];

        self::withJobsLock(static function() use ($jobsfile, $id, $cancelledby, &$result): void {
            $jobs = JsonStorage::read($jobsfile);
            foreach ($jobs as &$job) {
                if (($job["id"] ?? "") !== $id) {
                    continue;
                }

                if (in_array(($job["status"] ?? ""), ["pending", "waiting_dns"], true)) {
                    $job["status"] = "canceled";
                    $job["canceled_at"] = now_iso();
                    $job["canceled_by"] = $cancelledby;
                    $job["updated_at"] = now_iso();
                    $job["admin_pass"] = null;
                    unset($job["dns_waiting_message"]);
                    $result["cancelled"] = true;
                    JsonStorage::write($jobsfile, $jobs);
                    self::fixSharedStoragePermissions($jobsfile);
                }

                $result["job"] = $job;
                break;
            }
            unset($job);
        });

        if ($result["cancelled"] && is_array($result["job"])) {
            self::writeQueueFile($result["job"]);
        }
        return $result;
    }

    /**
     * Queues removal of files created by a failed installation or restore.
     *
     * @param string $sourcejobid
     * @param string $createdby
     * @return array
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function createFailedInstallationCleanupJob(string $sourcejobid, string $createdby): array {
        $jobsfile = app_config_path("/data/jobs.json");
        $cleanupjob = null;
        $created = false;

        self::withJobsLock(static function() use (
            $jobsfile,
            $sourcejobid,
            $createdby,
            &$cleanupjob,
            &$created
        ): void {
            $jobs = JsonStorage::read($jobsfile);
            $sourceindex = null;
            foreach ($jobs as $index => $job) {
                if (($job["id"] ?? "") === $sourcejobid) {
                    $sourceindex = $index;
                    break;
                }
            }

            if ($sourceindex === null) {
                throw new \RuntimeException(I18n::get("jobs.not_found"));
            }

            $sourcejob = $jobs[$sourceindex];
            if (($sourcejob["status"] ?? "") !== "failed"
                || !in_array(($sourcejob["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
                || !empty($sourcejob["files_deleted"])
                || !empty($sourcejob["installation_path_existed"])
            ) {
                throw new \RuntimeException(I18n::get("jobs.delete_files_not_allowed"));
            }

            $domain = $sourcejob["domain"];
            if (!self::isValidInstallationDomain($domain)) {
                throw new \RuntimeException(I18n::get("jobs.delete_files_invalid_domain"));
            }

            foreach ($jobs as $candidate) {
                if (($candidate["id"] ?? "") === $sourcejobid
                    || ($candidate["domain"] ?? "") !== $domain
                    || !in_array(($candidate["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
                ) {
                    continue;
                }
                if (strcmp($candidate["created_at"], $sourcejob["created_at"]) > 0) {
                    throw new \RuntimeException(I18n::get("jobs.delete_files_newer_installation"));
                }
            }

            foreach ($jobs as $existingjob) {
                if (($existingjob["type"] ?? "") === "cleanup_failed_installation"
                    && ($existingjob["source_job_id"] ?? "") === $sourcejobid
                    && in_array(($existingjob["status"] ?? ""), ["pending", "running"], true)
                ) {
                    $cleanupjob = $existingjob;
                    return;
                }
            }

            $cleanupjob = [
                "id" => self::newId(),
                "type" => "cleanup_failed_installation",
                "status" => "pending",
                "domain" => $domain,
                "source_job_id" => $sourcejobid,
                "base_dir" => "/home/{$domain}",
                "created_at" => now_iso(),
                "updated_at" => now_iso(),
                "created_by" => $createdby,
                "log_file" => app_config_path("/data/logs") . "/cleanup-{$domain}-" . date("Ymd-His") . ".log",
            ];
            $jobs[] = $cleanupjob;
            $jobs[$sourceindex]["cleanup_job_id"] = $cleanupjob["id"];
            $jobs[$sourceindex]["cleanup_status"] = "pending";
            $jobs[$sourceindex]["cleanup_requested_at"] = now_iso();
            $jobs[$sourceindex]["cleanup_requested_by"] = $createdby;
            $jobs[$sourceindex]["updated_at"] = now_iso();

            JsonStorage::write($jobsfile, $jobs);
            self::fixSharedStoragePermissions($jobsfile);
            $created = true;
        });

        if ($created && is_array($cleanupjob)) {
            self::writeQueueFile($cleanupjob);
        }
        if (!is_array($cleanupjob)) {
            throw new \RuntimeException(I18n::get("jobs.delete_files_not_allowed"));
        }
        return $cleanupjob;
    }

    /**
     * Updates cleanup information on the failed source job.
     *
     * @param string $sourcejobid
     * @param string $cleanupjobid
     * @param string $status
     * @param array $extra
     * @return array|null
     */
    public static function updateFailedInstallationCleanup(
        string $sourcejobid,
        string $cleanupjobid,
        string $status,
        array $extra = []
    ): ?array {
        return self::updateJob($sourcejobid, static function(array $job) use (
            $cleanupjobid,
            $status,
            $extra
        ): array {
            if (($job["status"] ?? "") !== "failed"
                || !in_array(($job["type"] ?? ""), ["install_moodle", "restore_moodle"], true)
            ) {
                return $job;
            }

            $job["cleanup_job_id"] = $cleanupjobid;
            $job["cleanup_status"] = $status;
            $job = array_merge($job, $extra);
            return $job;
        });
    }

    /**
     * Function nextPendingJob
     *
     * @return array|null
     */
    public static function nextPendingJob(): ?array {
        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"));
        $priorityjobs = [];
        $foregroundjobs = [];
        $backgroundjobs = [];

        foreach ($jobs as $job) {
            $status = $job["status"] ?? "";
            $type = $job["type"] ?? "";

            if ($type === "cleanup_failed_installation" && $status === "pending") {
                $priorityjobs[] = $job;
                continue;
            }

            if (in_array($type, ["install_moodle", "restore_moodle"], true) && in_array($status, ["pending", "waiting_dns"], true)) {
                $foregroundjobs[] = $job;
                continue;
            }

            if (in_array($type, ["app_build", "server_control"], true) && $status == "pending") {
                $foregroundjobs[] = $job;
                continue;
            }

            if ($type == "resource_usage" && $status == "pending") {
                $backgroundjobs[] = $job;
            }
        }

        $pendingjobs = !empty($priorityjobs)
            ? $priorityjobs
            : (!empty($foregroundjobs) ? $foregroundjobs : $backgroundjobs);
        if (empty($pendingjobs)) {
            return null;
        }

        usort($pendingjobs, static fn(array $a, array $b): int => strcmp(($a["created_at"] ?? ""), ($b["created_at"] ?? "")));
        return $pendingjobs[0];
    }

    /**
     * Validates a domain before it is used to build a cleanup path.
     *
     * @param string $domain
     * @return bool
     */
    private static function isValidInstallationDomain(string $domain): bool {
        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;
    }

    /**
     * Function markWaitingDns
     *
     * @param string $id
     * @param string $message
     * @return array|null
     * @throws \Random\RandomException|\DateMalformedStringException
     */
    public static function markWaitingDns(string $id, string $message): ?array {
        $transitioned = false;
        $job = self::updateJob($id, static function(array $job) use ($message, &$transitioned): array {
            if (!in_array(($job["status"] ?? ""), ["pending", "waiting_dns"], true)) {
                return $job;
            }
            $transitioned = true;
            $job["status"] = "waiting_dns";
            $job["dns_waiting_message"] = $message;
            $job["last_dns_check_at"] = now_iso();
            if (empty($job["dns_waiting_since"])) {
                $job["dns_waiting_since"] = now_iso();
            }
            return $job;
        });
        return $transitioned ? $job : null;
    }

    /**
     * Function markRunning
     *
     * @param string $id
     * @return array|null
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function markRunning(string $id): ?array {
        $transitioned = false;
        $job = self::updateJob($id, static function(array $job) use (&$transitioned): array {
            if (!in_array(($job["status"] ?? ""), ["pending", "waiting_dns"], true)) {
                return $job;
            }
            $transitioned = true;
            $job["status"] = "running";
            $job["dns_resolved_at"] = now_iso();
            unset($job["dns_waiting_message"]);
            $job["started_at"] = now_iso();
            return $job;
        });
        return $transitioned ? $job : null;
    }

    /**
     * Function markDone
     *
     * @param string $id
     * @param array $extra
     * @return array|null
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function markDone(string $id, array $extra = []): ?array {
        return self::updateJob($id, static function(array $job) use ($extra): array {
            $job = array_merge($job, $extra);
            $job["status"] = "done";
            $job["finished_at"] = now_iso();
            $job["admin_pass"] = null;
            return $job;
        });
    }

    /**
     * Function markFailed
     *
     * @param string $id
     * @param string $message
     * @return array|null
     * @throws \Random\RandomException
     * @throws \DateMalformedStringException
     */
    public static function markFailed(string $id, string $message): ?array {
        return self::updateJob($id, static function(array $job) use ($message): array {
            if (($job["status"] ?? "") === "canceled") {
                return $job;
            }
            $job["status"] = "failed";
            $job["finished_at"] = now_iso();
            $job["error"] = $message;
            $job["admin_pass"] = null;
            return $job;
        });
    }

    /**
     * Function writeQueueFile
     *
     * @param array $job
     * @return void
     * @throws \Random\RandomException
     */
    private static function writeQueueFile(array $job): void {
        $queuefile = rtrim(app_config_path("/data/queue"), "/") . "/{$job["id"]}.json";
        JsonStorage::write($queuefile, $job);
        self::fixSharedStoragePermissions($queuefile);
    }

    /**
     * Stores a job and its queue file.
     *
     * @param array $job
     * @return void
     * @throws \Random\RandomException
     */
    private static function storeJob(array $job): void {
        $jobsfile = app_config_path("/data/jobs.json");
        self::withJobsLock(static function() use ($jobsfile, $job): void {
            JsonStorage::update($jobsfile, static function(array $jobs) use ($job): array {
                $jobs[] = $job;
                return $jobs;
            });
            self::fixSharedStoragePermissions($jobsfile);
        });
        self::writeQueueFile($job);
    }

    /**
     * Serializes job state transitions between the web panel and root runner.
     *
     * @param callable $callback
     * @return mixed
     */
    private static function withJobsLock(callable $callback): mixed {
        $lockfile = app_config_path("/data/jobs.lock");
        if (!is_dir(dirname($lockfile))) {
            mkdir(dirname($lockfile), 0770, true);
        }
        $handle = fopen($lockfile, "c");
        if ($handle === false) {
            throw new \RuntimeException("Cannot open jobs lock.");
        }
        self::fixSharedStoragePermissions($lockfile);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException("Cannot lock jobs storage.");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Keeps queue storage writable by both the panel and the root runner.
     *
     * @param string $file
     * @return void
     */
    private static function fixSharedStoragePermissions(string $file): void {
        $dir = dirname($file);
        $group = (string) (app_config("apache_group") ?: "");
        if ($group !== "") {
            @chgrp($dir, $group);
            @chgrp($file, $group);
        }
        @chmod($dir, 0770);
        @chmod($file, 0660);
    }

    /**
     * Function newId
     *
     * @return string
     */
    private static function newId(): string {
        return "job_" . uniqid();
    }
}

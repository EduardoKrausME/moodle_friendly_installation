<?php
// Job manager. The web panel creates pending jobs; the root cron runner executes them.
namespace app;

class JobManager {
    public static function all(): array {
        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"), []);
        usort(
            $jobs,
            static fn(array $a, array $b): int => strcmp(($b['created_at'] ?? ''), ($a['created_at'] ?? ''))
        );
        return $jobs;
    }

    public static function createInstallJob(array $data): array {
        $job = [
            'id' => self::newId(),
            'type' => 'install_moodle',
            'status' => 'pending',
            'domain' => $data['domain'],
            'site_fullname' => $data['site_fullname'],
            'admin_user' => $data['admin_user'],
            'admin_pass' => $data['admin_pass'],
            'admin_email' => $data['admin_email'],
            'moodle_branch' => $data['moodle_branch'],
            'language' => I18n::moodleLanguage(isset($data['language']) && is_string($data['language']) ? $data['language'] : I18n::current()),
            'issue_cert' => (bool) $data['issue_cert'],
            'kopere_backup_zip' => $data['kopere_backup_zip'] ?? null,
            'created_at' => now_iso(),
            'updated_at' => now_iso(),
            'created_by' => Auth::user()['username'] ?? 'system',
            'log_file' => app_config_path("/logs") . '/install-' . $data['domain'] . '-' . date('Ymd-His') . '.log',
        ];

        JsonStorage::update(app_config_path("/data/jobs.json"), static function(array $jobs) use ($job): array {
            $jobs[] = $job;
            return $jobs;
        });

        self::writeQueueFile($job);
        return $job;
    }


    public static function createAppBuildJob(array $data): array {
        $job = [
            'id' => self::newId(),
            'type' => 'app_build',
            'status' => 'pending',
            'domain' => $data['domain'],
            'moodle_url' => $data['moodle_url'] ?? '',
            'package_uid' => $data['package_uid'],
            'package_name' => $data['package_name'],
            'statusbarbackgroundcolor' => $data['statusbarbackgroundcolor'],
            'icon_path' => $data['icon_path'],
            'app_version' => $data['app_version'],
            'created_at' => now_iso(),
            'updated_at' => now_iso(),
            'created_by' => Auth::user()['username'] ?? 'system',
            'log_file' => app_config_path("/logs") . '/app-build-' . $data['domain'] . '-' . date('Ymd-His') . '.log',
        ];

        JsonStorage::update(app_config_path("/data/jobs.json"), static function(array $jobs) use ($job): array {
            $jobs[] = $job;
            return $jobs;
        });

        self::writeQueueFile($job);
        return $job;
    }

    public static function updateJob(string $id, callable $callback): ?array {
        $updated = null;
        JsonStorage::update(
            app_config_path("/data/jobs.json"), static function(array $jobs) use ($id, $callback, &$updated): array {
            foreach ($jobs as &$job) {
                if (($job['id'] ?? '') == $id) {
                    $job = $callback($job);
                    $job['updated_at'] = now_iso();
                    $updated = $job;
                    break;
                }
            }
            unset($job);
            return $jobs;
        }
        );
        return $updated;
    }

    public static function nextPendingJob(): ?array {
        $jobs = JsonStorage::read(app_config_path("/data/jobs.json"), []);
        $pendingjobs = [];

        foreach ($jobs as $job) {
            $status = $job['status'] ?? '';
            $type = $job['type'] ?? '';

            if ($type == 'install_moodle' && in_array($status, ['pending', 'waiting_dns'], true)) {
                $pendingjobs[] = $job;
                continue;
            }

            if (in_array($type, ['app_build', 'restore_moodle'], true) && $status == 'pending') {
                $pendingjobs[] = $job;
            }
        }

        if (empty($pendingjobs)) {
            return null;
        }

        return $pendingjobs[array_rand($pendingjobs)];
    }

    public static function markWaitingDns(string $id, string $message): ?array {
        return self::updateJob($id, static function(array $job) use ($message): array {
            $job['status'] = 'waiting_dns';
            $job['dns_waiting_message'] = $message;
            $job['last_dns_check_at'] = now_iso();
            if (empty($job['dns_waiting_since'])) {
                $job['dns_waiting_since'] = now_iso();
            }
            return $job;
        });
    }

    public static function markRunning(string $id): ?array {
        return self::updateJob($id, static function(array $job): array {
            $job['status'] = 'running';
            $job['dns_resolved_at'] = now_iso();
            unset($job['dns_waiting_message']);
            $job['started_at'] = now_iso();
            return $job;
        });
    }

    public static function markDone(string $id, array $extra = []): ?array {
        return self::updateJob($id, static function(array $job) use ($extra): array {
            $job = array_merge($job, $extra);
            $job['status'] = 'done';
            $job['finished_at'] = now_iso();
            $job['admin_pass'] = null;
            return $job;
        });
    }

    public static function markFailed(string $id, string $message): ?array {
        return self::updateJob($id, static function(array $job) use ($message): array {
            $job['status'] = 'failed';
            $job['finished_at'] = now_iso();
            $job['error'] = $message;
            $job['admin_pass'] = null;
            return $job;
        });
    }

    private static function writeQueueFile(array $job): void {
        $queuefile = rtrim(app_config_path("/queue"), '/') . '/' . $job['id'] . '.json';
        JsonStorage::write($queuefile, $job);
    }

    private static function newId(): string {
        return 'job_' . uniqid();
    }
}

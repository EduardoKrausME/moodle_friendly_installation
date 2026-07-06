#!/usr/bin/env php
<?php
// CLI updater. Example cron: php /path/to/moodle_friendly_installation/bin/self-update.php

use app\AppUpdater;

require_once dirname(__DIR__) . "/public/app/bootstrap.php";

try {
    $result = AppUpdater::installLatest();
    echo ($result["message"] ?? "OK") . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Update failed: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}

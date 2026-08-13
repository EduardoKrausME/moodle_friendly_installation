<?php

use app\PostUpdateManager;

return static function(): void {
    // Version 2.4.0 introduces the post-update system. This migration repairs
    // directories created by the old updater before the new code could run.
    PostUpdateManager::repairPublicPermissions();
};

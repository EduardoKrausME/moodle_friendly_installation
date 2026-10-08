<?php

require_once __DIR__ . "/../../public/app/ApacheFallbackResourceUpdater.php";

use app\ApacheFallbackResourceUpdater;

return static function(): void {
    ApacheFallbackResourceUpdater::run();
};

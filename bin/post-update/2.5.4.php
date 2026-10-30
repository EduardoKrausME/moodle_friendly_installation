<?php

require_once __DIR__ . "/../../public/app/PhpFpmSocketResolver.php";
require_once __DIR__ . "/../../public/app/ApachePhpFpmSocketUpdater.php";

use app\ApachePhpFpmSocketUpdater;

return static function(): void {
    ApachePhpFpmSocketUpdater::run();
};

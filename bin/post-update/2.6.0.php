<?php

require_once __DIR__ . '/../../public/app/PhpFpmSocketResolver.php';
require_once __DIR__ . '/../../public/app/Moodle260Migration.php';

use app\Moodle260Migration;

return static function(): void {
    Moodle260Migration::run();
};

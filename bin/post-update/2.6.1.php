<?php

require_once __DIR__ . '/../../public/app/Moodle261Migration.php';

use app\Moodle261Migration;

return static function(): void {
    Moodle261Migration::run();
};

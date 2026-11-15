<?php

require_once __DIR__ . '/../../public/app/Moodle262Migration.php';

use app\Moodle262Migration;

return static function(): void {
    Moodle262Migration::run();
};

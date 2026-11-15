<?php
// Check the first-install default and persistence across subsequent requests.
declare(strict_types=1);

require_once __DIR__ . "/../public/app/JsonStorage.php";
require_once __DIR__ . "/../public/app/InstallDefaults.php";

use app\InstallDefaults;
use app\JsonStorage;

$root = sys_get_temp_dir() . "/moodle-install-email-" . bin2hex(random_bytes(6));

function app_config_path(string $path): string {
    global $root;
    return $root . $path;
}

function assertSameEmail(string $expected): void {
    $actual = InstallDefaults::adminEmail();
    if ($actual !== $expected) {
        throw new RuntimeException("Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

try {
    assertSameEmail("");
    InstallDefaults::saveAdminEmail("first@example.com");
    assertSameEmail("first@example.com");
    InstallDefaults::saveAdminEmail("second@example.com");
    assertSameEmail("second@example.com");

    try {
        InstallDefaults::saveAdminEmail("not-an-email");
        throw new RuntimeException("Invalid e-mail was accepted.");
    } catch (InvalidArgumentException $e) {
        assertSameEmail("second@example.com");
    }

    JsonStorage::write(app_config_path("/data/install-defaults.json"), ["admin_email" => "invalid"]);
    assertSameEmail("");

    $controller = file_get_contents(__DIR__ . "/../public/install.php");
    if ($controller === false
        || !str_contains($controller, '"admin_email" => InstallDefaults::adminEmail()')
        || !str_contains($controller, 'InstallDefaults::saveAdminEmail($validation["data"]["admin_email"])')
    ) {
        throw new RuntimeException("Installation form is not wired to persisted e-mail defaults.");
    }

    echo "Moodle installation e-mail default and persistence OK\n";
} finally {
    @unlink(app_config_path("/data/install-defaults.json"));
    @rmdir(app_config_path("/data"));
    @rmdir($root);
}

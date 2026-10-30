<?php

// Execute with: php tests/admin-sso-url.test.php
require_once __DIR__ . "/../public/app/SiteManager.php";

use app\SiteManager;

function checkSso(bool $valid, string $message): void {
    if (!$valid) {
        throw new RuntimeException($message);
    }
}

$file = tempnam(sys_get_temp_dir(), "moodle-sso-");
if ($file === false) {
    throw new RuntimeException("Cannot create test configuration.");
}

try {
    file_put_contents($file, <<<'PHP'
<?php
$CFG->wwwroot = 'https://moodle.example.org';
$CFG->dbname = 'moodle_test_database';
PHP
    );

    $site = ["domain" => "moodle.example.org", "config_file" => $file];
    $url = SiteManager::adminSsoUrl($site);
    $parts = parse_url($url);
    parse_str((string) ($parts["query"] ?? ""), $query);
    checkSso(($parts["host"] ?? "") === "moodle.example.org", "Unexpected host");
    checkSso(($parts["path"] ?? "") === "/moodle-logar-admin.php", "Unexpected SSO path");
    checkSso(($query["to"] ?? "") === "/admin/index.php", "Unexpected destination");
    checkSso(abs(time() - (int) ($query["time"] ?? 0)) <= 10, "Timestamp was not fresh");
    checkSso(hash_equals(
        hash_hmac("sha256", (string) ($query["time"] ?? ""), "moodle_test_database"),
        (string) ($query["signature"] ?? "")
    ), "Invalid SSO signature");

    try {
        SiteManager::adminSsoUrl($site, "//evil.example.org");
        throw new RuntimeException("External redirect accepted");
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }
    $other = $site;
    $other["domain"] = "attacker.example.org";
    try {
        SiteManager::adminSsoUrl($other);
        throw new RuntimeException("Domain mismatch accepted");
    } catch (RuntimeException $expected) {
        checkSso($expected->getMessage() !== "Domain mismatch accepted", "Domain mismatch accepted");
    }
    echo "Admin SSO URL tests passed.\n";
} finally {
    @unlink($file);
}

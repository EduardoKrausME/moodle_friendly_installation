<?php
// Smoke-test the app stylesheet without requiring a running Moodle site.
declare(strict_types=1);

require_once __DIR__ . "/../public/app/AppManager.php";

$branding = [
    "source" => "theme_learnlingo",
    "logo_url" => "https://moodle.example/pluginfile.php/1/core_admin/logo/0/brand.png",
    "brandcolor" => "#123456",
    "accentcolor" => "#ABCDEF",
    "canvascolor" => "#F8FAFC",
    "textcolor" => "#334455",
    "navbar_scss" => ':root { --learnlingo-navbar-bg: #{rgba($primary, .97)}; }',
];
$normalized = \app\AppManager::normalizeBranding($branding, "moodle.example");
if ($normalized === null || !$normalized["available"] ||
        $normalized["logo_url"] !== $branding["logo_url"] ||
        $normalized["canvascolor"] !== "#F8FAFC") {
    throw new RuntimeException("Public LearnLingo colours and logo were not validated.");
}
$invalid = $branding;
$invalid["logo_url"] = "https://127.0.0.1/private.png";
if (\app\AppManager::normalizeBranding($invalid, "moodle.example")["logo_url"] !== "") {
    throw new RuntimeException("External logo URL was accepted.");
}
$settings = [
    "theme_color_sync" => true,
    "statusbarbackgroundcolor" => "#000000",
    "app_scss" => ".app-custom { background: var(--kopere-mobile-primary); }",
];
$branding = $normalized;
$scss = \app\AppManager::buildAppScss($branding, $settings);
if (!str_contains($scss, '$primary: #123456;')
        || !str_contains($scss, '$learnlingo-accent: #ABCDEF;')
        || !str_contains($scss, '$app-canvas: #F8FAFC;')
        || !str_contains($scss, '$app-ink: #334455;')
        || !str_contains($scss, '--learnlingo-navbar-bg')
        || !str_contains($scss, '.app-custom')) {
    throw new RuntimeException("LearnLingo variables or app overrides were not carried into SCSS.");
}
$settings["theme_color_sync"] = false;
$manual = \app\AppManager::buildAppScss($branding, $settings);
if (!str_contains($manual, '$primary: #123456;')) {
    throw new RuntimeException("Old manual-colour switch overrode LearnLingo.");
}
$verified = \app\AppManager::validateSettings(
    ["package_uid" => "com.example.mobile", "package_name" => "Demo",
        "statusbarbackgroundcolor" => "#000000", "app_scss" => ""],
    "moodle.example",
    ["statusbarbackgroundcolor" => "#000000"],
    $branding
);
if (!$verified["valid"] || $verified["data"]["statusbarbackgroundcolor"] !== "#123456") {
    throw new RuntimeException("App build colour still depends on the removed colour field.");
}
if (empty($argv[1]) || file_put_contents($argv[1], $scss) === false) {
    throw new RuntimeException("Cannot write SCSS fixture.");
}
echo "App branding SCSS composition OK\n";

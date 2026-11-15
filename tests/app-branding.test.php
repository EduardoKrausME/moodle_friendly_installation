<?php
// Smoke-test the app stylesheet without requiring a running Moodle site.
declare(strict_types=1);

require_once __DIR__ . "/../public/app/AppManager.php";

$branding = [
    "brandcolor" => "#123456",
    "accentcolor" => "#ABCDEF",
    "canvascolor" => "#F8FAFC",
    "textcolor" => "#334455",
    "navbar_scss" => ':root { --learnlingo-navbar-bg: #{rgba($primary, .97)}; }',
];
$settings = [
    "theme_color_sync" => true,
    "statusbarbackgroundcolor" => "#000000",
    "app_scss" => ".app-custom { background: var(--kopere-mobile-primary); }",
];
$scss = \app\AppManager::buildAppScss($branding, $settings);
if (!str_contains($scss, '$primary: #123456;')
        || !str_contains($scss, '--learnlingo-navbar-bg')
        || !str_contains($scss, '.app-custom')) {
    throw new RuntimeException("LearnLingo variables or app overrides were not carried into SCSS.");
}
$settings["theme_color_sync"] = false;
$manual = \app\AppManager::buildAppScss($branding, $settings);
if (!str_contains($manual, '$primary: #000000;')) {
    throw new RuntimeException("Manual app colour did not override LearnLingo.");
}
if (empty($argv[1]) || file_put_contents($argv[1], $scss) === false) {
    throw new RuntimeException("Cannot write SCSS fixture.");
}
echo "App branding SCSS composition OK\n";

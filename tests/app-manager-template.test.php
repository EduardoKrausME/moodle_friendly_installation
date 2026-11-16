<?php
// Regression test: the app-manager template uses strict Mustache variables.
declare(strict_types=1);

$controller = file_get_contents(__DIR__ . "/../public/app_manager.php");
$template = file_get_contents(__DIR__ . "/../public/app/templates/page/app-manager.mustache");
if ($controller === false || $template === false) {
    throw new RuntimeException("Cannot read app-manager controller or template.");
}

// Inspect only the context passed to the page renderer, not the build-job payload.
$needle = 'render_app_template("page/app-manager", [';
$start = strpos($controller, $needle);
if ($start === false) {
    throw new RuntimeException("App-manager render context not found.");
}
$context = substr($controller, $start);
foreach (["has_theme_branding", "theme_brandcolor", "theme_accentcolor",
    "theme_canvascolor", "theme_textcolor", "has_theme_logo", "theme_logo_url"] as $key) {
    if (!str_contains($template, "{{" . $key . "}}")
            && !str_contains($template, "{{#" . $key . "}}")
            && !str_contains($template, "{{^" . $key . "}}")) {
        throw new RuntimeException("Missing expected template reference: {$key}");
    }
    if (!preg_match('/"' . preg_quote($key, '/') . '"\\s*=>/', $context)) {
        throw new RuntimeException("Strict Mustache variable not passed to render context: {$key}");
    }
}
$jobpos = strpos($controller, 'JobManager::createAppBuildJob([');
if ($jobpos !== false) {
    $jobsegment = substr($controller, $jobpos, $start - $jobpos);
    foreach (["has_theme_branding", "theme_brandcolor", "theme_accentcolor",
        "theme_canvascolor", "theme_textcolor", "has_theme_logo", "theme_logo_url"] as $key) {
        if (str_contains($jobsegment, '"' . $key . '" =>')) {
            throw new RuntimeException("UI variable leaked into build job: {$key}");
        }
    }
}
if (str_contains($template, 'type="color"') || str_contains($template, 'name="theme_color_sync"')) {
    throw new RuntimeException("Manual colour selector or sync checkbox was not removed.");
}
echo "App manager Mustache context OK\n";

<?php

use app\Auth;
use app\I18n;
use app\JobManager;
use app\MoodleBranchProvider;
use app\PanelConfigManager;
use app\Validator;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$warnings = [];
$moodlebranches = MoodleBranchProvider::getInstallBranches();
$allowedbranches = array_column($moodlebranches, "name");
$themeColors = Validator::themeColors();

$defaultvalues = [
    "domain" => "",
    "site_fullname" => "",
    "admin_user" => "admin",
    "admin_email" => "admin@moodle.com",
    "moodle_branch" => "",
    "issue_cert" => "1",
    "theme_palette" => "0",
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();
    $defaultvalues = array_merge($defaultvalues, $_POST);
    $validation = Validator::validateInstallRequest($_POST, $allowedbranches);
    $errors = $validation["errors"];
    $warnings = $validation["warnings"];

    if ($validation["valid"]) {
        try {
            if (!empty($_FILES["kopere_backup_zip"]) && is_array($_FILES["kopere_backup_zip"])) {
                $backupzip = Validator::storeKopereBackupUpload($_FILES["kopere_backup_zip"], $validation["data"]["domain"]);
                if ($backupzip !== null) {
                    $validation["data"]["kopere_backup_zip"] = $backupzip;
                }
            }
        } catch (RuntimeException $e) {
            $errors["kopere_backup_zip"] = $e->getMessage();
            $validation["valid"] = false;
        }
    }

    if ($validation["valid"]) {
        $job = JobManager::createInstallJob($validation["data"]);
        $job = JobManager::updateJob($job["id"], static function(array $job) use ($validation): array {
            $job["theme_palette"] = $validation["data"]["theme_palette"];
            $job["theme_primary"] = $validation["data"]["theme_primary"];
            $job["theme_secondary"] = $validation["data"]["theme_secondary"];
            return $job;
        }) ?: $job;
        $_SESSION["flash"] = t("install.queued", ["id" => $job["id"]]);
        redirect_to("/jobs.php?job={$job["id"]}");
    }
}

$selectedbranch = $defaultvalues["moodle_branch"] ?? $defaultbranch;
foreach ($moodlebranches as $index => $branch) {
    $moodlebranches[$index]["selected"] = $branch["name"] == $selectedbranch;
}

$selectedThemePalette = (string) ($defaultvalues["theme_palette"] ?? "0");
$themePaletteOptions = [];
foreach ($themeColors as $index => $colors) {
    $themePaletteOptions[] = [
        "index" => (string) $index,
        "primary" => $colors["primary"],
        "secondary" => $colors["secondary"],
        "checked" => $selectedThemePalette === (string) $index,
        "style" => "background: linear-gradient(135deg, {$colors["primary"]} 49%, #fff 50%, {$colors["secondary"]} 51%)",
    ];
}

render_header(t("install.title"));

echo render_app_template("page/install", [
    "csrf_token" => csrf_token(),
    "dns_intro" => I18n::get("install.dns_intro", ["server_public_ip" => PanelConfigManager::detect_public_ipv4()]),
    "warnings" => array_values($warnings),
    "has_moodle_branches" => !empty($moodlebranches),
    "moodle_branch_load_failed" => empty($moodlebranches),
    "moodle_branches" => $moodlebranches,
    "theme_palette_options" => $themePaletteOptions,
    "values" => [
        "domain" => $defaultvalues["domain"] ?? "",
        "site_fullname" => $defaultvalues["site_fullname"] ?? "",
        "admin_user" => $defaultvalues["admin_user"],
        "admin_email" => $defaultvalues["admin_email"],
        "issue_cert" => !empty($defaultvalues["issue_cert"]),
        "theme_palette" => $selectedThemePalette,
    ],
    "errors" => [
        "domain" => $errors["domain"] ?? "",
        "moodle_branch" => $errors["moodle_branch"] ?? "",
        "admin_user" => $errors["admin_user"] ?? "",
        "admin_pass" => $errors["admin_pass"] ?? "",
        "admin_email" => $errors["admin_email"] ?? "",
        "kopere_backup_zip" => $errors["kopere_backup_zip"] ?? "",
        "theme_palette" => $errors["theme_palette"] ?? "",
    ],
]);

render_footer();

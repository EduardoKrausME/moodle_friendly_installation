<?php

use app\Auth;
use app\JobManager;
use app\MoodleBranchProvider;
use app\Validator;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$warnings = [];
$moodlebranches = MoodleBranchProvider::getInstallBranches();
$allowedbranches = array_column($moodlebranches, "name");

$defaultvalues = [
    "domain" => "",
    "site_fullname" => "",
    "admin_user" => "admin",
    "admin_email" => "admin@moodle.com",
    "moodle_branch" => "",
    "issue_cert" => "1",
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
        $_SESSION["flash"] = t("install.queued", ["id" => $job["id"]]);
        redirect_to("/jobs.php?job={$job["id"]}");
    }
}

$selectedbranch = $defaultvalues["moodle_branch"] ?? $defaultbranch;
foreach ($moodlebranches as $index => $branch) {
    $moodlebranches[$index]["selected"] = $branch["name"] == $selectedbranch;
}

render_header(t("install.title"));

echo render_app_template("page/install", [
    "csrf_token" => csrf_token(),
    "warnings" => array_values($warnings),
    "has_moodle_branches" => !empty($moodlebranches),
    "moodle_branch_load_failed" => empty($moodlebranches),
    "moodle_branches" => $moodlebranches,
    "values" => [
        "domain" => $defaultvalues["domain"] ?? "",
        "site_fullname" => $defaultvalues["site_fullname"] ?? "",
        "admin_user" => $defaultvalues["admin_user"],
        "admin_email" => $defaultvalues["admin_email"],
        "issue_cert" => !empty($defaultvalues["issue_cert"]),
    ],
    "errors" => [
        "domain" => $errors["domain"] ?? "",
        "moodle_branch" => $errors["moodle_branch"] ?? "",
        "admin_user" => $errors["admin_user"] ?? "",
        "admin_pass" => $errors["admin_pass"] ?? "",
        "admin_email" => $errors["admin_email"] ?? "",
        "kopere_backup_zip" => $errors["kopere_backup_zip"] ?? "",
    ],
]);

render_footer();

<?php

use app\Auth;
use app\JobManager;
use app\MoodleBranchProvider;
use app\Validator;

require_once __DIR__ . '/app/bootstrap.php';
Auth::requireLogin();

$errors = [];
$warnings = [];
$moodlebranches = MoodleBranchProvider::getInstallBranches(502, 4);
$allowedbranches = array_column($moodlebranches, 'name');
$defaultbranch = app_config('default_moodle_branch');

if (!empty($allowedbranches) && !in_array($defaultbranch, $allowedbranches, true)) {
    $defaultbranch = $allowedbranches[0];
}

$values = [
    'domain' => '',
    'site_fullname' => '',
    'admin_user' => app_config('default_admin_user'),
    'admin_email' => app_config('default_admin_email'),
    'moodle_branch' => $defaultbranch,
    'issue_cert' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $values = array_merge($values, $_POST);
    $validation = Validator::validateInstallRequest($_POST, $allowedbranches);
    $errors = $validation['errors'];
    $warnings = $validation['warnings'];

    if ($validation['valid']) {
        $job = JobManager::createInstallJob($validation['data']);
        $_SESSION['flash'] = t('install.queued', ['id' => $job['id']]);
        redirect_to('/jobs.php');
    }
}

$selectedbranch = (string) ($values['moodle_branch'] ?? $defaultbranch);
foreach ($moodlebranches as $index => $branch) {
    $moodlebranches[$index]['selected'] = $branch['name'] === $selectedbranch;
}

render_header(t('install.title'));

echo render_app_template('page/install', [
    'csrf_token' => csrf_token(),
    'warnings' => array_values($warnings),
    'has_moodle_branches' => !empty($moodlebranches),
    'moodle_branch_load_failed' => empty($moodlebranches),
    'moodle_branches' => $moodlebranches,
    'values' => [
        'domain' => (string) ($values['domain'] ?? ''),
        'site_fullname' => (string) ($values['site_fullname'] ?? ''),
        'admin_user' => (string) ($values['admin_user'] ?? app_config('default_admin_user')),
        'admin_email' => (string) ($values['admin_email'] ?? app_config('default_admin_email')),
        'issue_cert' => !empty($values['issue_cert']),
    ],
    'errors' => [
        'domain' => (string) ($errors['domain'] ?? ''),
        'moodle_branch' => (string) ($errors['moodle_branch'] ?? ''),
        'admin_user' => (string) ($errors['admin_user'] ?? ''),
        'admin_pass' => (string) ($errors['admin_pass'] ?? ''),
        'admin_email' => (string) ($errors['admin_email'] ?? ''),
    ],
]);

render_footer();

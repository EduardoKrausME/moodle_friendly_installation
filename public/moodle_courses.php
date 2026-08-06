<?php

use app\Auth;
use app\MoodleManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? strtolower(trim($_GET["domain"])) : "";
$site = SiteManager::get($domain);
if ($site === null) {
    http_response_code(404);
    render_header(t("details.not_found_title"));
    echo render_app_template("page/details-not-found");
    render_footer();
    exit;
}

$error = "";
$stats = ["total" => 0, "visible" => 0, "hidden" => 0, "activities" => 0];
$courses = ["items" => [], "total" => 0, "page" => 1, "pages" => 1];
$reports = [];
$roles = [];
$createurl = MoodleManager::adminUrl($site, "/course/edit.php?category=1&returnto=topcat");
try {
    $manager = new MoodleManager($site);
    $stats = $manager->courseStats();
    $courses = $manager->courses();
    $reports = $manager->reports("courses");
    $roles = $manager->roleOptions();
    $createurl = $manager->createCourseUrl();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$stats = array_replace(["total" => 0, "visible" => 0, "hidden" => 0, "activities" => 0], $stats);
foreach ($stats as $key => $value) {
    $stats[$key] = (string) (int) $value;
}

$courselisthtml = render_app_template("fragment/moodle-courses-list", [
    "courses" => $courses["items"] ?? [],
    "has_courses" => !empty($courses["items"]),
    "pagination" => $courses,
]);

render_header(t("moodle_courses.title"));
echo render_app_template("page/moodle-courses", [
    "domain" => $domain,
    "back_url" => "/details.php?domain=" . rawurlencode($domain),
    "create_url" => $createurl,
    "csrf_token" => csrf_token(),
    "stats" => $stats,
    "has_error" => $error !== "",
    "error" => $error,
    "course_list_html" => $courselisthtml,
    "reports" => $reports,
    "has_reports" => !empty($reports),
    "roles" => $roles,
]);
render_footer();

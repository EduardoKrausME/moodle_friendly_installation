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
$stats = ["total" => 0, "active" => 0, "suspended" => 0, "deleted" => 0];
$users = ["items" => [], "total" => 0, "page" => 1, "pages" => 1];
$reports = [];
$createurl = MoodleManager::adminUrl($site, "/user/editadvanced.php?id=-1");
try {
    $manager = new MoodleManager($site);
    $stats = $manager->userStats();
    $users = $manager->users();
    $reports = $manager->reports("users");
    $createurl = $manager->createUserUrl();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$stats = array_replace(["total" => 0, "active" => 0, "suspended" => 0, "deleted" => 0], $stats);
foreach ($stats as $key => $value) {
    $stats[$key] = (string) (int) $value;
}

$userlisthtml = render_app_template("fragment/moodle-users-list", [
    "users" => $users["items"] ?? [],
    "has_users" => !empty($users["items"]),
    "pagination" => $users,
]);

render_header(t("moodle_users.title"));
echo render_app_template("page/moodle-users", [
    "domain" => $domain,
    "site_name" => $site["course_identity"]["fullname"] ?? $domain,
    "back_url" => "/details.php?domain=" . rawurlencode($domain),
    "create_url" => $createurl,
    "csrf_token" => csrf_token(),
    "stats" => $stats,
    "has_error" => $error !== "",
    "error" => $error,
    "user_list_html" => $userlisthtml,
    "reports" => $reports,
    "has_reports" => !empty($reports),
]);
render_footer();

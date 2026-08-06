<?php

use app\Auth;
use app\MoodleManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

ini_set("display_errors", "0");
header("Content-Type: application/json; charset=utf-8");

/**
 * @param array $data
 * @param int $status
 * @return never
 */
function moodle_ajax_response(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $domain = isset($_REQUEST["domain"]) && is_string($_REQUEST["domain"])
        ? strtolower(trim($_REQUEST["domain"])) : "";
    $site = SiteManager::get($domain);
    if ($site === null) {
        moodle_ajax_response(["ok" => false, "message" => t("details.not_found_title")], 404);
    }
    $manager = new MoodleManager($site);
    $action = isset($_REQUEST["action"]) && is_string($_REQUEST["action"]) ? $_REQUEST["action"] : "";

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        if ($action === "datatable_users") {
            $length = min(100, max(10, (int) ($_GET["length"] ?? 25)));
            $start = max(0, (int) ($_GET["start"] ?? 0));
            $result = $manager->users(
                (string) ($_GET["search"] ?? ""),
                (string) ($_GET["status"] ?? "all"),
                intdiv($start, $length) + 1,
                $length,
                (string) ($_GET["sort"] ?? "name"),
                (string) ($_GET["direction"] ?? "asc")
            );
            moodle_ajax_response([
                "ok" => true,
                "draw" => max(0, (int) ($_GET["draw"] ?? 0)),
                "recordsTotal" => $result["total_unfiltered"],
                "recordsFiltered" => $result["total"],
                "data" => $result["items"],
            ]);
        }

        if ($action === "datatable_courses") {
            $length = min(100, max(10, (int) ($_GET["length"] ?? 25)));
            $start = max(0, (int) ($_GET["start"] ?? 0));
            $result = $manager->courses(
                (string) ($_GET["search"] ?? ""),
                (string) ($_GET["visibility"] ?? "all"),
                intdiv($start, $length) + 1,
                $length,
                (string) ($_GET["sort"] ?? "course"),
                (string) ($_GET["direction"] ?? "asc")
            );
            moodle_ajax_response([
                "ok" => true,
                "draw" => max(0, (int) ($_GET["draw"] ?? 0)),
                "recordsTotal" => $result["total_unfiltered"],
                "recordsFiltered" => $result["total"],
                "data" => $result["items"],
            ]);
        }

        if ($action === "list_users") {
            $result = $manager->users(
                (string) ($_GET["search"] ?? ""),
                (string) ($_GET["status"] ?? "all"),
                max(1, (int) ($_GET["page"] ?? 1))
            );
            moodle_ajax_response([
                "ok" => true,
                "html" => render_app_template("fragment/moodle-users-list", [
                    "users" => $result["items"],
                    "has_users" => !empty($result["items"]),
                    "pagination" => $result,
                ]),
            ]);
        }

        if ($action === "user_details") {
            $userid = (int) ($_GET["userid"] ?? 0);
            $user = $manager->user($userid);
            if (!$user) {
                moodle_ajax_response(["ok" => false, "message" => t("moodle_users.not_found")], 404);
            }
            $reports = $manager->reports("users", $userid);
            moodle_ajax_response([
                "ok" => true,
                "html" => render_app_template("fragment/moodle-user-details", [
                    "user" => $user,
                    "domain" => $domain,
                    "csrf_token" => csrf_token(),
                    "reports" => $reports,
                    "has_reports" => !empty($reports),
                ]),
            ]);
        }

        if ($action === "list_courses") {
            $result = $manager->courses(
                (string) ($_GET["search"] ?? ""),
                (string) ($_GET["visibility"] ?? "all"),
                max(1, (int) ($_GET["page"] ?? 1))
            );
            moodle_ajax_response([
                "ok" => true,
                "html" => render_app_template("fragment/moodle-courses-list", [
                    "courses" => $result["items"],
                    "has_courses" => !empty($result["items"]),
                    "pagination" => $result,
                ]),
            ]);
        }

        if ($action === "course_details") {
            $courseid = (int) ($_GET["courseid"] ?? 0);
            $course = $manager->course($courseid, (string) ($_GET["search"] ?? ""));
            if (!$course) {
                moodle_ajax_response(["ok" => false, "message" => t("moodle_courses.not_found")], 404);
            }
            $reports = $manager->reports("courses", $courseid);
            moodle_ajax_response([
                "ok" => true,
                "html" => render_app_template("fragment/moodle-course-details", [
                    "course" => $course,
                    "domain" => $domain,
                    "csrf_token" => csrf_token(),
                    "reports" => $reports,
                    "has_reports" => !empty($reports),
                ]),
            ]);
        }

        if ($action === "find_users") {
            moodle_ajax_response([
                "ok" => true,
                "items" => $manager->availableUsers(
                    (int) ($_GET["courseid"] ?? 0),
                    (string) ($_GET["search"] ?? "")
                ),
            ]);
        }

        moodle_ajax_response(["ok" => false, "message" => t("moodle_manager.invalid_action")], 400);
    }

    $token = $_POST["csrf_token"] ?? "";
    if (!is_string($token) || empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        moodle_ajax_response(["ok" => false, "message" => t("moodle_manager.invalid_csrf")], 400);
    }
    if ($action === "user_state") {
        $state = (string) ($_POST["state"] ?? "");
        $map = [
            "suspend" => "user_suspend",
            "activate" => "user_activate",
            "delete" => "user_delete",
            "restore" => "user_restore",
        ];
        if (!isset($map[$state])) {
            moodle_ajax_response(["ok" => false, "message" => t("moodle_manager.invalid_action")], 400);
        }
        $userid = (int) ($_POST["userid"] ?? 0);
        $manager->execute($map[$state], ["userid" => $userid]);
        moodle_ajax_response(["ok" => true, "message" => t("moodle_users.action_{$state}_done")]);
    }

    if ($action === "participant_add") {
        $courseid = (int) ($_POST["courseid"] ?? 0);
        $manager->execute("participant_add", [
            "courseid" => $courseid,
            "userid" => (int) ($_POST["userid"] ?? 0),
            "roleid" => (int) ($_POST["roleid"] ?? 0),
        ]);
        moodle_ajax_response(["ok" => true, "message" => t("moodle_courses.participant_added")]);
    }

    if ($action === "participant_update") {
        $manager->execute("participant_update", [
            "courseid" => (int) ($_POST["courseid"] ?? 0),
            "userid" => (int) ($_POST["userid"] ?? 0),
            "roleid" => (int) ($_POST["roleid"] ?? 0),
            "active" => (string) ($_POST["active"] ?? "0") === "1",
        ]);
        moodle_ajax_response(["ok" => true, "message" => t("moodle_courses.participant_updated")]);
    }

    if ($action === "participant_remove") {
        $manager->execute("participant_remove", [
            "courseid" => (int) ($_POST["courseid"] ?? 0),
            "userid" => (int) ($_POST["userid"] ?? 0),
        ]);
        moodle_ajax_response(["ok" => true, "message" => t("moodle_courses.participant_removed")]);
    }

    moodle_ajax_response(["ok" => false, "message" => t("moodle_manager.invalid_action")], 400);
} catch (Throwable $exception) {
    moodle_ajax_response(["ok" => false, "message" => $exception->getMessage()], 500);
}

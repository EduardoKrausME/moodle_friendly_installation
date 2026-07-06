<?php

use app\AppUpdater;
use app\Auth;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$flash = flash_message();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();
    try {
        $result = AppUpdater::requestInstall();
        $_SESSION["flash"] = $result["message"] ?? t("updater.wait_update_message");
        redirect_to("/update.php");
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$state = AppUpdater::state();
$hascachedupdate = AppUpdater::hasCachedUpdate($state);
$updaterequested = !empty($state["update_requested"]);
$installing = ($state["update_status"] ?? "") === "installing";

render_header(t("updater.title"));
$mustachedata = [
    "csrf_token" => csrf_token(),
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "error" => $error,
    "has_error" => $error != "",
    "update_available" => $hascachedupdate,
    "has_update_button" => $hascachedupdate && !$updaterequested && !$installing,
    "update_requested" => $updaterequested || $installing,
    "is_current" => !$hascachedupdate && !$updaterequested && !$installing && $error == "",
    "status" => [
        "value" => $state["update_status"] ?? "",
        "message" => $state["update_message"] ?? "",
        "requested_at" => $state["update_requested_at"] ?? "",
        "requested_by" => $state["update_requested_by"] ?? "",
        "checked_at" => $state["latest_checked_at"] ?? "",
        "marked_at" => $state["update_marked_at"] ?? "",
    ],
    "current" => [
        "tag" => $state["installed_tag"] ?? "",
        "name" => $state["installed_name"] ?? "",
        "installed_at" => $state["installed_at"] ?? "",
        "updated_at" => $state["updated_at"] ?? "",
        "updated_by" => $state["updated_by"] ?? "",
        "previous_tag" => $state["previous_tag"] ?? "",
        "backup_dir" => $state["backup_dir"] ?? "",
    ],
    "latest" => [
        "tag" => $state["latest_tag"] ?? "",
        "name" => $state["latest_name"] ?? "",
        "published_at" => $state["latest_published_at"] ?? "",
        "html_url" => $state["latest_html_url"] ?? "",
        "has_html_url" => !empty($state["latest_html_url"] ?? ""),
    ],
];
echo render_app_template("page/update", $mustachedata);
render_footer();

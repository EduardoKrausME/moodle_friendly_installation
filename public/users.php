<?php

use app\Auth;
use app\UserManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$flash = flash_message();
$currentuser = Auth::user();
$currentusername = UserManager::normalizeUsername((string) ($currentuser["username"] ?? ""));
$editingusername = isset($_GET["username"]) && is_string($_GET["username"]) ? UserManager::normalizeUsername($_GET["username"]) : "";
$formvalues = [
    "original_username" => "",
    "username" => "",
    "name" => "",
    "status" => UserManager::STATUS_ACTIVE,
    "editing" => false,
    "password_required" => true,
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();

    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "save";

    try {
        if ($action == "change_status") {
            $username = isset($_POST["username"]) && is_string($_POST["username"]) ? $_POST["username"] : "";
            $status = isset($_POST["status"]) && is_string($_POST["status"]) ? $_POST["status"] : UserManager::STATUS_ACTIVE;
            UserManager::updateStatus($username, $status);
            $_SESSION["flash"] = t("users.status_saved");
            redirect_to("/users.php");
        }

        $originalusername = isset($_POST["original_username"]) && is_string($_POST["original_username"])
            ? UserManager::normalizeUsername($_POST["original_username"])
            : "";
        $username = isset($_POST["username"]) && is_string($_POST["username"])
            ? UserManager::normalizeUsername($_POST["username"])
            : "";
        $name = isset($_POST["name"]) && is_string($_POST["name"]) ? trim($_POST["name"]) : "";
        $password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";
        $status = isset($_POST["status"]) && is_string($_POST["status"]) ? $_POST["status"] : UserManager::STATUS_ACTIVE;

        if ($originalusername == "") {
            UserManager::create($username, $name, $password, $status);
            $_SESSION["flash"] = t("users.created");
        } else {
            $updateduser = UserManager::update($originalusername, $username, $name, $password != "" ? $password : null, $status);
            if ($currentusername != "" && $currentusername == $originalusername) {
                $_SESSION["user"] = [
                    "username" => $updateduser["username"],
                    "name" => $updateduser["name"],
                ];
            }
            $_SESSION["flash"] = t("users.updated");
        }

        redirect_to("/users.php");
    } catch (Throwable $exception) {
        $errors[] = ["message" => $exception->getMessage()];
        $editingusername = UserManager::normalizeUsername((string) ($_POST["original_username"] ?? ""));
        $formvalues = [
            "original_username" => $editingusername,
            "username" => isset($_POST["username"]) && is_string($_POST["username"]) ? UserManager::normalizeUsername($_POST["username"]) : "",
            "name" => isset($_POST["name"]) && is_string($_POST["name"]) ? trim($_POST["name"]) : "",
            "status" => isset($_POST["status"]) && is_string($_POST["status"]) ? UserManager::normalizeStatus($_POST["status"]) : UserManager::STATUS_ACTIVE,
            "editing" => $editingusername != "",
            "password_required" => $editingusername == "",
        ];
    }
}

if ($_SERVER["REQUEST_METHOD"] != "POST" && $editingusername != "") {
    $editinguser = UserManager::get($editingusername);
    if ($editinguser) {
        $formvalues = [
            "original_username" => $editinguser["username"],
            "username" => $editinguser["username"],
            "name" => $editinguser["name"],
            "status" => $editinguser["status"],
            "editing" => true,
            "password_required" => false,
        ];
    } else {
        $editingusername = "";
        $errors[] = ["message" => t("users.not_found")];
    }
}

$users = UserManager::all();
$activecount = 0;
foreach ($users as $user) {
    if (UserManager::isActive($user)) {
        $activecount++;
    }
}

$listusers = array_map(static function(array $user) use ($currentusername, $activecount): array {
    $username = (string) ($user["username"] ?? "");
    $status = UserManager::normalizeStatus($user["status"] ?? UserManager::STATUS_ACTIVE);
    $isactive = $status == UserManager::STATUS_ACTIVE;
    $iscurrent = $username != "" && $username == $currentusername;
    $canchangestatus = !$iscurrent && (!$isactive || $activecount > 1);
    $nextstatus = $isactive ? UserManager::STATUS_DISABLED : UserManager::STATUS_ACTIVE;

    return [
        "username" => $username,
        "name" => $user["name"] ?? $username,
        "status" => $status,
        "status_badge" => status_badge($isactive ? "active" : "danger", UserManager::statusLabel($status)),
        "edit_url" => "/users.php?username=" . rawurlencode($username),
        "is_current" => $iscurrent,
        "show_current_badge" => $iscurrent,
        "can_change_status" => $canchangestatus,
        "cannot_change_status" => !$canchangestatus,
        "next_status" => $nextstatus,
        "status_action_label" => $isactive ? t("users.disable_user") : t("users.enable_user"),
    ];
}, $users);

$formvalues["status_options"] = UserManager::statusOptions((string) ($formvalues["status"] ?? UserManager::STATUS_ACTIVE));
$formvalues["form_title"] = !empty($formvalues["editing"]) ? t("users.edit_heading") : t("users.create_heading");
$formvalues["password_help"] = !empty($formvalues["editing"])
    ? t("users.password_help_optional")
    : t("users.password_help_required");
$formvalues["submit_label"] = !empty($formvalues["editing"]) ? t("users.save_user") : t("users.create_user");

render_header(t("users.title"));
echo render_app_template("page/users", [
    "csrf_token" => csrf_token(),
    "flash" => $flash,
    "has_flash" => $flash != null && $flash != "",
    "has_errors" => !empty($errors),
    "errors" => $errors,
    "users" => $listusers,
    "has_users" => !empty($listusers),
    "form" => $formvalues,
]);
render_footer();

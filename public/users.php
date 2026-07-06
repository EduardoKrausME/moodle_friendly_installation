<?php

use app\Auth;
use app\UserManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$errors = [];
$flash = flash_message();
$currentuser = Auth::user();
$currentusername = UserManager::normalizeUsername((string) ($currentuser["username"] ?? ""));
$action = isset($_GET["action"]) && is_string($_GET["action"]) ? $_GET["action"] : "list";
$editingusername =
    isset($_GET["username"]) && is_string($_GET["username"]) ? UserManager::normalizeUsername($_GET["username"]) : "";

// Compatibility with old edit links like /users.php?username=admin.
if ($action == "list" && $editingusername != "") {
    $action = "edit";
}

$showform = in_array($action, ["create", "edit"], true);
$showlist = !$showform;
$formvalues = [
    "original_username" => "",
    "username" => "",
    "name" => "",
    "status" => "active",
    "editing" => false,
    "creating" => true,
    "password_required" => true,
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();
    try {
        $originalusername = isset($_POST["original_username"]) && is_string($_POST["original_username"])
            ? UserManager::normalizeUsername($_POST["original_username"])
            : "";
        $username = isset($_POST["username"]) && is_string($_POST["username"])
            ? UserManager::normalizeUsername($_POST["username"])
            : "";
        $name = isset($_POST["name"]) && is_string($_POST["name"]) ? trim($_POST["name"]) : "";
        $password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";
        $status = isset($_POST["status"]) && is_string($_POST["status"]) ? $_POST["status"] : "active";

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
        $showform = true;
        $showlist = false;
        $action = $editingusername != "" ? "edit" : "create";
        $formvalues = [
            "original_username" => $editingusername,
            "username" => isset($_POST["username"]) && is_string($_POST["username"]) ?
                UserManager::normalizeUsername($_POST["username"]) : "",
            "name" => isset($_POST["name"]) && is_string($_POST["name"]) ? trim($_POST["name"]) : "",
            "status" => isset($_POST["status"]) && is_string($_POST["status"]) ? UserManager::normalizeStatus($_POST["status"]) :
                "active",
            "editing" => $editingusername != "",
            "creating" => $editingusername == "",
            "password_required" => $editingusername == "",
        ];
    }
}

if ($_SERVER["REQUEST_METHOD"] != "POST" && $action == "edit") {
    if ($editingusername == "") {
        $showform = false;
        $showlist = true;
        $errors[] = ["message" => t("users.not_found")];
    } else {
        $editinguser = UserManager::get($editingusername);
        if ($editinguser) {
            $formvalues = [
                "original_username" => $editinguser["username"],
                "username" => $editinguser["username"],
                "name" => $editinguser["name"],
                "status" => $editinguser["status"],
                "editing" => true,
                "creating" => false,
                "password_required" => false,
            ];
        } else {
            $showform = false;
            $showlist = true;
            $editingusername = "";
            $errors[] = ["message" => t("users.not_found")];
        }
    }
}

if (!in_array($action, ["list", "create", "edit"], true)) {
    $showform = false;
    $showlist = true;
}

$users = [];
$listusers = [];
$activecount = 0;
if ($showlist) {
    $users = UserManager::all();
    foreach ($users as $user) {
        if (UserManager::isActive($user)) {
            $activecount++;
        }
    }

    foreach ($users as $user) {
        $status = UserManager::normalizeStatus($user["status"] ?? "active");
        $isactive = $status == "active";

        $listusers[] = [
            "username" => $user["username"],
            "name" => $user["name"] ?? $user["username"],
            "status" => $status,
            "status_badge" => status_badge($isactive ? "active" : "danger", UserManager::statusLabel($status)),
            "edit_url" => "/users.php?action=edit&username=" . rawurlencode($user["username"]),
            "is_current" => $user["username"] == $currentusername,
            "can_change_status" => !($user["username"] == $currentusername && (!$isactive || $activecount > 1)),
            "next_status" => $isactive ? "disabled" : "active",
            "status_action_label" => $isactive ? t("users.disable_user") : t("users.enable_user"),
        ];
    }
}

$formvalues["status_options"] = UserManager::statusOptions($formvalues["status"] ?? "active");
$formvalues["form_title"] = !empty($formvalues["editing"]) ? t("users.edit_heading") : t("users.create_heading");
$formvalues["password_help"] =
    !empty($formvalues["editing"]) ? t("users.password_help_optional") : t("users.password_help_required");
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
    "show_list" => $showlist,
    "show_form" => $showform,
    "form" => $formvalues,
    "is_current" => $formvalues["username"] == $currentusername,
]);
render_footer();

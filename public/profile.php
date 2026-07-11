<?php

use app\Auth;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$currentuser = Auth::currentUserRecord();
if (!$currentuser) {
    Auth::logout();
    redirect_to("/login.php");
}

$errors = [];
$flash = flash_message();
$forcechange = Auth::requiresPasswordChange();
$username = $currentuser["username"] ?? "";
$name = $currentuser["name"] ?? $username;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validate_csrf();

    $username = strtolower(trim($_POST["username"] ?? ""));
    $name = trim($_POST["name"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $username)) {
        $errors[] = t("profile.username_invalid");
    }

    if ($name == "") {
        $errors[] = t("profile.name_required");
    }

    if ($forcechange && $password == "") {
        $errors[] = t("profile.password_required_force");
    }

    if ($password != "" && strlen($password) < 6) {
        $errors[] = t("profile.password_short");
    }

    if ($password != "" && hash_equals($password, "123456")) {
        $errors[] = t("profile.password_cannot_be_default");
    } else if ($password != "" && hash_equals($password, "admin")) {
        $errors[] = t("profile.password_cannot_be_default");
    }

    if (empty($errors)) {
        try {
            Auth::updateCurrentUser($username, $name, $password != "" ? $password : null);
            $_SESSION["flash"] = t("profile.saved");
            redirect_to("/");
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

render_header(t("profile.title"));
echo render_app_template("page/profile", [
    "csrf_token" => csrf_token(),
    "username" => $username,
    "name" => $name,
    "force_password_change" => $forcechange,
    "has_flash" => $flash != null && $flash != "",
    "flash" => $flash,
    "has_errors" => !empty($errors),
    "errors" => array_map(static function(string $error): array {
        return ["message" => $error];
    }, $errors),
]);
render_footer();

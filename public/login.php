<?php

use app\Auth;

require_once __DIR__ . "/app/bootstrap.php";

$redirectto = Auth::redirectTarget($_POST["redirect_to"] ?? $_GET["redirect_to"] ?? "/");

if (Auth::check()) {
    redirect_to($redirectto);
}

$error = null;
$username = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = is_string($_POST["username"] ?? null) ? trim($_POST["username"]) : "";
    $password = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";
    if (Auth::attempt($username, $password)) {
        redirect_to($redirectto);
    }
    $error = Auth::loginError() ?? t("login.invalid_credentials");
}

Auth::renderLogin($redirectto, $error, $username);

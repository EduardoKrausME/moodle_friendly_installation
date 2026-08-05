<?php

error_reporting(E_ALL);
ini_set("display_errors", "On");

use app\Auth;

require_once __DIR__ . "/app/bootstrap.php";

Auth::requireLogin();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

validate_csrf();

/*
 * Fecha a sessão do painel sem destruí-la.
 */
session_write_close();

session_name("PMA_SIGNON");

/*
 * Impede o reaproveitamento do ID da sessão do painel.
 */
session_id("");

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/phpMyAdmin/",
    "domain" => "",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Strict",
]);

session_start();
session_regenerate_id(true);

$configbase = app_config();
$_SESSION = [
    "PMA_single_signon_user" => $configbase["mysql_admin_user"],
    "PMA_single_signon_password" => $configbase["mysql_admin_pass"],
    "PMA_single_signon_host" => $configbase["mysql_admin_host"],
    "PMA_single_signon_port" => $configbase["mysql_admin_port"],
    "PMA_single_signon_HMAC_secret" => bin2hex(random_bytes(32)),
];

session_write_close();

header("Location: /phpMyAdmin/");
exit;
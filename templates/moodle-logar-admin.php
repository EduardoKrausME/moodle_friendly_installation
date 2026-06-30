<?php
/***************************
 * User: kraus
 * Date: 22/01/2016
 * Time: 13:28
 ***************************/

$inicio = microtime(true);

ob_start();

error_reporting(E_ALL);
ini_set("display_errors", "On");

require "config.php";

// Validate
$time = $_GET["time"];
$signature = $_GET["signature"];
if ((time() - $time) > 300) {
    die("Expirado...");
}
$expected = hash_hmac("sha256", $time, $CFG->dbname);
if (!hash_equals($expected, $signature)) {
    die("Link Inválido...");
}

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

error_reporting(E_ALL);
ini_set("display_errors", "On");

$releases = explode(".", $CFG->release);
$release = intval($releases[0]) . "." . intval($releases[1]);

$sql = "SELECT * FROM {user} u WHERE u.id IN ($CFG->siteadmins) LIMIT 1";
$user = $DB->get_record_sql($sql);

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

\core\session\manager::login_user($user);

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

// Update login times.
update_user_login_times();

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

// Extra session prefs init.
set_login_session_preferences();

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

// Trigger login event.
$event = \core\event\user_loggedin::create(
    [
        "userid" => $user->id,
        "objectid" => $user->id,
        "other" => ["username" => $user->username],
    ]
);
$event->trigger();

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

$SESSION->tool_mfa_authenticated = true;

printf("<br>Processado em: %0.16f segundos", (microtime(true) - $inicio) / 1000000);

header("Location: ./");

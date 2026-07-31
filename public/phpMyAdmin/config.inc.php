<?php
/**
 * phpMyAdmin sample configuration, you can use it as base for
 * manual configuration. For easier setup you can use setup/
 *
 * All directives are explained in documentation in the doc/ folder
 * or at <https://docs.phpmyadmin.net/>.
 */

declare(strict_types = 1);

$cfg['Export']['method'] = "custom";
$cfg['Export']['sql_if_not_exists'] = true;
$cfg['MaxTableList'] = 1000;
$cfg['MaxRows'] = 50;

/**
 * This is needed for cookie based authentication to encrypt the cookie.
 * Needs to be a 32-bytes long string of random bytes. See FAQ 2.10.
 */
$cfg['blowfish_secret'] = ''; /* YOU MUST FILL IN THIS FOR COOKIE AUTH! */

/**
 * Servers configuration
 */
$i = 0;

/**
 * First server
 */
$i++;
/* Authentication type */

$configbase = require __DIR__ . "/../config.php";

$i = 0;
$i++;

$baseurl = rtrim($configbase["base_url"], "/");

$cfg["Servers"][$i]["auth_type"] = "signon";
$cfg["Servers"][$i]["host"] = $configbase["mysql_admin_host"];
$cfg["Servers"][$i]["port"] = $configbase["mysql_admin_port"];
$cfg["Servers"][$i]["socket"] = $configbase["mysql_admin_socket"];
$cfg["Servers"][$i]["compress"] = false;
$cfg["Servers"][$i]["AllowNoPassword"] = false;

$cfg["Servers"][$i]["SignonSession"] = "PMA_SIGNON";

$cfg["Servers"][$i]["SignonCookieParams"] = [
    "lifetime" => 0,
    "path" => "/",
    "domain" => "",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Strict",
];

$cfg["Servers"][$i]["SignonURL"] = "{$baseurl}/";
$cfg["Servers"][$i]["LogoutURL"] = "{$baseurl}/";

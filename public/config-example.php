<?php

// Configuration for the MyMoodle Moodle Admin dashboard.
return [
    "base_url" => "",

    "db_engine" => "mysqli",
    "apache_user" => "apache",
    "apache_group" => "apache",
    "php_bin" => "/usr/bin/php",

    // MySQL admin connection used only by the root runner.
    // The dashboard web pages do not connect to MySQL.
    "mysql_admin_host" => "localhost",
    "mysql_admin_port" => 3306,
    "mysql_admin_socket" => null,
    "mysql_admin_user" => "root",
    "mysql_admin_pass" => "",
];

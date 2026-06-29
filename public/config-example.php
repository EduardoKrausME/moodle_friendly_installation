<?php

// Configuration for the MyMoodle Moodle Admin dashboard.
return [
    "app_name" => "Moodle Admin",
    "base_url" => "https://admin.moodle",

    // Domain restrictions.
    "reserved_domains" => [
        "admin.moodle",
    ],

    // Keep this directory outside the public webroot.
    "base_dir" => realpath(__DIR__ . "/.."),

    // Target server layout.
    "default_moodle_branch" => "MOODLE_502_STABLE",
    "db_engine" => "mysql",
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

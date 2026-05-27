<?php

// Configuration for the MyLearn Moodle Admin dashboard.
return [
    "app_name" => "Moodle Admin",
    "base_url" => "https://admin.moodle",

    // Keep this directory outside the public webroot.
    "base_dir" => realpath(__DIR__ . "/.."),

    // Target server layout.
    "moodle_git_url" => "https://github.com/moodle/moodle.git",
    "default_moodle_branch" => "MOODLE_502_STABLE",
    "home_base_dir" => "/home",
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

    // Web server paths required by the requested architecture.
    "apache_sites_enabled" => "/etc/httpd/sites-enabled",
    "nginx_sites_enabled" => "/etc/nginx/sites-enabled",

    // Default values for new Moodle installs.
    "default_site_fullname_prefix" => "moodle",
    "default_admin_user" => "admin",
    "default_admin_email" => "admin@moodle.moodle",

    // Domain restrictions.
    "reserved_domains" => [
        "admin.moodle.moodle",
    ],
];

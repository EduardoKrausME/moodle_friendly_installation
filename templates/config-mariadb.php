<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = '{{DB_NAME}}';
$CFG->dbuser    = '{{DB_USER}}';
$CFG->dbpass    = '{{DB_PASS}}';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => false,
    'dbport' => '',
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_general_ci',
    'logslow'     => 3,
];

$CFG->wwwroot = 'https://{{DOMAIN}}';
$CFG->dataroot = '{{BASE_DIR}}/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->sslproxy = true;

$CFG->showcampaigncontent           = false;
$CFG->showservicesandsupportcontent = false;
$CFG->enableuserfeedback            = " ";
$CFG->registrationpending           = false;
$CFG->site_is_public                = false;
//$CFG->disableupdatenotifications    = true;
$CFG->routerconfigured              = true;

$domainroot = dirname(__DIR__);
if (file_exists($domainroot . "/../debug.enable")) {
    set_time_limit(0);
    ini_set("max_execution_time", 0);
    error_reporting(E_ALL);
    ini_set("display_errors", "1");
    ini_set("display_startup_errors", "1");

    $CFG->debug = 30719;
    $CFG->debugdisplay = 1;
}

if (file_exists("{$domainroot}/email.disable")) {
    $CFG->noemailever = true;
}

if (file_exists("{$domainroot}/email.redirect")) {
    $email = trim(file_get_contents("{$domainroot}/email.redirect"));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $CFG->divertallemailsto = $email;
    }
}

if (file_exists("{$domainroot}/theme-designer.enable")) {
    $CFG->themedesignermode = true;
}

if (file_exists("{$domainroot}/cache-dev.enable")) {
    $CFG->cachejs = false;
    $CFG->cachetemplates = false;
    $CFG->langstringcache = false;
}

if (file_exists("{$domainroot}/cron-debug.enable")) {
    $CFG->showcrondebugging = true;
}

if (file_exists("{$domainroot}/slow-sql.disable")) {
    unset($CFG->dboptions["logslow"]);
}

if (file_exists("{$domainroot}/perf.enable")) {
    define("MDL_PERF", true);
    define("MDL_PERFDB", true);
    define("MDL_PERFTOLOG", true);
}

require_once(__DIR__ . '/public/lib/setup.php');
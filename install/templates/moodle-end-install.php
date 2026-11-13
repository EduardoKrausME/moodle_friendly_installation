<?php
/**
 * Apply initial configuration for a new Moodle site.
 *
 * Installed temporarily in admin/cli and invoked by install-moodle.sh.
 * Usage: php moodle-end-install.php <moodle-lang> <hub-lang>
 *
 * The parent installer deletes the temporary copy after execution, including on failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

if ($argc !== 3 ||
        !preg_match('/^[a-z]{2,3}(?:_[a-z]{2})?$/', $argv[1]) ||
        !preg_match('/^[a-z]{2,3}(?:_[a-z]{2})?$/', $argv[2])) {
    fwrite(STDERR, "Usage: php moodle-end-install.php <moodle-lang> <hub-lang>\n");
    exit(2);
}

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/blocklib.php');

/**
 * Set a Moodle configuration value without overriding config.php-enforced settings.
 *
 * @param string $name
 * @param string $value
 * @param string|null $component
 */
function friendly_install_set_config(string $name, string $value, ?string $component = null): void {
    global $CFG;

    if ($component === null) {
        $forced = $CFG->config_php_settings ?? [];
    } else {
        $forced = $CFG->forced_plugin_settings[$component] ?? [];
    }
    if (array_key_exists($name, $forced)) {
        throw new RuntimeException("Cannot change config.php-enforced setting: {$name}");
    }
    if (!set_config($name, $value, $component)) {
        throw new RuntimeException("Failed to set Moodle configuration: {$name}");
    }
}

$settings = [
    'theme' => 'learnlingo',
    'enabledashboard' => '0',
    'enablemycourses' => '1',
    'defaulthomepage' => '0',
    'allowguestmymoodle' => '0',
    'forcelogin' => '0',
    'enablemyhome' => '1',
    'navshowallcourses' => '0',
    'allowframembedding' => '1',
    'autolang' => '1',
    'lang' => $argv[1],
    'langmenu' => '0',
    'autologinguests' => '0',
    'guestloginbutton' => '0',
    'passwordpolicy' => '0',
    'cronremotepassword' => bin2hex(random_bytes(16)),
    'cronclionly' => '0',
    'siteadmins' => '3,2',
];
foreach ($settings as $name => $value) {
    friendly_install_set_config($name, $value);
}

friendly_install_set_config('loglifetime', '3', 'backup');

$hubsettings = [
    'site_policyagreed' => '1',
    'site_language' => $argv[2],
    'site_countrycode' => 'BR',
    'site_privacy' => 'notdisplayed',
    'site_contactemail' => 'kraus@eduardokraus.com',
    'site_contactable' => '0',
    'site_emailalert' => '0',
    'site_commnews' => '0',
    'site_name' => '.',
    'site_description' => '.',
    'site_imageurl' => '.',
    'site_contactphone' => '.',
    'site_regioncode' => '-',
    'site_geolocation' => '.',
    'site_street' => '.',
];
foreach ($hubsettings as $name => $value) {
    friendly_install_set_config($name, $value, 'hub');
}

// Use Moodle's block API so contexts, block positions and user preferences are removed too.
$blocks = $DB->get_records('block_instances', ['pagetypepattern' => '*']);
foreach ($blocks as $block) {
    blocks_delete_instance($block);
}

// Remove user tours in foreign-key order (steps reference their parent tours).
$DB->delete_records('tool_usertours_steps');
$DB->delete_records('tool_usertours_tours');

fwrite(STDOUT, "Moodle defaults applied; wildcard blocks and user tours removed.\n");

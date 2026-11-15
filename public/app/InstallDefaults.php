<?php
// Stores reusable Moodle installation defaults outside the public web directory.
namespace app;

use InvalidArgumentException;

class InstallDefaults {
    private const string FILE = "/data/install-defaults.json";

    /**
     * Returns the last saved Moodle admin e-mail, or an empty value for a new panel.
     */
    public static function adminEmail(): string {
        $defaults = JsonStorage::read(app_config_path(self::FILE));
        $email = is_array($defaults) ? ($defaults["admin_email"] ?? null) : null;
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : "";
    }

    /**
     * Saves only an e-mail validated by the installation form.
     */
    public static function saveAdminEmail(string $email): void {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid Moodle administrator e-mail.");
        }
        JsonStorage::write(app_config_path(self::FILE), ["admin_email" => $email]);
    }
}

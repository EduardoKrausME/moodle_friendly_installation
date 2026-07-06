<?php
// User management helper for panel accounts stored in data/users.json.
namespace app;

use RuntimeException;

/**
 * Class UserManager
 */
class UserManager {
    public const string STATUS_ACTIVE = "active";
    public const string STATUS_DISABLED = "disabled";

    /**
     * Function usersFile
     *
     * @return string
     */
    public static function usersFile(): string {
        return app_config_path("/data/users.json");
    }

    /**
     * Function all
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array {
        $users = JsonStorage::read(self::usersFile(), []);
        if (!is_array($users)) {
            return [];
        }

        $normalized = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $normalized[] = self::normalizeUser($user);
        }

        usort($normalized, static function(array $a, array $b): int {
            return strcasecmp((string) ($a["username"] ?? ""), (string) ($b["username"] ?? ""));
        });

        return $normalized;
    }

    /**
     * Function get
     *
     * @param string $username
     * @return array|null
     */
    public static function get(string $username): ?array {
        $username = self::normalizeUsername($username);
        if ($username == "") {
            return null;
        }

        foreach (self::all() as $user) {
            if (($user["username"] ?? "") == $username) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Function normalizeUsername
     *
     * @param string $username
     * @return string
     */
    public static function normalizeUsername(string $username): string {
        return strtolower(trim($username));
    }

    /**
     * Function normalizeStatus
     *
     * @param string|null $status
     * @return string
     */
    public static function normalizeStatus(?string $status): string {
        $status = strtolower(trim((string) $status));
        if (in_array($status, ["disabled", "inactive", "blocked", "suspended"], true)) {
            return self::STATUS_DISABLED;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Function isActive
     *
     * @param array $user
     * @return bool
     */
    public static function isActive(array $user): bool {
        return self::normalizeStatus($user["status"] ?? self::STATUS_ACTIVE) == self::STATUS_ACTIVE;
    }

    /**
     * Function statusOptions
     *
     * @param string $selected
     * @return array<int, array<string, mixed>>
     */
    public static function statusOptions(string $selected): array {
        $selected = self::normalizeStatus($selected);
        $options = [];
        foreach ([self::STATUS_ACTIVE, self::STATUS_DISABLED] as $status) {
            $options[] = [
                "value" => $status,
                "label" => self::statusLabel($status),
                "selected" => $status == $selected,
            ];
        }

        return $options;
    }

    /**
     * Function statusLabel
     *
     * @param string $status
     * @return string
     */
    public static function statusLabel(string $status): string {
        $status = self::normalizeStatus($status);
        return $status == self::STATUS_ACTIVE ? t("users.status_active") : t("users.status_disabled");
    }

    /**
     * Function create
     *
     * @param string $username
     * @param string $name
     * @param string $password
     * @param string $status
     * @return void
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function create(string $username, string $name, string $password, string $status): void {
        $username = self::normalizeUsername($username);
        $name = trim($name);
        $status = self::normalizeStatus($status);

        self::validateUserData($username, $name, $password, true);

        $users = self::rawUsers();
        foreach ($users as $user) {
            if (is_array($user) && (($user["username"] ?? "") == $username)) {
                throw new RuntimeException(t("users.username_already_exists"));
            }
        }

        $now = now_iso();
        $users[] = [
            "username" => $username,
            "name" => $name,
            "password" => password_hash($password, PASSWORD_DEFAULT),
            "status" => $status,
            "created_at" => $now,
            "updated_at" => $now,
            "password_changed_at" => $now,
        ];

        self::assertAtLeastOneActive($users);
        JsonStorage::write(self::usersFile(), $users);
    }

    /**
     * Function update
     *
     * @param string $originalusername
     * @param string $username
     * @param string $name
     * @param string|null $password
     * @param string $status
     * @return array<string, mixed>
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function update(
        string $originalusername,
        string $username,
        string $name,
        ?string $password,
        string $status
    ): array {
        $originalusername = self::normalizeUsername($originalusername);
        $username = self::normalizeUsername($username);
        $name = trim($name);
        $status = self::normalizeStatus($status);
        $passwordforvalidation = $password === null ? "" : $password;

        self::validateUserData($username, $name, $passwordforvalidation, false);
        self::assertCurrentUserCanReceiveStatus($originalusername, $status);

        $users = self::rawUsers();
        $found = false;
        $updateduser = null;

        foreach ($users as &$user) {
            if (!is_array($user)) {
                continue;
            }

            if (($user["username"] ?? "") != $originalusername) {
                if (($user["username"] ?? "") == $username) {
                    throw new RuntimeException(t("users.username_already_exists"));
                }
                continue;
            }

            $user["username"] = $username;
            $user["name"] = $name;
            $user["status"] = $status;
            $user["updated_at"] = now_iso();

            if ($password !== null && $password !== "") {
                $user["password"] = password_hash($password, PASSWORD_DEFAULT);
                $user["password_changed_at"] = now_iso();
            }

            $found = true;
            $updateduser = self::normalizeUser($user);
            break;
        }
        unset($user);

        if (!$found || $updateduser == null) {
            throw new RuntimeException(t("users.not_found"));
        }

        self::assertAtLeastOneActive($users);
        JsonStorage::write(self::usersFile(), $users);

        return $updateduser;
    }

    /**
     * Function updateStatus
     *
     * @param string $username
     * @param string $status
     * @return void
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function updateStatus(string $username, string $status): void {
        $username = self::normalizeUsername($username);
        $status = self::normalizeStatus($status);
        self::assertCurrentUserCanReceiveStatus($username, $status);

        $users = self::rawUsers();
        $found = false;

        foreach ($users as &$user) {
            if (!is_array($user) || (($user["username"] ?? "") != $username)) {
                continue;
            }

            $user["status"] = $status;
            $user["updated_at"] = now_iso();
            $found = true;
            break;
        }
        unset($user);

        if (!$found) {
            throw new RuntimeException(t("users.not_found"));
        }

        self::assertAtLeastOneActive($users);
        JsonStorage::write(self::usersFile(), $users);
    }

    /**
     * Function rawUsers
     *
     * @return array<int, mixed>
     */
    private static function rawUsers(): array {
        $users = JsonStorage::read(self::usersFile(), []);
        return is_array($users) ? array_values($users) : [];
    }

    /**
     * Function normalizeUser
     *
     * @param array $user
     * @return array<string, mixed>
     */
    private static function normalizeUser(array $user): array {
        $username = self::normalizeUsername((string) ($user["username"] ?? ""));
        $name = trim((string) ($user["name"] ?? ""));
        $status = self::normalizeStatus($user["status"] ?? self::STATUS_ACTIVE);

        $user["username"] = $username;
        $user["name"] = $name != "" ? $name : $username;
        $user["status"] = $status;
        $user["status_label"] = self::statusLabel($status);
        $user["is_active"] = $status == self::STATUS_ACTIVE;
        $user["is_disabled"] = $status == self::STATUS_DISABLED;

        return $user;
    }

    /**
     * Function validateUserData
     *
     * @param string $username
     * @param string $name
     * @param string $password
     * @param bool $passwordrequired
     * @return void
     */
    private static function validateUserData(string $username, string $name, string $password, bool $passwordrequired): void {
        if (!preg_match('/^[a-z][a-z0-9._-]{2,31}$/', $username)) {
            throw new RuntimeException(t("profile.username_invalid"));
        }

        if ($name == "") {
            throw new RuntimeException(t("profile.name_required"));
        }

        if ($passwordrequired && $password == "") {
            throw new RuntimeException(t("users.password_required"));
        }

        if ($password != "" && strlen($password) < 6) {
            throw new RuntimeException(t("profile.password_short"));
        }

        if ($password != "" && (hash_equals($password, "123456") || hash_equals($password, "admin"))) {
            throw new RuntimeException(t("profile.password_cannot_be_default"));
        }
    }

    /**
     * Function assertCurrentUserCanReceiveStatus
     *
     * @param string $username
     * @param string $status
     * @return void
     */
    private static function assertCurrentUserCanReceiveStatus(string $username, string $status): void {
        if ($status != self::STATUS_DISABLED) {
            return;
        }

        $currentuser = Auth::user();
        $currentusername = self::normalizeUsername((string) ($currentuser["username"] ?? ""));
        if ($currentusername != "" && $currentusername == $username) {
            throw new RuntimeException(t("users.cannot_disable_current"));
        }
    }

    /**
     * Function assertAtLeastOneActive
     *
     * @param array $users
     * @return void
     */
    private static function assertAtLeastOneActive(array $users): void {
        foreach ($users as $user) {
            if (is_array($user) && self::isActive($user)) {
                return;
            }
        }

        throw new RuntimeException(t("users.at_least_one_active"));
    }
}

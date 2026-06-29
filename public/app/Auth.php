<?php
// Authentication helper. Plain text passwords are automatically upgraded to password_hash().
namespace app;

use RuntimeException;

/**
 * Class Auth
 */
class Auth {
    /**
     * Function user
     *
     * @return array|null
     */
    public static function user(): ?array {
        return $_SESSION["user"] ?? null;
    }

    /**
     * Function check
     *
     * @return bool
     */
    public static function check(): bool {
        return !empty($_SESSION["user"]);
    }


    /**
     * Function isDefaultPassword
     *
     * Checks plain text or password_hash values against the default panel password.
     *
     * @param string $stored
     * @return bool
     */
    public static function isDefaultPassword(string $stored): bool {
        if ($stored === '') {
            return false;
        }

        $info = password_get_info($stored);
        if (!empty($info["algo"])) {
            return password_verify('123456', $stored);
        }

        return hash_equals($stored, '123456');
    }

    /**
     * Function currentUserRecord
     *
     * @return array|null
     */
    public static function currentUserRecord(): ?array {
        $sessionuser = self::user();
        if (!$sessionuser) {
            return null;
        }

        $username = $sessionuser["username"] ?? '';
        if ($username == '') {
            return null;
        }

        $users = JsonStorage::read(app_config_path('/data/users.json'));
        foreach ($users as $user) {
            if (($user["username"] ?? '') == $username) {
                return is_array($user) ? $user : null;
            }
        }

        return null;
    }

    /**
     * Function requiresPasswordChange
     *
     * @return bool
     */
    public static function requiresPasswordChange(): bool {
        $user = self::currentUserRecord();
        if (!$user) {
            return false;
        }

        return self::isDefaultPassword((string) ($user["password"] ?? ''));
    }

    /**
     * Function updateCurrentUser
     *
     * @param string $username
     * @param string $name
     * @param string|null $password
     * @return void
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function updateCurrentUser(string $username, string $name, ?string $password = null): void {
        $sessionuser = self::user();
        if (!$sessionuser) {
            throw new RuntimeException('User is not logged in.');
        }

        $oldusername = $sessionuser["username"] ?? '';
        $usersfile = app_config_path('/data/users.json');
        $users = JsonStorage::read($usersfile);
        $found = false;

        foreach ($users as &$user) {
            if (($user["username"] ?? '') != $oldusername) {
                if (($user["username"] ?? '') == $username) {
                    throw new RuntimeException(t('profile.username_already_exists'));
                }
                continue;
            }

            $user["username"] = $username;
            $user["name"] = $name;
            $user["updated_at"] = now_iso();

            if ($password !== null && $password !== '') {
                $user["password"] = password_hash($password, PASSWORD_DEFAULT);
                $user["password_changed_at"] = now_iso();
            }

            $found = true;
            break;
        }
        unset($user);

        if (!$found) {
            throw new RuntimeException(t('profile.current_user_not_found'));
        }

        JsonStorage::write($usersfile, $users);
        $_SESSION["user"] = [
            'username' => $username,
            'name' => $name,
        ];
        csrf_token();
    }

    /**
     * Function requireLogin
     *
     * @return void
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            redirect_to('/login.php');
        }
    }

    /**
     * Function attempt
     *
     * @param string $username
     * @param string $password
     * @return bool
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function attempt(string $username, string $password): bool {
        $usersfile = app_config_path("/data/users.json");
        $users = JsonStorage::read($usersfile);
        $changed = false;
        $loggeduser = null;

        foreach ($users as &$user) {
            if (($user["username"] ?? '') != $username) {
                continue;
            }

            $stored = $user["password"] ?? '';
            $info = password_get_info($stored);

            if (!empty($info["algo"])) {
                $valid = password_verify($password, $stored);
            } else {
                $valid = hash_equals($stored, $password);
                if ($valid) {
                    $user["password"] = password_hash($password, PASSWORD_DEFAULT);
                    $user["password_upgraded_at"] = now_iso();
                    $changed = true;
                }
            }

            if ($valid) {
                $loggeduser = [
                    'username' => $user["username"],
                    'name' => $user["name"] ?? $user["username"],
                ];
            }
            break;
        }
        unset($user);

        if ($changed) {
            JsonStorage::write($usersfile, $users);
        }

        if ($loggeduser) {
            session_regenerate_id(true);
            $_SESSION["user"] = $loggeduser;
            csrf_token();
            return true;
        }

        return false;
    }

    /**
     * Function logout
     *
     * @return void
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}

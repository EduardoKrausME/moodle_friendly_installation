<?php
// Authentication helper. Plain text passwords are automatically upgraded to password_hash().
namespace app;

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
        return $_SESSION['user'] ?? null;
    }

    /**
     * Function check
     *
     * @return bool
     */
    public static function check(): bool {
        return !empty($_SESSION['user']);
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
     */
    public static function attempt(string $username, string $password): bool {
        $usersfile = app_config_path("/data/users.json");
        $users = JsonStorage::read($usersfile);
        $changed = false;
        $loggeduser = null;

        foreach ($users as &$user) {
            if (($user['username'] ?? '') != $username) {
                continue;
            }

            $stored = $user['password'] ?? '';
            $info = password_get_info($stored);
            $valid = false;

            if (!empty($info['algo'])) {
                $valid = password_verify($password, $stored);
            } else {
                $valid = hash_equals($stored, $password);
                if ($valid) {
                    $user['password'] = password_hash($password, PASSWORD_DEFAULT);
                    $user['password_upgraded_at'] = now_iso();
                    $changed = true;
                }
            }

            if ($valid) {
                $loggeduser = [
                    'username' => $user['username'],
                    'name' => $user['name'] ?? $user['username'],
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
            $_SESSION['user'] = $loggeduser;
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
                session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}

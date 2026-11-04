<?php
// Authentication helper. Plain text passwords are automatically upgraded to password_hash().
namespace app;

use RuntimeException;

/**
 * Class Auth
 */
class Auth {
    /** @var string|null */
    private static ?string $lastLoginError = null;

    /**
     * Function loginError
     *
     * @return string|null
     */
    public static function loginError(): ?string {
        return self::$lastLoginError;
    }

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
        if ($stored === "") {
            return false;
        }

        return self::passwordMatches($stored, "123456") || self::passwordMatches($stored, "admin");
    }

    /**
     * Function hasInitialAdminCredentials
     *
     * @return bool
     */
    public static function hasInitialAdminCredentials(): bool {
        $user = UserManager::get("admin");
        if (!$user || ($user["username"] ?? "") !== "admin") {
            return false;
        }

        return self::passwordMatches($user["password"], "admin");
    }

    /**
     * Function completeInitialAdminSetup
     *
     * Changes the initial admin password without creating an authenticated
     * session. The user must sign in normally after the welcome screen.
     *
     * @param string $password
     * @return array<string, string>
     * @throws \DateMalformedStringException
     * @throws \Random\RandomException
     */
    public static function completeInitialAdminSetup(string $password): array {
        if (!self::hasInitialAdminCredentials()) {
            throw new RuntimeException(t("onboarding.initial_state_changed"));
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(t("onboarding.password_short"));
        }

        if (hash_equals($password, "123456") || hash_equals($password, "admin")) {
            throw new RuntimeException(t("onboarding.password_cannot_be_default"));
        }

        $usersfile = app_config_path("/data/users.json");
        $users = JsonStorage::read($usersfile);
        $found = false;
        $name = "Administrador";

        foreach ($users as &$user) {
            if (!is_array($user)
                || UserManager::normalizeUsername($user["username"]) !== "admin"
                || !self::passwordMatches($user["password"], "admin")
            ) {
                continue;
            }

            $name = trim($user["name"]) ?: "Administrador";
            $user["password"] = password_hash($password, PASSWORD_DEFAULT);
            $user["updated_at"] = now_iso();
            $user["password_changed_at"] = now_iso();
            $found = true;
            break;
        }
        unset($user);

        if (!$found) {
            throw new RuntimeException(t("onboarding.initial_state_changed"));
        }

        JsonStorage::write($usersfile, $users);

        return [
            "username" => "admin",
            "name" => $name,
        ];
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

        $username = $sessionuser["username"] ?? "";
        if ($username == "") {
            return null;
        }

        return UserManager::get($username);
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

        return self::isDefaultPassword($user["password"]);
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
            throw new RuntimeException("User is not logged in.");
        }

        $oldusername = $sessionuser["username"] ?? "";
        $usersfile = app_config_path("/data/users.json");
        $users = JsonStorage::read($usersfile);
        $found = false;

        foreach ($users as &$user) {
            if (($user["username"] ?? "") != $oldusername) {
                if (($user["username"] ?? "") == $username) {
                    throw new RuntimeException(t("profile.username_already_exists"));
                }
                continue;
            }

            $user["username"] = $username;
            $user["name"] = $name;
            $user["updated_at"] = now_iso();

            if ($password !== null && $password !== "") {
                $user["password"] = password_hash($password, PASSWORD_DEFAULT);
                $user["password_changed_at"] = now_iso();
            }

            $found = true;
            break;
        }
        unset($user);

        if (!$found) {
            throw new RuntimeException(t("profile.current_user_not_found"));
        }

        JsonStorage::write($usersfile, $users);
        $_SESSION["user"] = [
            "username" => $username,
            "name" => $name,
        ];
        csrf_token();
    }

    /**
     * Function redirectTarget
     *
     * Only local absolute paths are accepted as post-login destinations.
     *
     * @param mixed $redirectTo
     * @return string
     */
    public static function redirectTarget(mixed $redirectTo): string {
        if (!is_string($redirectTo)
            || $redirectTo === ""
            || !str_starts_with($redirectTo, "/")
            || str_starts_with($redirectTo, "//")
            || str_contains($redirectTo, "\\")
            || preg_match('/[\x00-\x1F\x7F]/', $redirectTo)
        ) {
            return "/";
        }

        $parts = parse_url($redirectTo);
        if ($parts === false
            || isset($parts["scheme"])
            || isset($parts["host"])
            || isset($parts["user"])
            || isset($parts["pass"])
            || isset($parts["fragment"])
        ) {
            return "/";
        }

        return $redirectTo;
    }

    /**
     * Function renderLogin
     *
     * Renders the shared login template and ends the current request.
     *
     * @param string $redirectTo
     * @param string|null $error
     * @param string $username
     * @return never
     */
    public static function renderLogin(string $redirectTo = "/", ?string $error = null, string $username = ""): never {
        header("Cache-Control: no-store");
        render_header(t("login.title"));
        echo render_app_template("page/login", [
            "has_error" => $error !== null,
            "error" => $error,
            "username" => $username,
            "redirect_to" => self::redirectTarget($redirectTo),
        ]);
        render_footer();
        exit;
    }

    /**
     * Function requireLogin
     *
     * Renders the login form at the original URL for HTML pages. Non-page
     * endpoints can request a 401 JSON or plain-text response instead.
     *
     * @param string $responseFormat
     * @return void
     */
    public static function requireLogin(string $responseFormat = "html"): void {
        if (self::check()) {
            $user = self::currentUserRecord();
            if ($user && UserManager::isActive($user)) {
                return;
            }

            self::logout();
        }

        if ($responseFormat !== "html") {
            http_response_code(401);
            header("Cache-Control: no-store");

            if ($responseFormat === "json") {
                header("Content-Type: application/json; charset=utf-8");
                echo json_encode([
                    "ok" => false,
                    "success" => false,
                    "error" => "authentication_required",
                ]);
            } else {
                header("Content-Type: text/plain; charset=utf-8");
                echo "Authentication required.";
            }
            exit;
        }

        http_response_code(401);
        self::renderLogin(self::redirectTarget($_SERVER["REQUEST_URI"] ?? "/"));
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
        self::$lastLoginError = null;
        $username = UserManager::normalizeUsername($username);
        $usersfile = app_config_path("/data/users.json");
        $users = JsonStorage::read($usersfile);
        $changed = false;
        $loggeduser = null;

        foreach ($users as &$user) {
            if ($user["username"] != $username) {
                continue;
            }

            $info = password_get_info($user["password"]);

            if (!empty($info["algo"])) {
                $valid = password_verify($password, $user["password"]);
            } else {
                $valid = hash_equals($user["password"], $password);
                if ($valid) {
                    $user["password"] = password_hash($password, PASSWORD_DEFAULT);
                    $user["password_upgraded_at"] = now_iso();
                    $changed = true;
                }
            }

            if ($valid) {
                if (!UserManager::isActive(is_array($user) ? $user : [])) {
                    self::$lastLoginError = t("login.user_disabled");
                    break;
                }

                $loggeduser = [
                    "username" => $user["username"],
                    "name" => $user["name"] ?? $user["username"],
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
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), "", time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Function passwordMatches
     *
     * @param string $stored
     * @param string $plain
     * @return bool
     */
    private static function passwordMatches(string $stored, string $plain): bool {
        if ($stored === "") {
            return false;
        }

        $info = password_get_info($stored);
        if (!empty($info["algo"])) {
            return password_verify($plain, $stored);
        }

        return hash_equals($stored, $plain);
    }
}

<?php

use app\Auth;
use app\PanelConfigManager;

require_once __DIR__ . "/app/bootstrap.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$hasinitialstate = PanelConfigManager::requiresInitialSetup()
    && Auth::hasInitialAdminCredentials();
if (!$hasinitialstate) {
    if (Auth::check()) {
        redirect_to("/");
    }
    redirect_to("/login.php");
}

$publicip = PanelConfigManager::detect_public_ipv4();
$detectedbaseurl = PanelConfigManager::detectRequestBaseUrl();
if ($detectedbaseurl === "" && $publicip !== "") {
    $detectedbaseurl = "http://{$publicip}";
}

$requestedstep = (int) ($_GET["step"] ?? 1);
$step = $requestedstep === 2 ? 2 : 1;
$sessionbaseurl = trim((string) ($_SESSION["onboarding"]["base_url"] ?? ""));
$baseurl = $sessionbaseurl !== "" ? $sessionbaseurl : $detectedbaseurl;
$errors = [];
$setupcompleted = false;
$nextloginusername = "admin";
$nextloginpassword = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();

    $action = (string) ($_POST["action"] ?? "");
    if ($action === "save_url") {
        $step = 1;
        $baseurl = trim((string) ($_POST["base_url"] ?? ""));
        try {
            $baseurl = PanelConfigManager::normalizeBaseUrl($baseurl);
            $_SESSION["onboarding"]["base_url"] = $baseurl;
            redirect_to("/onboarding.php?step=2");
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    } else if ($action === "finish") {
        $step = 2;
        if ($sessionbaseurl === "") {
            redirect_to("/onboarding.php?step=1");
        }

        $baseurl = $sessionbaseurl;
        $password = $_POST["password"] ?? "";

        if (strlen($password) < 6) {
            $errors[] = t("onboarding.password_short");
        }

        if ($password === "123456" || $password === "admin") {
            $errors[] = t("onboarding.password_cannot_be_default");
        }

        if (empty($errors)) {
            $previoussavedconfig = PanelConfigManager::savedConfig();
            try {
                PanelConfigManager::saveBaseUrl($baseurl);

                try {
                    $account = Auth::completeInitialAdminSetup($password);
                } catch (Throwable $exception) {
                    PanelConfigManager::save($previoussavedconfig);
                    throw $exception;
                }

                unset($_SESSION["onboarding"], $_SESSION["user"]);
                session_regenerate_id(true);

                $setupcompleted = true;
                $nextloginusername = (string) ($account["username"] ?? "admin");
                $nextloginpassword = $password;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    } else {
        $errors[] = t("onboarding.invalid_request");
    }
}

if (!$setupcompleted && $step === 2 && $sessionbaseurl === "") {
    redirect_to("/onboarding.php?step=1");
}

$host = PanelConfigManager::baseUrlHost($baseurl);
$isipurl = PanelConfigManager::isIpBaseUrl($baseurl);
$ishttpsurl = PanelConfigManager::isHttpsBaseUrl($baseurl);
$hasdomainurl = $host !== "" && !$isipurl;
$dnsdomain = $hasdomainurl ? $host : "painel.seudominio.com.br";

render_header(t("onboarding.title"));
echo render_app_template("page/onboarding", [
    "setup_completed" => $setupcompleted,
    "show_wizard" => !$setupcompleted,
    "show_step_one" => !$setupcompleted && $step === 1,
    "show_step_two" => !$setupcompleted && $step === 2,
    "step_one_active" => !$setupcompleted && $step === 1,
    "step_one_complete" => !$setupcompleted && $step === 2,
    "step_two_active" => !$setupcompleted && $step === 2,
    "csrf_token" => csrf_token(),
    "base_url" => $baseurl,
    "detected_base_url" => $detectedbaseurl,
    "public_ip" => $publicip,
    "has_public_ip" => $publicip !== "",
    "has_errors" => !empty($errors),
    "errors" => array_map(static function(string $error): array {
        return ["message" => $error];
    }, $errors),
    "is_ip_url" => $isipurl,
    "has_domain_url" => $hasdomainurl,
    "is_https_url" => $ishttpsurl,
    "needs_https" => $hasdomainurl && !$ishttpsurl,
    "dns_domain" => $dnsdomain,
    "certbot_command" => "sudo certbot --nginx -d {$dnsdomain} --redirect",
    "dns_check_command" => "dig +short {$dnsdomain}",
    "next_login_username" => $nextloginusername,
    "next_login_password" => $nextloginpassword,
]);
render_footer();

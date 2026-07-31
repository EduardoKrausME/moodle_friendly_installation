<?php

use app\AppUpdater;
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
$step = in_array($requestedstep, [1, 2, 3], true) ? $requestedstep : 1;
$sessionbaseurl = trim((string) ($_SESSION["onboarding"]["base_url"] ?? ""));
$baseurl = $sessionbaseurl !== "" ? $sessionbaseurl : $detectedbaseurl;
$errors = [];
$setupcompleted = false;
$nextloginusername = "admin";
$nextloginpassword = "";
$updatecheckerror = "";
$updatechecked = false;
$updateavailable = false;
$updatestate = AppUpdater::state();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validate_csrf();

    $action = $_POST["action"];
    if ($action === "save_url") {
        $step = 1;
        $baseurl = trim($_POST["base_url"]);
        try {
            $baseurl = PanelConfigManager::normalizeBaseUrl($baseurl);
            $_SESSION["onboarding"]["base_url"] = $baseurl;
            unset($_SESSION["onboarding"]["update_check_complete"]);
            redirect_to("/onboarding.php?step=2");
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    } else if ($action === "continue_after_update_check") {
        $step = 2;
        if ($sessionbaseurl === "") {
            redirect_to("/onboarding.php?step=1");
        }

        $_SESSION["onboarding"]["update_check_complete"] = true;
        redirect_to("/onboarding.php?step=3");
    } else if ($action === "finish") {
        $step = 3;
        if ($sessionbaseurl === "") {
            redirect_to("/onboarding.php?step=1");
        }
        if (empty($_SESSION["onboarding"]["update_check_complete"])) {
            redirect_to("/onboarding.php?step=2");
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

if (!$setupcompleted && $step > 1 && $sessionbaseurl === "") {
    redirect_to("/onboarding.php?step=1");
}
if (!$setupcompleted && $step === 3 && empty($_SESSION["onboarding"]["update_check_complete"])) {
    redirect_to("/onboarding.php?step=2");
}

if (!$setupcompleted && $step === 2) {
    try {
        $updatecheck = AppUpdater::check();
        $updatestate = $updatecheck["state"];
        $updatechecked = true;
        $updateavailable = !empty($updatecheck["update_available"]);
    } catch (Throwable $exception) {
        $updatecheckerror = $exception->getMessage();
    }
}

$installedtag = trim((string) ($updatestate["installed_tag"] ?? ""));
$latesttag = trim((string) ($updatestate["latest_tag"] ?? ""));
$latesthtmlurl = trim((string) ($updatestate["latest_html_url"] ?? ""));

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
    "show_step_three" => !$setupcompleted && $step === 3,
    "step_one_active" => !$setupcompleted && $step === 1,
    "step_one_complete" => !$setupcompleted && $step > 1,
    "step_two_active" => !$setupcompleted && $step === 2,
    "step_two_complete" => !$setupcompleted && $step === 3,
    "step_three_active" => !$setupcompleted && $step === 3,
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
    "update_available" => $updatechecked && $updateavailable,
    "update_is_current" => $updatechecked && !$updateavailable,
    "update_check_failed" => $updatecheckerror !== "",
    "update_check_error" => $updatecheckerror,
    "installed_version" => $installedtag,
    "latest_version" => $latesttag,
    "has_latest_version" => $updatechecked && $latesttag !== "",
    "latest_release_url" => $latesthtmlurl,
    "has_latest_release_url" => $updatechecked && $latesthtmlurl !== "",
    "next_login_username" => $nextloginusername,
    "next_login_password" => $nextloginpassword,
]);
render_footer();

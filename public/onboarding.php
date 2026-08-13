<?php

use app\AppUpdater;
use app\Auth;
use app\PanelConfigManager;
use app\PanelDomainManager;

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
        $baseurl = trim((string) ($_POST["base_url"] ?? ""));
        try {
            $baseurl = PanelConfigManager::normalizeBaseUrl($baseurl);
            if (PanelConfigManager::isIpBaseUrl($baseurl)) {
                $host = PanelConfigManager::baseUrlHost($baseurl);
                $ipauthority = str_contains($host, ":") ? "[{$host}]" : $host;
                $baseurl = "http://{$ipauthority}";
            } else {
                $jobid = trim((string) ($_POST["panel_domain_job_id"] ?? ""));
                if (!PanelDomainManager::isCompletedFor($jobid, $baseurl)) {
                    throw new RuntimeException(PanelDomainManager::message("not_ready"));
                }
                $baseurl = PanelDomainManager::canonicalDomainUrl($baseurl);
                $_SESSION["onboarding"]["panel_domain_job_id"] = $jobid;
            }

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

        $updaterequested = !empty($updatestate["update_requested"]);
        $installing = ($updatestate["update_status"] ?? "") === "installing";
        if ($updateavailable && !$updaterequested && !$installing) {
            $updaterequest = AppUpdater::requestInstall();
            $updatestate = $updaterequest["state"] ?? AppUpdater::state();
        }
    } catch (Throwable $exception) {
        $updatecheckerror = $exception->getMessage();
    }
}

$installedtag = trim((string) ($updatestate["installed_tag"] ?? ""));
$latesttag = trim((string) ($updatestate["latest_tag"] ?? ""));
$latesthtmlurl = trim((string) ($updatestate["latest_html_url"] ?? ""));

$host = PanelConfigManager::baseUrlHost($baseurl);
$isipurl = PanelConfigManager::isIpBaseUrl($baseurl);
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
    "dns_domain" => $dnsdomain,
    "panel_domain_intro" => PanelDomainManager::message("automatic_intro"),
    "panel_domain_ip_note" => PanelDomainManager::message("ip_access_note"),
    "panel_domain_checklist_title" => PanelDomainManager::message("checklist_title"),
    "panel_domain_check_request_received" => PanelDomainManager::message("check_request_received"),
    "panel_domain_check_public_ip" => PanelDomainManager::message("check_public_ip"),
    "panel_domain_check_dns" => PanelDomainManager::message("check_dns"),
    "panel_domain_check_webserver_config" => PanelDomainManager::message("check_webserver_config"),
    "panel_domain_check_webserver_test" => PanelDomainManager::message("check_webserver_test"),
    "panel_domain_check_certificate" => PanelDomainManager::message("check_certificate"),
    "panel_domain_check_activation" => PanelDomainManager::message("check_activation"),
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

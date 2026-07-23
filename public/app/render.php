<?php
// Small HTML rendering helpers rendered with php-mustache.
use app\AppUpdater;
use app\Auth;
use app\I18n;
use Mustache\Engine;
use Mustache\Loader\FilesystemLoader;

/**
 * Function render_header
 *
 * @param string $title
 * @return void
 */
function render_header(string $title): void {
    $appname = app_config("app_name");
    $user = Auth::user();
    $hasuser = (bool) $user;
    $currentlanguage = I18n::currentMeta();
    $hasupdate = $hasuser && AppUpdater::hasCachedUpdate();
    $bodyclasses = [$hasuser ? "has-sidebar" : "auth-page"];
    if (basename((string) ($_SERVER["SCRIPT_NAME"] ?? "")) === "onboarding.php") {
        $bodyclasses[] = "onboarding-page";
    }
    if ($hasupdate) {
        $bodyclasses[] = "has-update";
    }

    $mustachedata = [
        "html_lang" => I18n::htmlLang(),
        "page_title" => "{$title} - {$appname}",
        "app_name" => $appname,
        "body_class" => implode(" ", $bodyclasses),
        "has_user" => $hasuser,
        "user_name" => $user["name"] ?? $user["username"] ?? "Administrador",
        "navigation" => render_navigation_items(),
        "current_language_name" => $currentlanguage["native_name"] ?? $currentlanguage["name"] ?? I18n::current(),
    ];
    echo render_app_template("layout/header", $mustachedata);
}

/**
 * Function render_navigation_items
 *
 * @return array<int, array<string, string>>
 */
function render_navigation_items(): array {
    $current = basename(($_SERVER["SCRIPT_NAME"] ?? ""));

    $items = [];

    $items[] = [
        "url" => "/",
        "label" => t("index.title"),
        "icon" => "S",
        "active_on" => ["index.php", "details.php"],
    ];
    $items[] = [
        "url" => "/app_manager.php",
        "label" => t("app_manager.list_heading"),
        "icon" => "A",
        "active_on" => ["app_manager.php"],
    ];
    if ($current == "install.php") {
        $items[] = [
            "url" => "/install.php",
            "label" => t("navigation.install_moodle"),
            "icon" => "+",
            "active_on" => ["install.php"],
        ];
    }
    $items[] = [
        "url" => "/jobs.php",
        "label" => t("jobs.title"),
        "icon" => "F",
        "active_on" => ["jobs.php"],
    ];
    $items[] = [
        "url" => "/users.php",
        "label" => t("users.title"),
        "icon" => "U",
        "active_on" => ["users.php"],
    ];
    $items[] = [
        "url" => "/configuration.php",
        "label" => t("configuration.title"),
        "icon" => "C",
        "active_on" => ["configuration.php"],
    ];
    if (AppUpdater::hasCachedUpdate() || $current == "update.php") {
        $items[] = [
            "url" => "/update.php",
            "label" => t("updater.title"),
            "icon" => "↑",
            "active_on" => ["update.php"],
            "extra_class" => AppUpdater::hasCachedUpdate() ? "update-link" : "",
        ];
    }
    $items[] = [
        "url" => "/logout.php",
        "label" => t("navigation.logout"),
        "icon" => "×",
        "active_on" => [],
        "extra_class" => "logout-link",
    ];

    foreach ($items as $index => $item) {
        $classes = ["side-nav-link"];
        if (in_array($current, $item["active_on"], true)) {
            $classes[] = "is-active";
        }
        if (!empty($item["extra_class"])) {
            $classes[] = $item["extra_class"];
        }

        $items[$index]["class"] = implode(" ", $classes);
        unset($items[$index]["active_on"], $items[$index]["extra_class"]);
    }

    return $items;
}

/**
 * Function render_footer
 *
 * @return void
 */
function render_footer(): void {
    if (Auth::user()) {
        echo "</main></div></body></html>";
        return;
    }

    echo "</main></body></html>";
}

/**
 * Function status_badge
 *
 * @param string $status
 * @param $label
 * @return string
 */
function status_badge(string $status, $label = null): string {
    if ($status == "waiting_dns") {
        $label = t("status.waiting_dns");
    } else if ($status == "running") {
        $label = t("status.running");
    } else if ($status == "failed") {
        $label = t("status.failed");
    } else if ($status == "error") {
        $label = t("status.error");
    } else if ($status == "active") {
        $label = t("status.active");
    } else if ($status == "done") {
        $label = t("status.done");
    } else if (!$label) {
        $label = $status;
    }

    $class = match ($status) {
        "done", "active", "ok" => "ok",
        "running" => "running",
        "waiting_dns", "warning" => "warning",
        "failed", "error", "danger" => "danger",
        default => "muted",
    };

    return render_app_template("status-badge", [
        "class" => $class,
        "label" => $label,
    ]);
}

/**
 * Function flash_message
 *
 * @return string|null
 */
function flash_message(): ?string {
    if (empty($_SESSION["flash"])) {
        return null;
    }
    $message = $_SESSION["flash"];
    unset($_SESSION["flash"]);
    return $message;
}

/**
 * Function render_app_template
 *
 * @param string $template
 * @param array $context
 * @return string
 */
function render_app_template(string $template, array $context = []): string {
    $language = I18n::currentMeta();
    $basecontext = [
        "i18n" => I18n::strings(),
        "language" => [
            "label" => t("language.label"),
            "change" => t("language.change"),
            "current_code" => I18n::current(),
            "current_name" => $language["name"] ?? I18n::current(),
            "current_native_name" => $language["native_name"] ?? $language["name"] ?? I18n::current(),
            "current_flag" => $language["flag"] ?? "",
            "items" => I18n::languagesForSelector(),
        ],
    ];

    return render_mustache_engine()->render($template, array_replace_recursive($basecontext, $context));
}

/**
 * Function render_app_template
 *
 * @return \Mustache\Engine
 */
function render_mustache_engine(): Engine {
    static $engine = null;

    if ($engine instanceof Engine) {
        return $engine;
    }

    $engine = new Engine([
        "loader" => new FilesystemLoader(__DIR__ . "/templates"),
        "escape" => static function($value): string {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        },
    ]);

    return $engine;
}

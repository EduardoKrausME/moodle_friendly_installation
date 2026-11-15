<?php

/**
 * Return a string query parameter.
 *
 * @param string $name
 * @return string
 */
function queryString(string $name): string {
    $value = $_GET[$name] ?? "";
    return is_string($value) ? trim($value) : "";
}

/**
 * Replace one data attribute on an element identified by its ID.
 *
 * @param string $content
 * @param string $elementid
 * @param string $attribute
 * @param string $value
 * @return string
 */
function replaceDataAttribute(
    string $content,
    string $elementid,
    string $attribute,
    string $value
): string {
    $escapedvalue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $elementidpattern = preg_quote($elementid, "/");
    $attributepattern = preg_quote($attribute, "/");
    $pattern = '/(<[^>]*\bid=["\']' . $elementidpattern . '["\'][^>]*\b'
        . $attributepattern . '\s*=\s*)(["\'])(.*?)(\2)/is';

    $updated = preg_replace_callback(
        $pattern,
        static fn(array $matches): string => $matches[1] . $matches[2] . $escapedvalue . $matches[4],
        $content,
        1
    );

    return is_string($updated) ? $updated : $content;
}

/**
 * Display the failing Moodle app configuration inside the preview iframe.
 *
 * @param array $configtest
 * @param string $domain
 * @return never
 */
function previewConfigurationError(array $configtest, string $domain): never {
    $escape = static fn(mixed $value): string =>
        htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $haserror = !empty($configtest["has_error"]);
    $warnings = is_array($configtest["warnings"] ?? null) ? $configtest["warnings"] : [];
    $title = t($haserror ? "app_preview.test_failed_heading" : "app_preview.missing_heading");
    $intro = t($haserror ? "app_preview.test_failed_intro" : "app_preview.missing_intro");
    $managerurl = "/app_manager.php?domain=" . rawurlencode($domain);

    http_response_code($haserror ? 503 : 422);
    header("Content-Type: text/html; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer");
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");

    ?>
    <!doctype html>
    <html lang="<?php echo $escape(\app\I18n::htmlLang()); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo $escape($title); ?></title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0;
                padding: 22px 14px;
                min-height: 100vh;
                background: #f1f5f9;
                color: #172033;
                font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
            }
            main {
                width: 100%;
                max-width: 540px;
                margin: auto;
                padding: 20px;
                border: 1px solid #fcd34d;
                border-radius: 18px;
                background: #fff;
                box-shadow: 0 8px 25px rgba(15, 23, 42, .08);
                overflow-wrap: anywhere;
            }
            h1 { margin: 0 0 10px; font-size: 21px; line-height: 1.25; }
            p { margin: 0 0 16px; color: #475569; }
            ul { padding: 0; margin: 0 0 18px; list-style: none; }
            li { padding: 12px 0; border-top: 1px solid #e2e8f0; }
            li strong { display: block; margin-bottom: 4px; }
            li span { display: block; color: #475569; }
            code { color: #9a3412; overflow-wrap: anywhere; }
            a { color: #1d4ed8; font-weight: 650; }
            li a { display: inline-block; margin-top: 7px; }
            .action {
                display: block;
                padding: 12px;
                border-radius: 10px;
                background: #2563eb;
                color: #fff;
                text-align: center;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
    <main role="alert">
        <h1><?php echo $escape($title); ?></h1>
        <p><?php echo $escape($intro); ?></p>
        <?php if ($haserror): ?>
            <p><?php echo $escape($configtest["error"] ?? ""); ?></p>
        <?php elseif ($warnings !== []): ?>
            <ul>
                <?php foreach ($warnings as $warning): ?>
                    <?php if (!is_array($warning)) { continue; } ?>
                    <li>
                        <strong>
                            <?php echo $escape(t("app_preview.missing_item")); ?>
                            <code><?php echo $escape($warning["key"] ?? ""); ?></code>
                        </strong>
                        <span><?php echo $escape($warning["description"] ?? ""); ?></span>
                        <span><?php echo $escape($warning["message"] ?? ""); ?></span>
                        <?php if (!empty($warning["has_edit_url"]) && !empty($warning["edit_url"])): ?>
                            <a href="<?php echo $escape($warning["edit_url"]); ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?php echo $escape(t("app_preview.edit_in_moodle")); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="action" href="<?php echo $escape($managerurl); ?>"
           target="_blank" rel="noopener noreferrer">
            <?php echo $escape(t("app_preview.open_settings")); ?>
        </a>
    </main>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Load the package name and Moodle URL for the preview.
 *
 * @return array{package_name: string, wwwroot_web: string}
 */
function previewSettings(): array {
    $packagename = queryString("package_name");
    $wwwroot = queryString("wwwroot_web");
    $domain = queryString("domain");
    if ($domain !== "") {
        require_once dirname(__DIR__) . "/app/bootstrap.php";

        \app\Auth::requireLogin();
        $site = \app\SiteManager::details($domain);
        if ($site === null) {
            http_response_code(404);
            exit("Site not found.");
        }

        $moodleconfigtest = \app\AppManager::moodleConfigTest($domain);
        if (empty($moodleconfigtest["valid"])) {
            previewConfigurationError($moodleconfigtest, $domain);
        }

        $settings = \app\AppManager::getSettings($site);
        if ($packagename === "") {
            $packagename = (string) ($settings["package_name"] ?? "");
        }
        if ($wwwroot === "") {
            $wwwroot = (string) ($site["url"] ?? "");
        }
    }

    return [
        "package_name" => $packagename,
        "wwwroot_web" => $wwwroot,
    ];
}

/**
 * Return the response content type for an asset.
 *
 * @param string $file
 * @return string
 */
function assetContentType(string $file): string {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        "avif" => "image/avif",
        "css" => "text/css; charset=UTF-8",
        "cur" => "image/x-icon",
        "eot" => "application/vnd.ms-fontobject",
        "gif" => "image/gif",
        "htm" => "text/html; charset=UTF-8",
        "html" => "text/html; charset=UTF-8",
        "ico" => "image/x-icon",
        "jpeg" => "image/jpeg",
        "jpg" => "image/jpeg",
        "js" => "text/javascript; charset=UTF-8",
        "json" => "application/json; charset=UTF-8",
        "map" => "application/json; charset=UTF-8",
        "mp3" => "audio/mpeg",
        "mp4" => "video/mp4",
        "ogg" => "audio/ogg",
        "otf" => "font/otf",
        "pdf" => "application/pdf",
        "png" => "image/png",
        "properties" => "text/plain; charset=UTF-8",
        "svg" => "image/svg+xml",
        "ttf" => "font/ttf",
        "txt" => "text/plain; charset=UTF-8",
        "wasm" => "application/wasm",
        "webm" => "video/webm",
        "webp" => "image/webp",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
        "xml" => "application/xml; charset=UTF-8",
    ];

    if (isset($types[$extension])) {
        return $types[$extension];
    }

    if (class_exists("finfo")) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedtype = $finfo->file($file);
        if (is_string($detectedtype) && $detectedtype !== "") {
            return $detectedtype;
        }
    }

    return "application/octet-stream";
}

$wwwroot = realpath(dirname(__DIR__, 2) . "/app-MoodleMobile-V2/www");
if ($wwwroot === false || !is_dir($wwwroot)) {
    http_response_code(500);
    exit("The app-MoodleMobile-V2/www directory was not found.");
}

$asset = queryString("asset");
if ($asset === "") {
    $asset = "index.html";
}

$asset = rawurldecode(str_replace("\\", "/", $asset));
$asset = ltrim($asset, "/");
$file = realpath($wwwroot . DIRECTORY_SEPARATOR . $asset);
$wwwrootprefix = $wwwroot . DIRECTORY_SEPARATOR;

if (
    $file === false
    || !is_file($file)
    || (!str_starts_with($file, $wwwrootprefix) && $file !== $wwwroot)
) {
    http_response_code(404);
    exit("File not found.");
}

header("X-Content-Type-Options: nosniff");
header("Content-Type: " . assetContentType($file));

if ($file === $wwwroot . DIRECTORY_SEPARATOR . "index.html") {
    $content = file_get_contents($file);
    if ($content === false) {
        http_response_code(500);
        exit("Could not read index.html.");
    }

    $settings = previewSettings();
    if ($settings["package_name"] !== "") {
        $content = replaceDataAttribute(
            $content,
            "data-versao",
            "data-package_name",
            $settings["package_name"]
        );
    }
    if ($settings["wwwroot_web"] !== "") {
        $content = replaceDataAttribute(
            $content,
            "data-versao",
            "data-wwwroot_web",
            $settings["wwwroot_web"]
        );
    }

    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Content-Length: " . strlen($content));
    echo $content;
    exit;
}

$size = filesize($file);
if ($size !== false) {
    header("Content-Length: " . $size);
}
header("Cache-Control: public, max-age=300");
readfile($file);

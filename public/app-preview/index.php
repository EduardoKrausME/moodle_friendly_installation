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
            redirect_to("/app_manager.php?domain=" . rawurlencode($domain));
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

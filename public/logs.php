<?php

use app\Auth;
use app\LogManager;
use app\SiteManager;

require_once __DIR__ . "/app/bootstrap.php";
Auth::requireLogin();

$domain = isset($_GET["domain"]) && is_string($_GET["domain"]) ? trim($_GET["domain"]) : "";
$site = SiteManager::get($domain);
if ($site === null) {
    http_response_code(404);
    render_header(t("logs.title"));
    echo render_app_template("page/details-not-found");
    render_footer();
    exit;
}

$sources = LogManager::sources($site);
$sourceid = isset($_GET["source"]) && is_string($_GET["source"]) ? $_GET["source"] : "";
if ($sourceid === "" && !empty($sources)) {
    $sourceid = (string) ($sources[0]["id"] ?? "");
}
$selectedsource = LogManager::source($site, $sourceid);
$query = isset($_GET["q"]) && is_string($_GET["q"]) ? trim(mb_substr($_GET["q"], 0, 100)) : "";
$follow = isset($_GET["follow"]) && $_GET["follow"] === "1";
$result = $selectedsource !== null
    ? LogManager::read($selectedsource, $query)
    : ["content" => "", "error" => "", "truncated" => false, "line_count" => 0];

foreach ($sources as $index => $source) {
    $sources[$index]["selected"] = ($source["id"] ?? "") === $sourceid;
    $sources[$index]["url"] = "/logs.php?domain=" . rawurlencode($domain)
        . "&source=" . rawurlencode((string) ($source["id"] ?? ""));
}

render_header(t("logs.title"));
echo render_app_template("page/logs", [
    "domain" => $domain,
    "details_url" => "/details.php?domain=" . rawurlencode($domain),
    "has_sources" => !empty($sources),
    "sources" => $sources,
    "source_id" => $sourceid,
    "query" => $query,
    "follow" => $follow,
    "has_selected_source" => $selectedsource !== null,
    "selected_source" => $selectedsource,
    "has_content" => $result["content"] !== "",
    "content" => $result["content"],
    "has_error" => $result["error"] !== "",
    "error" => $result["error"],
    "truncated" => $result["truncated"],
    "line_count" => $result["line_count"],
]);
render_footer();

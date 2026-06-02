<?php

use app\AppManager;
use app\JobManager;

$job = JobManager::markRunning((string) $job['id']);
if (!$job) {
    throw new RuntimeException('Cannot mark app build job as running.');
}

$result = executeAppBuildJob($job);
if ($result['exitcode'] === 0) {
    JobManager::markDone((string) $job['id'], [
        'artifact_files' => $result['artifact_files'],
        'app_version' => $result['app_version'],
    ]);
    echo "App build completed: {$job['id']}\n";
} else {
    JobManager::markFailed((string) $job['id'], $result['message']);
    echo "App build failed: {$job['id']} - {$result['message']}\n";
}

function executeAppBuildJob(array $job): array {
    $domain = sanitizeDomain((string) ($job['domain'] ?? ''));
    $packageuid = $job['package_uid'] ?? '';
    $packagename = $job['package_name'] ?? '';
    $moodleurl = $job['moodle_url'] ?? '';
    if ($moodleurl === '') {
        $moodleurl = 'https://' . $domain;
    }
    $moodleurl = rtrim($moodleurl, '/');
    $color = $job['statusbarbackgroundcolor'] ?? '#08422A';
    $version = $job['app_version'] ?? AppManager::appVersion();
    $iconpath = $job['icon_path'] ?? '';
    $logfile = $job['log_file'] ?? app_config_path('/logs/app-build-' . $domain . '.log');

    ensureDir(dirname($logfile), 0750);
    appendAppBuildLog($logfile, 'Starting APP build for ' . $domain . '.');

    $source = app_config_path('/app-MoodleMobile-V2');
    if (!is_dir($source)) {
        return failAppBuild($logfile, 'app-MoodleMobile-V2 directory not found.');
    }
    if (!is_file($iconpath) || !is_readable($iconpath)) {
        return failAppBuild($logfile, 'APP icon not found or not readable.');
    }

    $workroot = app_config_path('/runtime/app-builds/' . $job['id']);
    $workdir = $workroot . '/app';
    removeDir($workroot);
    ensureDir($workroot, 0700);
    copyRecursive($source, $workdir, ['node_modules', 'platforms', 'plugins']);

    $resfolder = sanitizePackageUid($packageuid);
    $resdir = $workdir . '/res/' . $resfolder;
    ensureDir($resdir, 0750);
    copy($iconpath, $resdir . '/logo.png');
    chmod($resdir . '/logo.png', 0777);

    try {
        generateAppImages($resdir, $color, $logfile);
        updateCordovaConfig($workdir . '/config.xml', $resfolder, $packageuid, $packagename, $color, $version);
        updateIndexHtml($workdir . '/www/index.html', $packageuid, $packagename, $version, $moodleurl);
        $buildconfig = createAndroidBuildConfig($resfolder, $workdir, $logfile);

        runBuildCommand('npm install --no-audit --fund=false', $workdir, $logfile);
        runBuildCommand('npx cordova platform remove android || true', $workdir, $logfile);
        runBuildCommand('npx cordova platform add android@15.0.0', $workdir, $logfile);
        runBuildCommand('npx cordova requirements android', $workdir, $logfile);
        runBuildCommand('npx cordova build android --release -- --packageType=apk --buildConfig ' . escapeshellarg($buildconfig), $workdir, $logfile);
        runBuildCommand('npx cordova build android --release -- --packageType=bundle --buildConfig ' . escapeshellarg($buildconfig), $workdir, $logfile);

        $artifacts = moveBuildArtifacts($workdir, $domain, $packageuid, $version, $logfile);
        appendAppBuildLog($logfile, 'Build completed successfully.');

        return [
            'exitcode' => 0,
            'message' => 'OK',
            'artifact_files' => $artifacts,
            'app_version' => $version,
        ];
    } catch (Throwable $e) {
        return failAppBuild($logfile, $e->getMessage());
    }
}

function generateAppImages(string $resdir, string $color, string $logfile): void {
    ensureDir($resdir . '/android', 0750);
    ensureDir($resdir . '/android-notification', 0750);
    ensureDir($resdir . '/android-screen', 0750);

    $sizes = [512, 192, 144, 96, 72, 48, 36];
    foreach ($sizes as $size) {
        runImageCommand('magick logo.png -resize ' . $size . 'x' . $size . ' android/' . $size . 'x' . $size . '.png', $resdir, $logfile);
    }

    foreach ([48, 72, 96, 144, 192] as $size) {
        runImageCommand('magick logo.png -resize ' . $size . 'x' . $size . ' android-notification/' . $size . 'x' . $size . '.png', $resdir, $logfile);
    }

    runImageCommand('magick logo.png -resize 1024x1024 -background ' . escapeshellarg($color) . ' -gravity center -extent 1024x1024 splash.png', $resdir, $logfile);
    runImageCommand('magick logo.png -resize 1024x1024 splash-tmp.png', $resdir, $logfile);
    runImageCommand('magick splash-tmp.png -gravity center -crop 1024x500+0+0 tela-recursos.png', $resdir, $logfile);
    @unlink($resdir . '/splash-tmp.png');

    $screens = [
        'ldpi' => [320, 200, 320],
        'hdpi' => [800, 480, 800],
        'xhdpi' => [1280, 720, 1280],
        'xxhdpi' => [1600, 960, 1600],
        'xxxhdpi' => [1920, 1280, 1920],
    ];
    foreach ($screens as $density => $data) {
        [$square, $short, $long] = $data;
        runImageCommand('magick logo.png -resize ' . $square . 'x' . $square . ' splash-tmp.png', $resdir, $logfile);
        runImageCommand('magick splash-tmp.png -gravity center -crop ' . $short . 'x' . $long . '+0+0 android-screen/drawable-port-' . $density . '-screen.png', $resdir, $logfile);
        runImageCommand('magick splash-tmp.png -gravity center -crop ' . $long . 'x' . $short . '+0+0 android-screen/drawable-land-' . $density . '-screen.png', $resdir, $logfile);
        runImageCommand('magick splash-tmp.png -gravity center -crop ' . $square . 'x' . $square . '+0+0 android-screen/drawable-' . $density . '-screen.png', $resdir, $logfile);
    }
    @unlink($resdir . '/splash-tmp.png');
}

function updateCordovaConfig(string $configfile, string $resfolder, string $packageuid, string $packagename, string $color, string $version): void {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($configfile)) {
        throw new RuntimeException('Could not read the APP config.xml.');
    }

    $root = $dom->documentElement;
    $root->setAttribute('id', $packageuid);
    $root->setAttribute('android-packageName', $packageuid);
    $root->setAttribute('version', $version);

    setElementText($dom, 'name', $packagename);
    setPreference($dom, 'AppendUserAgent', ' AppMoodleMobileV2/' . $version);
    setPreference($dom, 'StatusBarBackgroundColor', $color);
    setPreference($dom, 'SplashScreenBackgroundColor', $color);
    setPreference($dom, 'AndroidWindowSplashScreenIconBackgroundColor', $color);

    $icons = [
        'ldpi' => '36x36.png',
        'mdpi' => '48x48.png',
        'hdpi' => '72x72.png',
        'xhdpi' => '96x96.png',
        'xxhdpi' => '144x144.png',
        'xxxhdpi' => '192x192.png',
    ];
    foreach ($dom->getElementsByTagName('icon') as $icon) {
        $density = $icon->getAttribute('density');
        if (isset($icons[$density])) {
            $icon->setAttribute('src', 'res/' . $resfolder . '/android/' . $icons[$density]);
        }
    }

    foreach ($dom->getElementsByTagName('splash') as $splash) {
        $splash->setAttribute('src', 'res/' . $resfolder . '/splash.png');
    }

    foreach ($dom->getElementsByTagName('resource-file') as $resource) {
        $target = $resource->getAttribute('target');
        $src = $resource->getAttribute('src');
        if (preg_match('/ic_stat_onesignal_default\.png$/', $target) && preg_match('/(48|72|96|144|192)x\1\.png$/', $src, $matches)) {
            $resource->setAttribute('src', 'res/' . $resfolder . '/android-notification/' . $matches[0]);
            continue;
        }
        if (str_contains($target, 'ic_menu_share.png')) {
            $resource->setAttribute('src', preg_replace('#^src/img/#', 'www/img/', $src));
        }
    }

    $dom->save($configfile);
}

function setElementText(DOMDocument $dom, string $tagname, string $value): void {
    $nodes = $dom->getElementsByTagName($tagname);
    if ($nodes->length > 0) {
        $nodes->item(0)->nodeValue = $value;
    }
}

function setPreference(DOMDocument $dom, string $name, string $value): void {
    foreach ($dom->getElementsByTagName('preference') as $pref) {
        if ($pref->getAttribute('name') === $name) {
            $pref->setAttribute('value', $value);
            return;
        }
    }

    $pref = $dom->createElement('preference');
    $pref->setAttribute('name', $name);
    $pref->setAttribute('value', $value);
    $dom->documentElement->appendChild($pref);
}

function updateIndexHtml(string $file, string $packageuid, string $packagename, string $version, string $moodleurl): void {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('Could not read www/index.html.');
    }

    $content = preg_replace_callback(
        '/(<title>)(.*?)(<\/title>)/is',
        static fn(array $matches): string => $matches[1] . htmlSpecialCharsValue($packagename) . $matches[3],
        $content,
        1
    );
    $content = replaceDataAttribute($content, 'data-versao', 'data-package_uid', $packageuid);
    $content = replaceDataAttribute($content, 'data-versao', 'data-package_name', $packagename);
    $content = replaceDataAttribute($content, 'data-versao', 'data-wwwroot_web', $moodleurl);
    $content = replaceElementTextById($content, 'config-package_version', $version);

    if (file_put_contents($file, $content) === false) {
        throw new RuntimeException('Could not save www/index.html.');
    }
}

function replaceDataAttribute(string $content, string $elementid, string $attribute, string $value): string {
    $escapedvalue = htmlSpecialCharsValue($value);
    $elementidpattern = preg_quote($elementid, '/');
    $attributepattern = preg_quote($attribute, '/');

    $pattern = '/(<[^>]*\bid=["\']' . $elementidpattern . '["\'][^>]*\b' . $attributepattern . '\s*=\s*)(["\'])(.*?)(\2)/is';
    $updated = preg_replace_callback(
        $pattern,
        static fn(array $matches): string => $matches[1] . $matches[2] . $escapedvalue . $matches[4],
        $content,
        1,
        $count
    );

    if ($updated === null) {
        throw new RuntimeException('Error updating attribute ' . $attribute . ' in #' . $elementid . '.');
    }
    if ($count > 0) {
        return $updated;
    }

    $insertpattern = '/(<[^>]*\bid=["\']' . $elementidpattern . '["\'][^>]*)(>)/is';
    $updated = preg_replace_callback(
        $insertpattern,
        static fn(array $matches): string => rtrim($matches[1]) . ' ' . $attribute . '="' . $escapedvalue . '"' . $matches[2],
        $content,
        1,
        $count
    );

    if ($updated === null || $count === 0) {
        throw new RuntimeException('Element #' . $elementid . ' not found in www/index.html.');
    }

    return $updated;
}

function replaceElementTextById(string $content, string $elementid, string $value): string {
    $elementidpattern = preg_quote($elementid, '/');
    $pattern = '/(<([a-z0-9:-]+)\b[^>]*\bid=["\']' . $elementidpattern . '["\'][^>]*>)(.*?)(<\/\2>)/is';
    $updated = preg_replace_callback(
        $pattern,
        static fn(array $matches): string => $matches[1] . htmlSpecialCharsValue($value) . $matches[4],
        $content,
        1,
        $count
    );

    if ($updated === null) {
        throw new RuntimeException('Error updating text for #' . $elementid . '.');
    }
    if ($count === 0) {
        throw new RuntimeException('Element #' . $elementid . ' not found in www/index.html.');
    }

    return $updated;
}

function htmlSpecialCharsValue(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function createAndroidBuildConfig(string $resfolder, string $workdir, string $logfile): string {
    $keydir = $workdir . '/res/' . $resfolder . '/key-android';
    $keystore = $keydir . '/keystore';
    $passfile = $keydir . '/keystore.txt';
    $buildconfig = $keydir . '/build.json';

    if (!is_file($keystore) || !is_readable($keystore)) {
        throw new RuntimeException('Android keystore not found at res/' . $resfolder . '/key-android/keystore.');
    }
    if (!is_file($passfile) || !is_readable($passfile)) {
        throw new RuntimeException('Android keystore password not found at res/' . $resfolder . '/key-android/keystore.txt.');
    }

    $password = trim((string) file_get_contents($passfile));
    if ($password === '') {
        throw new RuntimeException('The keystore.txt file is empty.');
    }

    file_put_contents(
        $buildconfig,
        json_encode(AppManager::androidBuildConfig($keystore, $password), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    chmod($buildconfig, 0600);
    appendAppBuildLog($logfile, 'Android build config prepared at ' . $buildconfig . '.');

    return $buildconfig;
}

function moveBuildArtifacts(string $workdir, string $domain, string $packageuid, string $version, string $logfile): array {
    $apk = newestFile($workdir . '/platforms/android/app/build/outputs/apk/release/*.apk');
    $aab = newestFile($workdir . '/platforms/android/app/build/outputs/bundle/release/*.aab');

    if ($apk === null) {
        throw new RuntimeException('APK not found after the build.');
    }
    if ($aab === null) {
        throw new RuntimeException('AAB not found after the build.');
    }

    $destdir = AppManager::storageDir($domain);
    ensureDir($destdir, 0750);
    $basename = preg_replace('/[^a-z0-9_.-]+/i', '_', $packageuid . '.' . $version);
    $apkdest = $destdir . '/' . $basename . '.apk';
    $aabdest = $destdir . '/' . $basename . '.aab';

    copy($apk, $apkdest);
    copy($aab, $aabdest);
    chmod($apkdest, 0777);
    chmod($aabdest, 0777);

    appendAppBuildLog($logfile, 'APK moved to ' . $apkdest . '.');
    appendAppBuildLog($logfile, 'AAB moved to ' . $aabdest . '.');

    return [basename($apkdest), basename($aabdest)];
}

function newestFile(string $pattern): ?string {
    $files = glob($pattern) ?: [];
    if (!$files) {
        return null;
    }
    usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    return $files[0];
}

function runImageCommand(string $command, string $cwd, string $logfile): void {
    runCommand($command, $cwd, $logfile, false);
}

function runBuildCommand(string $command, string $cwd, string $logfile): void {
    runCommand($command, $cwd, $logfile, true);
}

function runCommand(string $command, string $cwd, string $logfile, bool $withjava): void {
    appendAppBuildLog($logfile, '$ ' . $command);
    $env = 'export npm_config_unsafe_perm=true; ';
    if ($withjava) {
        $env .= 'if command -v javac >/dev/null 2>&1; then export JAVA_HOME="$(dirname "$(dirname "$(readlink -f "$(command -v javac)")")")"; export CORDOVA_JAVA_HOME="$JAVA_HOME"; export PATH="$JAVA_HOME/bin:$PATH"; fi; ';
    }

    $script = 'cd ' . escapeshellarg($cwd) . ' && ' . $env . $command . ' >> ' . escapeshellarg($logfile) . ' 2>&1';
    exec('/usr/bin/env bash -lc ' . escapeshellarg($script), $output, $exitcode);
    if ($exitcode !== 0) {
        throw new RuntimeException('Command failed with code ' . $exitcode . ': ' . $command . '. See the log: ' . $logfile);
    }
}

function appendAppBuildLog(string $logfile, string $message): void {
    ensureDir(dirname($logfile), 0750);
    file_put_contents($logfile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function failAppBuild(string $logfile, string $message): array {
    appendAppBuildLog($logfile, 'ERROR: ' . $message);
    return [
        'exitcode' => 1,
        'message' => $message,
        'artifact_files' => [],
        'app_version' => AppManager::appVersion(),
    ];
}

function copyRecursive(string $source, string $dest, array $skipnames = []): void {
    ensureDir($dest, 0750);
    $source = rtrim($source, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relative = substr($path, strlen($source) + 1);
        $parts = preg_split('#[/\\\\]+#', $relative) ?: [];
        if (array_intersect($parts, $skipnames)) {
            continue;
        }
        $target = $dest . '/' . $relative;

        if ($item->isDir()) {
            ensureDir($target, 0750);
            continue;
        }

        ensureDir(dirname($target), 0750);
        copy($path, $target);
        chmod($target, fileperms($path) & 0777);
    }
}

function removeDir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

function ensureDir(string $dir, int $mode): void {
    if (!is_dir($dir)) {
        mkdir($dir, $mode, true);
    }
}


function sanitizePackageUid(string $packageuid): string {
    $packageuid = strtolower(trim($packageuid));
    $packageuid = preg_replace('/[^a-z0-9_.]+/', '_', $packageuid);
    return trim((string) $packageuid, '._');
}

function sanitizeDomain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/[^a-z0-9.-]+/', '-', $domain);
    return trim((string) $domain, '.-');
}

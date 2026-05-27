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
    $packageuid = (string) ($job['package_uid'] ?? '');
    $packagename = (string) ($job['package_name'] ?? '');
    $color = (string) ($job['statusbarbackgroundcolor'] ?? '#08422A');
    $version = (string) ($job['app_version'] ?? AppManager::appVersion());
    $iconpath = (string) ($job['icon_path'] ?? '');
    $logfile = (string) ($job['log_file'] ?? app_config_path('/logs/app-build-' . $domain . '.log'));

    ensureDir(dirname($logfile), 0750);
    appendAppBuildLog($logfile, 'Iniciando build do APP para ' . $domain . '.');

    $source = app_config_path('/app-MoodleMobile-V2');
    if (!is_dir($source)) {
        return failAppBuild($logfile, 'Diretório app-MoodleMobile-V2 não encontrado.');
    }
    if (!is_file($iconpath) || !is_readable($iconpath)) {
        return failAppBuild($logfile, 'Ícone do APP não encontrado ou sem leitura.');
    }

    $workroot = app_config_path('/runtime/app-builds/' . $job['id']);
    $workdir = $workroot . '/app';
    removeDir($workroot);
    ensureDir($workroot, 0700);
    copyRecursive($source, $workdir, ['node_modules', 'platforms', 'plugins']);

    $resdir = $workdir . '/res/' . $domain;
    removeDir($resdir);
    ensureDir($resdir, 0750);
    copy($iconpath, $resdir . '/logo.png');
    chmod($resdir . '/logo.png', 0640);

    try {
        generateAppImages($resdir, $color, $logfile);
        updateCordovaConfig($workdir . '/config.xml', $domain, $packageuid, $packagename, $color, $version);
        updateIndexHtml($workdir . '/www/index.html', $packageuid, $packagename, $version);
        $buildconfig = createAndroidBuildConfig($domain, $workdir, $logfile);

        runBuildCommand('npm install --no-audit --fund=false', $workdir, $logfile);
        runBuildCommand('npx cordova platform remove android || true', $workdir, $logfile);
        runBuildCommand('npx cordova platform add android@15.0.0', $workdir, $logfile);
        runBuildCommand('npx cordova requirements android', $workdir, $logfile);
        runBuildCommand('npx cordova build android --release -- --packageType=apk --buildConfig ' . escapeshellarg($buildconfig), $workdir, $logfile);
        runBuildCommand('npx cordova build android --release -- --packageType=bundle --buildConfig ' . escapeshellarg($buildconfig), $workdir, $logfile);

        $artifacts = moveBuildArtifacts($workdir, $domain, $packageuid, $version, $logfile);
        appendAppBuildLog($logfile, 'Build finalizado com sucesso.');

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

function updateCordovaConfig(string $configfile, string $domain, string $packageuid, string $packagename, string $color, string $version): void {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($configfile)) {
        throw new RuntimeException('Não foi possível ler config.xml do APP.');
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
            $icon->setAttribute('src', 'res/' . $domain . '/android/' . $icons[$density]);
        }
    }

    foreach ($dom->getElementsByTagName('splash') as $splash) {
        $splash->setAttribute('src', 'res/' . $domain . '/splash.png');
    }

    foreach ($dom->getElementsByTagName('resource-file') as $resource) {
        $target = $resource->getAttribute('target');
        $src = $resource->getAttribute('src');
        if (preg_match('/ic_stat_onesignal_default\.png$/', $target) && preg_match('/(48|72|96|144|192)x\1\.png$/', $src, $matches)) {
            $resource->setAttribute('src', 'res/' . $domain . '/android-notification/' . $matches[0]);
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

function updateIndexHtml(string $file, string $packageuid, string $packagename, string $version): void {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('Não foi possível ler www/index.html.');
    }

    $content = preg_replace('/(<title>)(.*?)(<\/title>)/is', '$1' . htmlspecialchars($packagename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '$3', $content);
    $content = preg_replace('/(<span\s+id="package_version">)(.*?)(<\/span>)/is', '$1' . htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '$3', $content);
    $content = preg_replace('/(<span\s+style="display:\s*none"\s+id="package_uid">)(.*?)(<\/span>)/is', '$1' . htmlspecialchars($packageuid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '$3', $content);
    $content = preg_replace('/(<span\s+style="display:\s*none"\s+id="package_name">)(.*?)(<\/span>)/is', '$1' . htmlspecialchars($packagename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '$3', $content);

    file_put_contents($file, $content);
}

function createAndroidBuildConfig(string $domain, string $workdir, string $logfile): string {
    $keydir = AppManager::storageDir($domain) . '/key-android';
    ensureDir($keydir, 0700);
    $keystore = $keydir . '/keystore';
    $passfile = $keydir . '/keystore.txt';

    if (!is_file($keystore) || !is_file($passfile)) {
        $password = bin2hex(random_bytes(12)) . 'A1#';
        file_put_contents($passfile, $password . PHP_EOL);
        chmod($passfile, 0600);
        $command = 'keytool -genkeypair -v -keystore ' . escapeshellarg($keystore) .
            ' -alias app -keyalg RSA -keysize 2048 -validity 10000 -storetype PKCS12' .
            ' -storepass ' . escapeshellarg($password) .
            ' -keypass ' . escapeshellarg($password) .
            ' -dname ' . escapeshellarg('CN=' . $domain . ', OU=Mobile, O=MyLearn, L=Sao Paulo, ST=SP, C=BR');
        runBuildCommand($command, $workdir, $logfile);
        chmod($keystore, 0600);
    }

    $password = trim((string) file_get_contents($passfile));
    $config = [
        'android' => [
            'release' => [
                'keystore' => $keystore,
                'storePassword' => $password,
                'alias' => 'app',
                'password' => $password,
                'keystoreType' => 'pkcs12',
            ],
        ],
    ];

    $buildconfig = $workdir . '/build.json';
    file_put_contents($buildconfig, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    chmod($buildconfig, 0600);
    return $buildconfig;
}

function moveBuildArtifacts(string $workdir, string $domain, string $packageuid, string $version, string $logfile): array {
    $apk = newestFile($workdir . '/platforms/android/app/build/outputs/apk/release/*.apk');
    $aab = newestFile($workdir . '/platforms/android/app/build/outputs/bundle/release/*.aab');

    if ($apk === null) {
        throw new RuntimeException('APK não encontrado após o build.');
    }
    if ($aab === null) {
        throw new RuntimeException('AAB não encontrado após o build.');
    }

    $destdir = AppManager::storageDir($domain);
    ensureDir($destdir, 0750);
    $basename = preg_replace('/[^a-z0-9_.-]+/i', '_', $packageuid . '.' . $version);
    $apkdest = $destdir . '/' . $basename . '.apk';
    $aabdest = $destdir . '/' . $basename . '.aab';

    copy($apk, $apkdest);
    copy($aab, $aabdest);
    chmod($apkdest, 0640);
    chmod($aabdest, 0640);

    appendAppBuildLog($logfile, 'APK movido para ' . $apkdest . '.');
    appendAppBuildLog($logfile, 'AAB movido para ' . $aabdest . '.');

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
        throw new RuntimeException('Comando falhou com código ' . $exitcode . ': ' . $command . '. Veja o log: ' . $logfile);
    }
}

function appendAppBuildLog(string $logfile, string $message): void {
    ensureDir(dirname($logfile), 0750);
    file_put_contents($logfile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function failAppBuild(string $logfile, string $message): array {
    appendAppBuildLog($logfile, 'ERRO: ' . $message);
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
        $parts = preg_split('#[/\\]+#', $relative) ?: [];
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

function sanitizeDomain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/[^a-z0-9.-]+/', '-', $domain);
    return trim((string) $domain, '.-');
}

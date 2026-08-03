<?php

$devices = [
        "iphone-se" => [
                "label" => "iPhone SE",
                "width" => 375,
                "height" => 667,
                "type" => "phone",
                "description" => "375 × 667",
        ],
        "iphone-13-mini" => [
                "label" => "iPhone 13 mini",
                "width" => 375,
                "height" => 812,
                "type" => "phone",
                "description" => "375 × 812",
        ],
        "iphone-15" => [
                "label" => "iPhone 15",
                "width" => 393,
                "height" => 852,
                "type" => "phone",
                "description" => "393 × 852",
        ],
        "iphone-xr" => [
                "label" => "iPhone XR",
                "width" => 414,
                "height" => 896,
                "type" => "phone",
                "description" => "414 × 896",
        ],
        "iphone-15-pro-max" => [
                "label" => "iPhone 15 Pro Max",
                "width" => 430,
                "height" => 932,
                "type" => "phone",
                "description" => "430 × 932",
        ],
        "pixel-8" => [
                "label" => "Google Pixel 8",
                "width" => 412,
                "height" => 915,
                "type" => "phone",
                "description" => "412 × 915",
        ],
        "motorola-edge-50" => [
                "label" => "Motorola Edge 50",
                "width" => 412,
                "height" => 915,
                "type" => "phone",
                "description" => "412 × 915",
        ],
        "xiaomi-14" => [
                "label" => "Xiaomi 14",
                "width" => 393,
                "height" => 873,
                "type" => "phone",
                "description" => "393 × 873",
        ],
        "oneplus-12" => [
                "label" => "OnePlus 12",
                "width" => 450,
                "height" => 1000,
                "type" => "phone",
                "description" => "450 × 1000",
        ],

        "ipad-11" => [
                "label" => "iPad Air 11",
                "width" => 820,
                "height" => 1180,
                "type" => "tablet",
                "description" => "820 × 1180",
        ],
        "ipad-13" => [
                "label" => "iPad Pro 13",
                "width" => 1024,
                "height" => 1366,
                "type" => "tablet",
                "description" => "1024 × 1366",
        ],
];

$appUrl = "/app-preview/?package_name=" . urlencode($_GET["package_name"]) .
        "&wwwroot=" . urlencode($_GET["wwwroot"]) .
        "&domain=" . urlencode($_GET["domain"]);
$selectedDevice = (string) ($_GET["device"] ?? "iphone-se");

if (!isset($devices[$selectedDevice])) {
    $selectedDevice = "iphone-se";
}
$currentDevice = $devices[$selectedDevice];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>App Preview</title>

    <style>
        :root {
            color-scheme: light;
            --background: #eef3f9;
            --panel: #ffffff;
            --border: #d8e1ec;
            --text: #172033;
            --muted: #68758a;
            --primary: #1463df;
            --primary-hover: #0d52bf;
            --device-width: <?php echo (int)$currentDevice["width"] ?>px;
            --device-height: <?php echo (int)$currentDevice["height"] ?>px;
            --device-scale: 0.75;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .page {
            display: grid;
            grid-template-columns: minmax(260px, 330px) minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            padding: 24px;
            background: var(--panel);
            border-right: 1px solid var(--border);
        }

        .sidebar h1 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .sidebar > p {
            margin: 0 0 24px;
            color: var(--muted);
            line-height: 1.5;
        }

        .field {
            display: grid;
            gap: 8px;
            margin-bottom: 20px;
        }

        .field label,
        .group-title {
            font-size: 13px;
            font-weight: 700;
            color: #3d4b60;
        }

        .url-row {
            display: grid;
            gap: 8px;
        }

        input[type="text"] {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            background: #fff;
            color: var(--text);
        }

        input[type="text"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(20, 99, 223, .13);
        }

        .primary-button,
        .secondary-button,
        .device-button {
            border: 0;
            cursor: pointer;
        }

        .primary-button {
            padding: 11px 14px;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
        }

        .primary-button:hover {
            background: var(--primary-hover);
        }

        .device-list {
            display: grid;
            gap: 10px;
            margin-top: 8px;
        }

        .device-button {
            display: grid;
            grid-template-columns: 42px 1fr auto;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            text-align: left;
        }

        .device-button:hover {
            border-color: #9db9df;
        }

        .device-button.is-active {
            border-color: var(--primary);
            background: #edf5ff;
            box-shadow: 0 0 0 2px rgba(20, 99, 223, .08);
        }

        .device-icon {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #edf2f8;
        }

        .device-icon::before {
            content: "";
            display: block;
            width: 17px;
            height: 29px;
            border: 2px solid currentColor;
            border-radius: 5px;
        }

        .device-button[data-type="tablet"] .device-icon::before {
            width: 23px;
            height: 30px;
            border-radius: 4px;
        }

        .device-name {
            display: block;
            font-weight: 700;
        }

        .device-size {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            color: var(--muted);
        }

        .selected-check {
            color: var(--primary);
            font-weight: 800;
            opacity: 0;
        }

        .device-button.is-active .selected-check {
            opacity: 1;
        }

        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }

        .secondary-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: var(--text);
            font-weight: 700;
        }

        .button-icon {
            width: 18px;
            height: 18px;
            flex: 0 0 auto;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .secondary-button:hover {
            background: #f5f8fc;
        }

        .preview-area {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            min-width: 0;
            height: 100vh;
            padding: 28px;
        }

        .preview-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .preview-title {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
        }

        .preview-title strong {
            font-size: 18px;
        }

        .preview-title span {
            color: var(--muted);
            font-size: 13px;
        }

        .scale-control {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 13px;
        }

        .scale-control input {
            width: 150px;
        }

        .stage {
            display: grid;
            place-items: start center;
            min-width: 0;
            min-height: 0;
            padding: 10px;
            overflow: auto;
        }

        .device-space {
            position: relative;
            width: calc((var(--device-width) + 28px) * var(--device-scale));
            height: calc((var(--device-height) + 28px) * var(--device-scale));
            transition: width .2s ease, height .2s ease;
        }

        .device-shell {
            position: absolute;
            top: 0;
            left: 0;
            width: calc(var(--device-width) + 28px);
            height: calc(var(--device-height) + 28px);
            padding: 14px;
            transform: scale(var(--device-scale));
            transform-origin: top left;
            border: 2px solid #202634;
            border-radius: 44px;
            background: #202634;
            box-shadow: 0 18px 45px rgba(32, 38, 52, .18);
            transition: width .2s ease, height .2s ease, border-radius .2s ease, transform .2s ease;
        }

        .device-shell.is-tablet {
            border-radius: 28px;
        }

        .screen {
            position: relative;
            width: var(--device-width);
            height: var(--device-height);
            overflow: hidden;
            border-radius: 31px;
            background: #fff;
        }

        .device-shell.is-tablet .screen {
            border-radius: 16px;
        }

        .screen iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }

        .notch {
            position: absolute;
            z-index: 2;
            top: 8px;
            left: 50%;
            width: 112px;
            height: 28px;
            transform: translateX(-50%);
            border-radius: 16px;
            background: #202634;
            pointer-events: none;
        }

        .device-shell.is-tablet .notch {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            top: 9px;
        }

        @media (max-width: 860px) {
            .page {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
                width: auto;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }

            .preview-area {
                height: 100dvh;
                padding: 18px 10px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <aside class="sidebar">
        <form id="preview-form" method="get">
            <div class="actions">
                <button id="rotate-button" class="secondary-button" type="button" title="Rotacionar dispositivo">
                    <svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="nonzero" d="m 16.547917,2.619851 c 3.259152,1.5363039 5.599951,4.6832489 5.954316,8.410025 h 1.497313 C 23.490459,4.9243068 18.349684,0.12707412 12.070952,0.12707412 c -0.224597,0 -0.439212,0.0198233 -0.663809,0.0346907 L 15.215309,3.9430547 Z M 10.304122,1.8566549 c -0.5839517,-0.5798308 -1.5322499,-0.5798308 -2.116202,0 L 1.8343217,8.1654124 c -0.5839521,0.5798307 -0.5839521,1.5214363 0,2.1012666 L 13.83279,22.180469 c 0.583952,0.57983 1.532251,0.57983 2.116202,0 l 6.353599,-6.308758 c 0.583952,-0.579831 0.583952,-1.521436 0,-2.101267 z M 14.895883,21.129834 2.892423,9.2160459 9.2460212,2.9072885 21.244489,14.821077 Z M 7.5939859,21.422228 C 4.3348345,19.885924 1.9940351,16.738979 1.639671,13.012203 H 0.14235804 c 0.50908643,6.105569 5.64986116,10.902802 11.92859396,10.902802 0.224596,0 0.439211,-0.01983 0.663809,-0.03469 L 8.9265944,20.099024 Z"/>
                        <path d="M20 4v7h-7"></path>
                    </svg>
                    <span>Rotate</span>
                </button>
                <button id="reload-button" class="secondary-button" type="button" title="Recarregar preview">
                    <svg class="button-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"></path>
                        <path d="M21 3v5h-5"></path>
                    </svg>
                    <span>Reload</span>
                </button>
            </div>
            <div class="device-list">
                <?php
                foreach ($devices as $key => $device): ?>
                    <button class="device-button <?php
                    echo $key === $selectedDevice ? "is-active" : "" ?>"
                            type="button"
                            data-device="<?php
                            echo $key ?>"
                            data-width="<?php
                            echo (int) $device["width"] ?>"
                            data-height="<?php
                            echo (int) $device["height"] ?>"
                            data-type="<?php
                            echo $device["type"] ?>">
                        <span class="device-icon" aria-hidden="true"></span>
                        <span>
                            <span class="device-name"><?php
                                echo $device["label"] ?></span>
                            <span class="device-size"><?php
                                echo $device["description"] ?></span>
                        </span>
                        <span class="selected-check" aria-hidden="true">✓</span>
                    </button>
                <?php
                endforeach; ?>
            </div>

            <input id="device-input" type="hidden" name="device" value="<?php
            echo $selectedDevice ?>">
        </form>
    </aside>

    <main class="preview-area">
        <div class="preview-toolbar">
            <div class="preview-title">
                <strong id="current-device-name"><?php
                    echo $currentDevice["label"] ?></strong>
                <span id="current-device-size"><?php
                    echo $currentDevice["description"] ?></span>
            </div>

            <label class="scale-control" for="scale-range">
                Zoom
                <input id="scale-range" type="range" min="10" max="100" step="1" value="75">
                <span id="scale-value">75%</span>
            </label>
        </div>

        <div class="stage">
            <div class="device-space">
                <div
                        id="device-shell"
                        class="device-shell <?php
                        echo $currentDevice["type"] === "tablet" ? "is-tablet" : "" ?>"
                >
                    <div class="notch" aria-hidden="true"></div>
                    <div class="screen">
                        <iframe
                                id="app-frame"
                                src="<?php
                                echo $appUrl ?>"
                                title="Pré-visualização do aplicativo"
                                allow="autoplay; fullscreen"
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    (() => {
        const root = document.documentElement;
        const deviceButtons = document.querySelectorAll(".device-button");
        const deviceInput = document.getElementById("device-input");
        const deviceShell = document.getElementById("device-shell");
        const stage = document.querySelector(".stage");
        const frame = document.getElementById("app-frame");
        const scaleRange = document.getElementById("scale-range");
        const scaleValue = document.getElementById("scale-value");
        const rotateButton = document.getElementById("rotate-button");
        const reloadButton = document.getElementById("reload-button");
        const currentDeviceName = document.getElementById("current-device-name");
        const currentDeviceSize = document.getElementById("current-device-size");

        let currentWidth = <?php echo (int) $currentDevice["width"] ?>;
        let currentHeight = <?php echo (int) $currentDevice["height"] ?>;
        let rotated = false;
        let fitFrameId = 0;

        const applyScale = (percent) => {
            const normalizedPercent = Math.max(10, Math.min(100, Math.floor(percent)));
            const scale = normalizedPercent / 100;

            root.style.setProperty("--device-scale", scale.toString());
            scaleRange.value = String(normalizedPercent);
            scaleValue.textContent = `${normalizedPercent}%`;
        };

        const fitZoomToStage = () => {
            cancelAnimationFrame(fitFrameId);

            fitFrameId = requestAnimationFrame(() => {
                const stageStyle = getComputedStyle(stage);
                const availableWidth = stage.clientWidth
                    - parseFloat(stageStyle.paddingLeft)
                    - parseFloat(stageStyle.paddingRight);
                const availableHeight = stage.clientHeight
                    - parseFloat(stageStyle.paddingTop)
                    - parseFloat(stageStyle.paddingBottom);

                const width = rotated ? currentHeight : currentWidth;
                const height = rotated ? currentWidth : currentHeight;
                const shellWidth = width + 28;
                const shellHeight = height + 28;
                const fittedScale = Math.min(
                    1,
                    availableWidth / shellWidth,
                    availableHeight / shellHeight
                );

                applyScale(fittedScale * 100);
            });
        };

        const updateDimensions = () => {
            const width = rotated ? currentHeight : currentWidth;
            const height = rotated ? currentWidth : currentHeight;

            root.style.setProperty("--device-width", `${width}px`);
            root.style.setProperty("--device-height", `${height}px`);
            currentDeviceSize.textContent = `${width} × ${height}`;
            fitZoomToStage();
        };

        deviceButtons.forEach((button) => {
            button.addEventListener("click", () => {
                deviceButtons.forEach((item) => item.classList.remove("is-active"));
                button.classList.add("is-active");

                currentWidth = Number(button.dataset.width);
                currentHeight = Number(button.dataset.height);
                rotated = false;

                deviceInput.value = button.dataset.device;
                currentDeviceName.textContent = button.querySelector(".device-name").textContent;

                deviceShell.classList.toggle("is-tablet", button.dataset.type === "tablet");
                updateDimensions();
            });
        });

        scaleRange.addEventListener("input", () => {
            applyScale(Number(scaleRange.value));
        });

        rotateButton.addEventListener("click", () => {
            rotated = !rotated;
            updateDimensions();
        });

        reloadButton.addEventListener("click", () => {
            frame.src = frame.src;
        });

        window.addEventListener("resize", fitZoomToStage);
        window.addEventListener("orientationchange", fitZoomToStage);

        if ("ResizeObserver" in window) {
            new ResizeObserver(fitZoomToStage).observe(stage);
        }

        updateDimensions();
    })();
</script>
</body>
</html>

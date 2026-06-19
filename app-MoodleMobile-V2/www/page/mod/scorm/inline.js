(function () {
    "use strict";

    if (window.KopereScormInlineReady) {
        return;
    }
    window.KopereScormInlineReady = true;

    var attachmentExtensions = [
        "pdf", "doc", "docx", "odt", "rtf", "txt", "csv",
        "xls", "xlsx", "ods", "ppt", "pptx", "odp",
        "zip", "rar", "7z", "tar", "gz",
        "mp3", "m4a", "wav", "ogg", "mp4", "m4v", "avi", "mov", "wmv", "webm",
        "png", "jpg", "jpeg", "gif", "webp", "svg", "bmp"
    ];

    function closestLink(element) {
        while (element && element !== document) {
            if (element.tagName && element.tagName.toLowerCase() === "a") {
                return element;
            }
            element = element.parentNode;
        }

        return null;
    }

    function getCleanPath(url) {
        try {
            return decodeURIComponent(url.pathname || "").split("?")[0].split("#")[0];
        } catch (e) {
            return String(url || "").split("?")[0].split("#")[0];
        }
    }

    function hasAttachmentExtension(path) {
        var match = /\.([a-z0-9]{1,8})$/i.exec(path || "");

        if (!match) {
            return false;
        }

        return attachmentExtensions.indexOf(match[1].toLowerCase()) !== -1;
    }

    function isIgnoredHref(href) {
        return !href || /^(javascript|mailto|tel|sms):/i.test(href) || href.charAt(0) === "#";
    }

    function isAttachmentLink(link) {
        var href = link.getAttribute("href") || "";

        if (isIgnoredHref(href)) {
            return false;
        }

        if (link.hasAttribute("download") || link.getAttribute("data-kopere-open-browser") === "1") {
            return true;
        }

        var absoluteUrl;

        try {
            absoluteUrl = new URL(href, window.location.href);
        } catch (e) {
            return false;
        }

        var url = absoluteUrl.toString();
        var path = getCleanPath(absoluteUrl);

        if (/\/pluginfile\.php\//i.test(url) || /\/webservice\/pluginfile\.php\//i.test(url)) {
            return true;
        }

        if (hasAttachmentExtension(path)) {
            return true;
        }

        return false;
    }

    function buildPayload(link) {
        var href = link.getAttribute("href") || "";
        var absoluteUrl = href;

        try {
            absoluteUrl = new URL(href, window.location.href).toString();
        } catch (e) {
            absoluteUrl = href;
        }

        return {
            href: href,
            url: absoluteUrl,
            text: (link.textContent || "").trim(),
            title: link.getAttribute("title") || "",
            download: link.getAttribute("download") || "",
            pageurl: window.location.href
        };
    }

    function sendToApp(payload) {
        var message = {
            type: "kopere-scorm-offline",
            version: "SCORM_1.2",
            action: "openattachment",
            payload: payload || {},
            timecreated: Date.now()
        };

        try {
            if (window.top && window.top !== window) {
                window.top.postMessage(message, "*");
                return;
            }
        } catch (e) {
            // Try the parent frame below.
        }

        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(message, "*");
            }
        } catch (e) {
            // There is no reachable app frame.
        }
    }

    document.addEventListener("click", function (event) {
        var link = closestLink(event.target);

        if (!link || !isAttachmentLink(link)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        sendToApp(buildPayload(link));
    }, true);

    if (!window.KopereScormOriginalOpen) {
        window.KopereScormOriginalOpen = window.open;

        window.open = function (url, target, features) {
            var fakeLink = document.createElement("a");
            fakeLink.setAttribute("href", url || "");

            if (isAttachmentLink(fakeLink)) {
                sendToApp(buildPayload(fakeLink));
                return null;
            }

            return window.KopereScormOriginalOpen.call(window, url, target, features);
        };
    }

    function forceAnimatedOpacity() {
        var elements = document.querySelectorAll(".animated");

        elements.forEach(function (element) {
            element.style.setProperty("opacity", "1", "important");
        });
    }

    forceAnimatedOpacity();
    window.setInterval(forceAnimatedOpacity, 5000);
}());

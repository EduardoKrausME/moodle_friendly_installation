(function () {
    "use strict";

    // SCORM documents do not depend on the app's jQuery or same-origin access.
    window.ScormPlayerI18n = {
        init: function (params) {
            var locales = [];

            if (params && typeof params.get === "function") {
                locales.push(params.get("lang"));
            }

            try {
                if (window.parent && window.parent !== window && window.parent.I18n) {
                    var parentI18n = window.parent.I18n;
                    if (parentI18n.strings) {
                        Object.keys(parentI18n.strings).forEach(function (locale) {
                            if (!Object.prototype.hasOwnProperty.call(I18n.strings, locale)) {
                                I18n.add(locale, parentI18n.strings[locale]);
                            }
                        });
                    }
                    if (typeof parentI18n.getCurrent === "function") {
                        locales.push(parentI18n.getCurrent());
                    }
                }
            } catch (e) {
                // Ignore access denied by a cross-origin or sandboxed parent.
            }

            try {
                locales.push(window.localStorage.getItem("app_lang"));
            } catch (e) {
                // File-based playback can run without local storage access.
            }

            if (typeof navigator !== "undefined") {
                locales.push(navigator.language);
            }
            locales.push(I18n.fallback);

            for (var i = 0; i < locales.length; i++) {
                if (!locales[i]) {
                    continue;
                }

                var locale = I18n.normalize(locales[i]);
                if (Object.prototype.hasOwnProperty.call(I18n.strings, locale)) {
                    I18n.current = locale;
                    document.documentElement.setAttribute("lang", locale.replace("_", "-"));
                    return locale;
                }
            }

            I18n.current = I18n.fallback;
            document.documentElement.setAttribute("lang", I18n.fallback.replace("_", "-"));
            return I18n.current;
        }
    };
})();

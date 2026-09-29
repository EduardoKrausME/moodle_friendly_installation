(() => {
    const body = document.body;
    const root = document.documentElement;
    const header = document.querySelector('.site-header');
    const menu = document.querySelector('.menu-button');
    const nav = document.querySelector('.site-header nav');
    const headerActions = document.querySelector('.header-actions');
    const themeKey = 'mfi-theme';

    const preferredTheme = () => (
        window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    );

    const currentTheme = () => root.dataset.theme || preferredTheme();

    const syncThemeButton = button => {
        if (!button) return;
        const dark = currentTheme() === 'dark';
        button.innerHTML = '<span aria-hidden="true">' + (dark ? '☀' : '☾') + '</span>';
        button.setAttribute('aria-label', dark ? 'Ativar modo claro / Switch to light mode' : 'Ativar modo escuro / Switch to dark mode');
        button.setAttribute('title', dark ? 'Ativar modo claro / Switch to light mode' : 'Ativar modo escuro / Switch to dark mode');
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
    };

    const currentLanguage = () => root.lang && root.lang.toLowerCase().startsWith('pt') ? 'pt_br' : 'en';

    const browserLanguage = () => {
        const languages = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || 'en'];
        return /^pt(?:[-_]|$)/i.test(languages[0] || 'en') ? 'pt_br' : 'en';
    };

    const alternateUrl = language => {
        const path = window.location.pathname;
        let next = path;

        if (/\/pt_br(?:\/|$)/.test(path)) {
            next = path.replace(/\/pt_br(?=\/|$)/, '/' + language);
        } else if (/\/en(?:\/|$)/.test(path)) {
            next = path.replace(/\/en(?=\/|$)/, '/' + language);
        } else {
            next = language + '/';
        }

        return next + window.location.search + window.location.hash;
    };

    const createLanguageMenu = () => {
        if (!headerActions) return;
        const current = currentLanguage();
        const switcher = document.createElement('nav');
        switcher.className = 'language-switcher';
        switcher.setAttribute('aria-label', current === 'pt_br' ? 'Trocar idioma' : 'Change language');
        switcher.innerHTML =
            '<a class="language-flag-link' + (current === 'pt_br' ? ' active' : '') + '" href="' + alternateUrl('pt_br') + '"' +
                (current === 'pt_br' ? ' aria-current="page"' : '') +
                ' aria-label="Português (Brasil)" title="Português (Brasil)">' +
                '<span aria-hidden="true">🇧🇷</span>' +
            '</a>' +
            '<a class="language-flag-link' + (current === 'en' ? ' active' : '') + '" href="' + alternateUrl('en') + '"' +
                (current === 'en' ? ' aria-current="page"' : '') +
                ' aria-label="English" title="English">' +
                '<span aria-hidden="true">🇺🇸</span>' +
            '</a>';

        headerActions.insertBefore(switcher, headerActions.firstChild);
    };

    const createLanguageNotice = () => {
        if (!header) return;
        const current = currentLanguage();
        const preferred = browserLanguage();
        if (current === preferred) return;

        const notice = document.createElement('div');
        notice.className = 'language-notice';

        if (current === 'pt_br') {
            notice.innerHTML =
                '<div class="shell language-notice-inner">' +
                    '<span>Your browser language is not Portuguese. Would you prefer the English version?</span>' +
                    '<div><a class="language-notice-action" href="' + alternateUrl('en') + '">Switch to English →</a>' +
                    '<button type="button" class="language-notice-close" aria-label="Dismiss">×</button></div>' +
                '</div>';
        } else {
            notice.innerHTML =
                '<div class="shell language-notice-inner">' +
                    '<span>Seu navegador está configurado para Português. Deseja abrir a versão em Português?</span>' +
                    '<div><a class="language-notice-action" href="' + alternateUrl('pt_br') + '">Abrir em Português →</a>' +
                    '<button type="button" class="language-notice-close" aria-label="Fechar">×</button></div>' +
                '</div>';
        }

        header.parentNode.insertBefore(notice, header);
        notice.querySelector('.language-notice-close')?.addEventListener('click', () => notice.remove());
    };

    createLanguageNotice();
    createLanguageMenu();

    let themeButton = null;
    if (headerActions) {
        themeButton = document.createElement('button');
        themeButton.type = 'button';
        themeButton.className = 'theme-toggle';
        headerActions.insertBefore(themeButton, menu || null);

        themeButton.addEventListener('click', () => {
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            localStorage.setItem(themeKey, next);
            syncThemeButton(themeButton);
        });
        syncThemeButton(themeButton);
    }

    if (window.matchMedia) {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onSystemThemeChange = event => {
            if (localStorage.getItem(themeKey)) return;
            root.dataset.theme = event.matches ? 'dark' : 'light';
            syncThemeButton(themeButton);
        };
        if (media.addEventListener) media.addEventListener('change', onSystemThemeChange);
        else if (media.addListener) media.addListener(onSystemThemeChange);
    }

    const syncHeader = () => header?.classList.toggle('scrolled', window.scrollY > 10);
    syncHeader();
    window.addEventListener('scroll', syncHeader, {passive:true});

    menu?.addEventListener('click', () => {
        const open = !body.classList.contains('menu-open');
        body.classList.toggle('menu-open', open);
        menu.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu.textContent = open ? '×' : '☰';
    });

    nav?.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
        body.classList.remove('menu-open');
        menu?.setAttribute('aria-expanded', 'false');
        if (menu) menu.textContent = '☰';
    }));

    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', e => {
            const id = a.getAttribute('href');
            if (!id || id === '#') return;
            const target = document.querySelector(id);
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({behavior:'smooth', block:'start'});
        });
    });
})();
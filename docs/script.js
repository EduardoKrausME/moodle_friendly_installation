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
        button.setAttribute('aria-label', dark ? 'Ativar modo claro' : 'Ativar modo escuro');
        button.setAttribute('title', dark ? 'Ativar modo claro' : 'Ativar modo escuro');
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
    };

    let themeButton = null;
    if (headerActions) {
        themeButton = document.createElement('button');
        themeButton.type = 'button';
        themeButton.className = 'theme-toggle';
        headerActions.insertBefore(themeButton, menu || headerActions.firstChild);

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
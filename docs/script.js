(() => {
    const body = document.body;
    const header = document.querySelector('.site-header');
    const menu = document.querySelector('.menu-button');
    const nav = document.querySelector('.site-header nav');

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
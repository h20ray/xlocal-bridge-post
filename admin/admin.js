(function () {
    const root = document.querySelector('.xlocal-admin');
    if (!root) {
        return;
    }

    const tabs = root.querySelectorAll('.xlocal-tab');
    const panels = root.querySelectorAll('.xlocal-tab-panel');

    function activateTab(id) {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.tab === id;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });
        panels.forEach((panel) => {
            const isActive = panel.dataset.tabPanel === id;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    if (tabs.length) {
        const active = root.querySelector('.xlocal-tab.is-active');
        const initialId = active && active.dataset.tab ? active.dataset.tab : tabs[0].dataset.tab;
        activateTab(initialId);
        tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                activateTab(tab.dataset.tab);
            });
        });
    }

    const helpWraps = root.querySelectorAll('.xlocal-help');
    const closeAllHelp = function () {
        helpWraps.forEach((wrap) => wrap.classList.remove('is-open'));
    };

    root.querySelectorAll('.xlocal-help-icon').forEach((icon) => {
        icon.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const wrap = icon.closest('.xlocal-help');
            if (wrap) {
                const willOpen = !wrap.classList.contains('is-open');
                closeAllHelp();
                if (willOpen) {
                    wrap.classList.add('is-open');
                }
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.xlocal-help')) {
            closeAllHelp();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllHelp();
        }
    });

    root.querySelectorAll('.xlocal-help-text').forEach((tooltip) => {
        tooltip.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    root.querySelectorAll('.xlocal-doc-pill[data-doc-target]').forEach((pill) => {
        pill.addEventListener('click', () => {
            const targetId = pill.getAttribute('data-doc-target');
            if (!targetId) {
                return;
            }
            const target = root.querySelector('#' + CSS.escape(targetId));
            if (!target) {
                return;
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
})();

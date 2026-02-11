(function () {
    const tabs = document.querySelectorAll('.xlocal-tab');
    const panels = document.querySelectorAll('.xlocal-tab-panel');

    function activateTab(id) {
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.tab === id);
        });
        panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.tabPanel === id);
        });
    }

    if (tabs.length) {
        const first = tabs[0].dataset.tab;
        activateTab(first);
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => activateTab(tab.dataset.tab));
        });
    }

    document.querySelectorAll('.xlocal-help-icon').forEach((icon) => {
        icon.addEventListener('click', (event) => {
            event.preventDefault();
            const wrap = icon.closest('.xlocal-help');
            if (wrap) {
                wrap.classList.toggle('is-open');
            }
        });
    });
})();

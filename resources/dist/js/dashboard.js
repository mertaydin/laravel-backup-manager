(function () {
    function getPreference() {
        try { return localStorage.getItem('backup-manager-theme') || 'system'; }
        catch (error) { return 'system'; }
    }
    function resolveTheme(preference) {
        return preference === 'system'
            ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
            : preference;
    }
    function applyTheme(preference) {
        var button = document.getElementById('theme-toggle');
        var label = document.getElementById('theme-label');
        document.documentElement.dataset.theme = resolveTheme(preference);
        if (button && label) {
            var text = button.getAttribute('data-theme-' + preference) || button.getAttribute('data-theme-system');
            label.textContent = text;
            button.setAttribute('aria-label', text);
        }
    }
    var selected = getPreference();
    applyTheme(selected);
    var button = document.getElementById('theme-toggle');
    if (button) {
        button.addEventListener('click', function () {
            var order = ['system', 'light', 'dark'];
            selected = order[(order.indexOf(selected) + 1) % order.length];
            try { localStorage.setItem('backup-manager-theme', selected); } catch (error) {}
            applyTheme(selected);
        });
    }
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', function () {
            if (selected === 'system') applyTheme('system');
        });
    }
})();

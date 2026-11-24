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

(function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || 'Delete this backup?';
            if (!window.confirm(message)) event.preventDefault();
        });
    });

    var status = document.getElementById('backup-job-status');
    if (!status) return;

    var url = status.getAttribute('data-status-url');
    var attempts = 0;
    function poll() {
        attempts += 1;
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to retrieve backup status.');
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'completed') {
                    status.textContent = status.getAttribute('data-completed') + (data.backup_id ? ' · ' + data.backup_id : '');
                    status.classList.add('is-complete');
                    window.setTimeout(function () { window.location.reload(); }, 1800);
                    return;
                }
                if (data.status === 'failed') {
                    status.textContent = status.getAttribute('data-failed') + (data.error ? ' · ' + data.error : '');
                    status.classList.add('is-failed');
                    return;
                }
                status.textContent = data.status === 'running'
                    ? status.getAttribute('data-running')
                    : status.textContent;
                if (attempts < 600) window.setTimeout(poll, 2000);
            })
            .catch(function () {
                if (attempts < 600) window.setTimeout(poll, 3000);
            });
    }
    poll();
})();

(function () {
    document.querySelectorAll('[data-locale-switcher]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.value) window.location.assign(select.value);
        });
    });
})();


(function () {
    var count = document.getElementById('destination-selected-count');
    var form = document.querySelector('.destination-form');
    if (!count || !form) return;

    function updateSelection(input) {
        var option = input.closest('.destination-option');
        if (!option) return;

        var selected = input.checked;
        option.classList.toggle('is-selected', selected);

        var state = option.querySelector('.destination-state-text');
        if (state) state.textContent = selected ? 'Seçildi' : 'Seç';

        count.textContent = form.querySelectorAll('input[name="targets[]"]:checked').length;
    }

    form.querySelectorAll('input[name="targets[]"]').forEach(function (input) {
        input.addEventListener('change', function () {
            updateSelection(input);
        });
    });
})();

(function () {
    var search = document.querySelector('input[name="q"][type="search"]');
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
        var target = event.target;
        if (target && (target.isContentEditable || /INPUT|TEXTAREA|SELECT/.test(target.tagName))) return;
        if (!search) return;
        event.preventDefault();
        search.focus();
        search.select();
    });

    var countdown = document.getElementById('next-backup-countdown');
    if (!countdown) return;
    var target = Number(countdown.getAttribute('data-target')) * 1000;
    function renderCountdown() {
        var remaining = Math.max(0, target - Date.now());
        var seconds = Math.floor(remaining / 1000);
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        countdown.textContent = days + 'd ' + hours + 'h ' + minutes + 'm ' + secs + 's';
    }
    renderCountdown();
    window.setInterval(renderCountdown, 1000);
})();

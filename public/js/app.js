(function () {
    'use strict';

    var toggle = document.querySelector('[data-nav-toggle]');
    var sidebar = document.querySelector('[data-sidebar]');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (
                sidebar.classList.contains('is-open') &&
                !sidebar.contains(event.target) &&
                !toggle.contains(event.target)
            ) {
                sidebar.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var msg = form && form.getAttribute ? form.getAttribute('data-confirm') : null;
        if (msg && !window.confirm(msg)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('[data-copy-target]') : null;
        if (!btn) {
            return;
        }
        var targetId = btn.getAttribute('data-copy-target');
        var el = targetId ? document.getElementById(targetId) : null;
        if (!el) {
            return;
        }
        var text = el.textContent ? el.textContent.trim() : '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied';
            });
        }
    });
})();

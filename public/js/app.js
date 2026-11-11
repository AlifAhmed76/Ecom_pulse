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

    var dashboardRoot = document.getElementById('dashboard-root');
    if (dashboardRoot) {
        var metricsUrl = dashboardRoot.getAttribute('data-metrics-url');
        if (metricsUrl && window.fetch) {
            window.setInterval(function () {
                window.fetch(metricsUrl, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                }).then(function (resp) {
                    if (resp.status === 200) {
                        return resp.json();
                    }
                    return null;
                }).then(function (data) {
                    if (!data) {
                        return;
                    }
                    var nodes = dashboardRoot.querySelectorAll('[data-metric]');
                    for (var i = 0; i < nodes.length; i++) {
                        var key = nodes[i].getAttribute('data-metric');
                        if (Object.prototype.hasOwnProperty.call(data, key)) {
                            nodes[i].textContent = Number(data[key]).toLocaleString();
                        }
                    }
                    var updatedEl = document.getElementById('dashboard-updated');
                    if (updatedEl && data.updated_hhmm) {
                        updatedEl.textContent = 'Updated ' + data.updated_hhmm;
                    }
                }).catch(function () {});
            }, 60000);
        }
    }

    function renderCharts() {
        if (typeof window.Chart !== 'function') {
            return;
        }
        var canvases = document.querySelectorAll('canvas[data-chart-source]');
        for (var i = 0; i < canvases.length; i++) {
            var canvas = canvases[i];
            var srcId = canvas.getAttribute('data-chart-source');
            var scriptEl = srcId ? document.getElementById(srcId) : null;
            if (!scriptEl) {
                continue;
            }
            try {
                var cfg = JSON.parse(scriptEl.textContent || '{}');
                new window.Chart(canvas.getContext('2d'), cfg);
            } catch (e) {
                // Ignore malformed chart payloads
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCharts);
    } else {
        renderCharts();
    }
})();

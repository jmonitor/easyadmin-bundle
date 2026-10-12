(function () {
    'use strict';

    var STORAGE_KEY = 'jmonitor_easyadmin.live';
    var root = document.querySelector('[data-live]');

    if (!root) {
        return;
    }

    var url = root.getAttribute('data-live-url');
    var interval = Math.max(1, parseInt(root.getAttribute('data-live-interval'), 10) || 10) * 1000;
    var content = root.querySelector('[data-live-content]');
    var toggle = root.querySelector('[data-live-toggle]');
    var enabled = readPreference();
    var timer = null;
    var inFlight = false;

    function readPreference() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) !== 'off';
        } catch (e) {
            return true;
        }
    }

    function writePreference(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value ? 'on' : 'off');
        } catch (e) {
            // private mode or blocked storage: the preference only lasts for this page
        }
    }

    function renderToggle() {
        if (!toggle) {
            return;
        }

        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        toggle.classList.toggle('active', enabled);
        toggle.textContent = enabled ? 'Live' : 'Live (paused)';
    }

    function refresh() {
        if (inFlight || document.hidden) {
            return;
        }

        inFlight = true;

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var deadline = controller ? window.setTimeout(function () { controller.abort(); }, interval * 2) : null;

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }, signal: controller ? controller.signal : undefined })
            .then(function (response) {
                if (response.redirected || response.status === 401 || response.status === 403) {
                    // the session is gone: let the firewall show its login page instead of injecting it here
                    stop();
                    window.location.reload();

                    return null;
                }

                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (html !== null) {
                    content.innerHTML = html;
                }
            })
            .catch(function () {
                // network hiccup or timeout: keep the current content, try again next tick
            })
            .then(function () {
                if (deadline !== null) {
                    window.clearTimeout(deadline);
                }

                inFlight = false;
            });
    }

    function start() {
        stop();

        if (enabled) {
            timer = window.setInterval(refresh, interval);
        }
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            enabled = !enabled;
            writePreference(enabled);
            renderToggle();
            start();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            start();
            refresh();
        }
    });

    renderToggle();
    start();
})();

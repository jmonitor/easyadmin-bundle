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
    var updated = root.querySelector('[data-live-updated]');
    var enabled = readPreference();
    var timer = null;
    var inFlight = false;
    var updatedAt = Date.now();

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

        toggle.checked = enabled;
    }

    function ago(milliseconds) {
        var seconds = Math.floor(milliseconds / 1000);

        if (seconds < 5) {
            return 'just now';
        }

        if (seconds < 60) {
            return seconds + ' s ago';
        }

        if (seconds < 3600) {
            return Math.floor(seconds / 60) + ' min ago';
        }

        if (seconds < 86400) {
            return Math.floor(seconds / 3600) + ' h ago';
        }

        return Math.floor(seconds / 86400) + ' d ago';
    }

    function renderUpdated() {
        if (updated) {
            updated.textContent = ago(Date.now() - updatedAt);
        }
    }

    function markUpdated() {
        updatedAt = Date.now();

        if (updated) {
            updated.dateTime = new Date(updatedAt).toISOString();
        }

        renderUpdated();
    }

    function replaceContent(html) {
        var activeTabs = Array.prototype.map.call(content.querySelectorAll('[data-bs-toggle="tab"].active'), function (tab) {
            return tab.getAttribute('data-bs-target');
        });
        var scrolls = Array.prototype.map.call(content.querySelectorAll('.jm-scroll, .jm-query'), function (element) {
            return element.scrollTop;
        });

        content.innerHTML = html;

        activeTabs.forEach(function (target) {
            var tab = content.querySelector('[data-bs-toggle="tab"][data-bs-target="' + target + '"]');
            var pane = content.querySelector(target);

            if (!tab || !pane) {
                return;
            }

            Array.prototype.forEach.call(tab.closest('.nav').querySelectorAll('[data-bs-toggle="tab"]'), function (other) {
                other.classList.remove('active');
                other.setAttribute('aria-selected', 'false');
            });
            Array.prototype.forEach.call(pane.parentElement.children, function (other) {
                other.classList.remove('active');
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            pane.classList.add('active');
        });

        Array.prototype.forEach.call(content.querySelectorAll('.jm-scroll, .jm-query'), function (element, index) {
            if (scrolls[index]) {
                element.scrollTop = scrolls[index];
            }
        });
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
                    replaceContent(html);
                    markUpdated();
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
        toggle.addEventListener('change', function () {
            enabled = toggle.checked;
            writePreference(enabled);
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
    markUpdated();
    window.setInterval(renderUpdated, 1000);
    start();
})();

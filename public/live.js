(function () {
    'use strict';

    var STORAGE_KEY = 'jmonitor_easyadmin.live';
    var SCROLLABLE = '.jm-scroll, .jm-query, .table-responsive';
    var root = document.querySelector('[data-live]');

    if (!root) {
        return;
    }

    var url = root.getAttribute('data-live-url');
    var interval = Math.max(1, parseInt(root.getAttribute('data-live-interval'), 10) || 10) * 1000;
    var content = root.querySelector('[data-live-content]');
    var toggle = root.querySelector('[data-live-toggle]');
    var updated = root.querySelector('[data-live-updated]');
    var indexUrl = root.getAttribute('data-live-index-url');
    var status = root.querySelector('[data-live-status]');
    var failedLabel = root.querySelector('[data-live-failed]');
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

    function markFailed(failed) {
        if (!status) {
            return;
        }

        status.classList.toggle('text-muted', !failed);
        status.classList.toggle('text-warning-emphasis', failed);

        if (failedLabel) {
            failedLabel.hidden = !failed;
        }
    }

    function hasSelectionInside(element) {
        var selection = window.getSelection ? window.getSelection() : null;

        return selection !== null && !selection.isCollapsed && selection.containsNode(element, true);
    }

    // replacing the content would close an open drop-down
    function hasFocusedSelectInside(element) {
        var active = document.activeElement;

        return active !== null && active.tagName === 'SELECT' && element.contains(active);
    }

    function replaceContent(html) {
        var activeTabs = Array.prototype.map.call(content.querySelectorAll('[data-bs-toggle="tab"].active'), function (tab) {
            return tab.getAttribute('data-bs-target');
        });
        var scrolls = Array.prototype.map.call(content.querySelectorAll(SCROLLABLE), function (element) {
            return { top: element.scrollTop, left: element.scrollLeft };
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

        var scrollables = content.querySelectorAll(SCROLLABLE);

        // positions are matched by rank: a block that appeared or vanished would shift them all
        if (scrollables.length !== scrolls.length) {
            return;
        }

        Array.prototype.forEach.call(scrollables, function (element, index) {
            element.scrollTop = scrolls[index].top;
            element.scrollLeft = scrolls[index].left;
        });
    }

    function refresh() {
        if (!enabled || inFlight || document.hidden || hasSelectionInside(content) || hasFocusedSelectInside(content)) {
            return;
        }

        inFlight = true;

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var deadline = controller ? window.setTimeout(function () { controller.abort(); }, interval * 2) : null;

        fetch(url, { credentials: 'same-origin', redirect: 'manual', signal: controller ? controller.signal : undefined })
            .then(function (response) {
                // a redirect or a denial means the session is gone: the firewall shows its login page on reload
                if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) {
                    stop();
                    window.location.reload();

                    return null;
                }

                if (response.status === 404) {
                    stop();
                    window.location.assign(indexUrl || window.location.href);

                    return null;
                }

                markFailed(!response.ok);

                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (html === null || !enabled || hasSelectionInside(content) || hasFocusedSelectInside(content)) {
                    return;
                }

                replaceContent(html);
                markUpdated();
            })
            .catch(function () {
                markFailed(true);
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
            refresh();
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

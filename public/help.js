(function () {
    'use strict';

    var panel = document.querySelector('[data-jm-help-panel]');

    if (!panel || !window.bootstrap || !window.bootstrap.Offcanvas) {
        return;
    }

    // a transformed ancestor would turn the fixed offcanvas into a box inside the page
    document.body.appendChild(panel);

    var frame = panel.querySelector('[data-jm-help-frame]');
    var external = panel.querySelector('[data-jm-help-external]');
    var offcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(panel);

    function themedUrl(href) {
        var url = new URL(href, window.location.href);
        url.searchParams.set('theme', document.body.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');

        return url.toString();
    }

    // delegated: the polling replaces the links
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target instanceof Element ? event.target.closest('a[data-jm-help]') : null;

        if (!link) {
            return;
        }

        event.preventDefault();

        var url = themedUrl(link.href);

        if (frame.getAttribute('src') !== url) {
            frame.setAttribute('src', url);
        }

        external.href = url;
        offcanvas.show();
    });
})();

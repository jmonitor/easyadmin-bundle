(function () {
    'use strict';

    // Longer than the gap between a key and the change it causes, far shorter than picking in an open list.
    var KEY_CHANGE_DELAY = 100;
    var lastKeyAt = -Infinity;

    // on <html>: the polling replaces the content, so a class inside it would be lost
    document.documentElement.className += ' jm-navigate-js';

    function navigationSelect(target) {
        return target && target.tagName === 'SELECT' && target.hasAttribute('data-jm-navigate') && target.form ? target : null;
    }

    // delegated: the polling replaces the content, and the select with it
    document.addEventListener('keydown', function (event) {
        var select = navigationSelect(event.target);

        if (!select) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            select.form.submit();

            return;
        }

        lastKeyAt = event.timeStamp;
    });

    document.addEventListener('change', function (event) {
        var select = navigationSelect(event.target);

        if (!select) {
            return;
        }

        // Chrome and Edge on Windows change a closed list with the arrow keys: going to the database
        // at each key would move a keyboard user away (WCAG 3.2.2), Enter or the button goes there.
        if (event.timeStamp - lastKeyAt < KEY_CHANGE_DELAY) {
            select.form.classList.add('jm-navigate-pending');

            return;
        }

        select.form.submit();
    });
})();

(function () {
    'use strict';

    // delegated: the polling replaces the content, and the select with it
    document.addEventListener('change', function (event) {
        var select = event.target;

        if (!select || select.tagName !== 'SELECT' || !select.hasAttribute('data-jm-navigate') || !select.form) {
            return;
        }

        select.form.submit();
    });
})();

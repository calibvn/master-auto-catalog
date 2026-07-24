(function () {
    'use strict';

    function sendSearchTerm(value) {
        if (!value || !window.macSearchLogs || !window.macSearchLogs.endpoint) {
            return;
        }

        var body = 'query=' + encodeURIComponent(value);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(
                window.macSearchLogs.endpoint,
                new Blob([body], { type: 'application/x-www-form-urlencoded;charset=UTF-8' })
            );
            return;
        }

        fetch(window.macSearchLogs.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body
        }).catch(function () {});
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.querySelector) {
            return;
        }

        var input = form.querySelector('input[name="s"], textarea[name="s"]');
        if (input) {
            sendSearchTerm(input.value.trim());
        }
    }, true);
}());

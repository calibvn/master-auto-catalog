(function () {
    'use strict';

    var config = window.vinFallbackAdmin || {};

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else if (key === 'open' || key === 'hidden') {
                node[key] = !!attrs[key];
            } else {
                node.setAttribute(key, attrs[key]);
            }
        });
        (children || []).forEach(function (child) {
            if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    /* ---------- Template token buttons ---------- */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.vin-template-token');
        if (!button) return;
        var input = document.getElementById(button.getAttribute('data-target'));
        if (!input) return;
        var token = button.getAttribute('data-token') || '';
        var start = input.selectionStart || input.value.length;
        var end = input.selectionEnd || input.value.length;
        var prefix = input.value.slice(0, start);
        var suffix = input.value.slice(end);
        var spacer = prefix && !/\s$/.test(prefix) ? ' ' : '';
        input.value = prefix + spacer + token + suffix;
        input.focus();
        var pos = (prefix + spacer + token).length;
        input.setSelectionRange(pos, pos);
    });

    /* ---------- Provider order ---------- */

    var list = document.getElementById('vfs-providers');
    var orderInput = document.getElementById('vfs-provider-order');

    function syncOrder() {
        var items = list.querySelectorAll('.vfs-provider');
        var ids = [];
        items.forEach(function (item, index) {
            ids.push(item.getAttribute('data-id'));
            item.querySelector('.vfs-position').textContent = index + 1;
            item.querySelector('.vfs-move-up').disabled = index === 0;
            item.querySelector('.vfs-move-down').disabled = index === items.length - 1;
        });
        orderInput.value = ids.join(',');
    }

    if (list && orderInput) {
        list.addEventListener('click', function (event) {
            var button = event.target.closest('.vfs-move-up, .vfs-move-down');
            if (!button) return;
            var item = button.closest('.vfs-provider');
            if (button.classList.contains('vfs-move-up') && item.previousElementSibling) {
                list.insertBefore(item, item.previousElementSibling);
            } else if (button.classList.contains('vfs-move-down') && item.nextElementSibling) {
                list.insertBefore(item.nextElementSibling, item);
            }
            syncOrder();
            button.focus();
        });
        syncOrder();
    }

    /* ---------- VIN test ---------- */

    var STATUS = {
        found: {label: 'Найдено', cls: 'good'},
        not_found: {label: 'Не найдено', cls: 'muted'},
        error: {label: 'Ошибка', cls: 'bad'},
        disabled: {label: 'Выключен', cls: 'off'},
        not_configured: {label: 'Не заполнены доступы', cls: 'warn'}
    };

    function prettyBody(body) {
        if (body === null || body === undefined || body === '') return '(пусто)';
        if (typeof body !== 'string') return JSON.stringify(body, null, 2);
        try {
            return JSON.stringify(JSON.parse(body), null, 2);
        } catch (e) {
            return body;
        }
    }

    function headerLines(headers) {
        return Object.keys(headers || {}).map(function (name) {
            var value = headers[name];
            return name + ': ' + (Array.isArray(value) ? value.join(', ') : value);
        }).join('\n') || '(нет)';
    }

    function bodyText(body) {
        if (body && typeof body === 'object') {
            return Object.keys(body).map(function (k) { return k + '=' + body[k]; }).join('\n');
        }
        return body === null || body === undefined || body === '' ? '(пусто)' : String(body);
    }

    function isFailedExchange(exchange) {
        return !!exchange.error || !exchange.response || exchange.response.status < 200 || exchange.response.status >= 300;
    }

    function exchangeSummary(exchange) {
        var outcome = exchange.error
            ? 'network error'
            : exchange.response.status + ' ' + (exchange.response.message || '');
        return exchange.request.method + ' ' + exchange.request.url + '  →  ' + outcome.trim() + ' · ' + exchange.elapsed_ms + ' ms';
    }

    function codeBlock(title, text) {
        return el('div', {class: 'vfs-code'}, [
            el('div', {class: 'vfs-code-title', text: title}),
            el('pre', {text: text})
        ]);
    }

    function renderExchange(exchange, index) {
        var failed = isFailedExchange(exchange);
        var parts = [
            el('h4', {text: 'Что отправили'}),
            codeBlock('Заголовки', headerLines(exchange.request.headers)),
            codeBlock('Тело запроса', bodyText(exchange.request.body)),
            codeBlock('То же самое командой curl', exchange.request.curl),
            el('h4', {text: 'Что ответил сервер'})
        ];

        if (exchange.error) {
            parts.push(el('div', {class: 'vfs-error', text: 'Ответа нет: ' + exchange.error}));
        } else {
            var res = exchange.response;
            parts.push(codeBlock('Статус', 'HTTP ' + res.status + ' ' + (res.message || '')));
            parts.push(codeBlock('Заголовки', headerLines(res.headers)));
            parts.push(codeBlock('Тело ответа (' + res.body_bytes + ' байт)', prettyBody(res.body)));
        }

        return el('details', {class: 'vfs-exchange' + (failed ? ' is-failed' : ''), open: failed}, [
            el('summary', {}, [
                el('span', {class: 'vfs-exchange-num', text: '#' + (index + 1)}),
                el('span', {class: 'vfs-exchange-line', text: exchangeSummary(exchange)})
            ]),
            el('div', {class: 'vfs-exchange-body'}, parts)
        ]);
    }

    function supportReport(meta, provider) {
        var lines = [
            'VIN API diagnostic report',
            '=========================',
            'Provider: ' + provider.label + ' (' + provider.class + ')',
            'Site: ' + meta.site,
            'Tested at (UTC): ' + meta.tested_at_utc,
            'VIN: ' + meta.vin,
            'Result: ' + provider.status + (provider.error ? ' - ' + provider.error : ''),
            'Total time: ' + provider.elapsed_ms + ' ms',
            'Client: WordPress ' + meta.wordpress + ', PHP ' + meta.php,
            'Note: secrets are masked as first4…last4 (length).'
        ];

        (provider.http || []).forEach(function (exchange, index) {
            var n = index + 1;
            lines.push('', '--- Request #' + n + ' ---');
            lines.push('Time (UTC): ' + exchange.time_utc);
            lines.push(exchange.request.method + ' ' + exchange.request.url);
            lines.push('Headers:', headerLines(exchange.request.headers));
            lines.push('Body:', bodyText(exchange.request.body));
            lines.push('curl:', exchange.request.curl);
            lines.push('', '--- Response #' + n + ' (' + exchange.elapsed_ms + ' ms) ---');
            if (exchange.error) {
                lines.push('No response: ' + exchange.error);
            } else {
                lines.push('HTTP ' + exchange.response.status + ' ' + (exchange.response.message || ''));
                lines.push('Headers:', headerLines(exchange.response.headers));
                lines.push('Body (' + exchange.response.body_bytes + ' bytes):', prettyBody(exchange.response.body));
            }
        });

        return lines.join('\n');
    }

    function copyText(text, button) {
        var done = function () {
            var original = button.textContent;
            button.textContent = 'Скопировано';
            setTimeout(function () { button.textContent = original; }, 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        var area = el('textarea', {style: 'position:fixed;left:-9999px'});
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        done();
    }

    function renderProvider(meta, provider) {
        var status = STATUS[provider.status] || {label: provider.status, cls: 'muted'};
        var ran = provider.status !== 'disabled' && provider.status !== 'not_configured';
        var head = el('div', {class: 'vfs-result-head'}, [
            el('strong', {text: provider.label}),
            el('span', {class: 'vfs-badge vfs-badge-' + status.cls, text: status.label}),
            ran ? el('span', {class: 'vfs-result-time', text: provider.elapsed_ms + ' ms · запросов: ' + (provider.http || []).length}) : null
        ]);

        var body = [];
        if (provider.error) {
            body.push(el('div', {class: 'vfs-error', text: provider.error}));
        }
        if (provider.status === 'not_found') {
            body.push(el('p', {class: 'vfs-hint', text: 'Сервер ответил без ошибки, но авто по этому VIN не вернул. Если оно точно есть у провайдера, отправьте отчёт в поддержку.'}));
        }
        if (provider.status === 'found' && provider.item) {
            body.push(el('dl', {class: 'vfs-found'}, [
                el('dt', {text: 'Название'}), el('dd', {text: provider.generated_title || ''}),
                el('dt', {text: 'Slug'}), el('dd', {text: provider.generated_slug || ''}),
                el('dt', {text: 'Цена'}), el('dd', {text: String(provider.item.price || 0)}),
                el('dt', {text: 'Фото'}), el('dd', {text: String((provider.item.images || []).length)})
            ]));
            body.push(el('details', {class: 'vfs-exchange'}, [
                el('summary', {text: 'Данные товара после обработки (JSON)'}),
                el('pre', {class: 'vfs-json', text: JSON.stringify(provider.item, null, 2)})
            ]));
        }
        (provider.http || []).forEach(function (exchange, index) {
            body.push(renderExchange(exchange, index));
        });
        if (ran) {
            var copyButton = el('button', {type: 'button', class: 'button', text: 'Скопировать для поддержки'});
            copyButton.addEventListener('click', function () { copyText(supportReport(meta, provider), copyButton); });
            body.push(el('div', {class: 'vfs-result-actions'}, [copyButton]));
        }

        return el('div', {class: 'vfs-result vfs-result-' + status.cls}, [head, body.length ? el('div', {class: 'vfs-result-body'}, body) : null]);
    }

    function renderResult(container, payload) {
        container.textContent = '';
        if (!payload || !payload.success) {
            var message = payload && payload.data && payload.data.message ? payload.data.message : 'Неизвестная ошибка';
            container.appendChild(el('div', {class: 'vfs-error', text: message}));
            return;
        }

        var meta = payload.data;
        container.appendChild(el('p', {class: 'vfs-test-meta', text: 'VIN ' + meta.vin + ' · ' + meta.tested_at_utc + ' UTC'}));
        meta.providers.forEach(function (provider) {
            container.appendChild(renderProvider(meta, provider));
        });
        container.appendChild(el('details', {class: 'vfs-exchange'}, [
            el('summary', {text: 'Весь ответ теста (JSON)'}),
            el('pre', {class: 'vfs-json', text: JSON.stringify(payload, null, 2)})
        ]));
    }

    var testButton = document.getElementById('vin-api-test-button');
    var testInput = document.getElementById('vin-api-test-input');
    var testResult = document.getElementById('vin-api-test-result');

    function runTest() {
        var vin = (testInput.value || '').replace(/\s+/g, '').toUpperCase();
        testInput.value = vin;
        testResult.hidden = false;
        testResult.textContent = 'Опрашиваем источники…';
        testButton.disabled = true;

        var body = new URLSearchParams();
        body.append('action', 'vin_fallback_test_provider');
        body.append('nonce', config.nonce || '');
        body.append('vin', vin);

        fetch(config.ajaxUrl || window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        return {success: false, data: {message: 'HTTP ' + response.status + ': ' + text.slice(0, 500)}};
                    }
                });
            })
            .then(function (payload) {
                if (payload && payload.success === false && payload.data && payload.data.message === 'Invalid VIN format') {
                    payload.data.message = 'Неверный VIN: нужно 17 символов, латиница и цифры, без I, O, Q.';
                }
                renderResult(testResult, payload);
            })
            .catch(function (error) { testResult.textContent = String(error); })
            .finally(function () { testButton.disabled = false; });
    }

    if (testButton && testInput && testResult) {
        testButton.addEventListener('click', runTest);
        testInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                runTest();
            }
        });
    }
})();

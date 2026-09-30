/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors */
/* SPDX-License-Identifier: GPL-3.0-or-later */

(() => {
    'use strict';

    const form = document.querySelector('form[data-timezone-url]');
    if (!form) {
        return;
    }

    const timezone = form.querySelector('[name="collector_edit[timezone]"]');
    const timezoneOptions = document.getElementById('collector-timezones');
    if (timezone && timezoneOptions) {
        let timer;
        timezone.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(async () => {
                const url = new URL(form.dataset.timezoneUrl, window.location.href);
                url.searchParams.set('term', timezone.value);
                try {
                    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                    if (!response.ok) {
                        return;
                    }
                    const options = await response.json();
                    timezoneOptions.replaceChildren(...options.map(({value}) => {
                        const option = document.createElement('option');
                        option.value = value;
                        return option;
                    }));
                } catch {
                    timezoneOptions.replaceChildren();
                }
            }, 150);
        });
    }

    const button = form.querySelector('[data-collector-connection-test]');
    const result = form.querySelector('[data-collector-connection-result]');
    if (button && result) {
        button.addEventListener('click', async () => {
            const body = new FormData();
            const fields = new FormData(form);
            for (const name of ['dbhost', 'dbuser', 'dbpass', 'dbdefault', 'dbport', 'dbretries', 'dbssl', 'dbsslkey', 'dbsslcert', 'dbsslca', 'revision']) {
                const key = `collector_edit[${name}]`;
                if (fields.has(key)) {
                    body.set(key, fields.get(key));
                }
            }
            body.set('connection_token', form.querySelector('[name="connection_token"]').value);
            if (form.dataset.collectorId !== '') {
                body.set('collector_id', form.dataset.collectorId);
            }
            const ssl = form.querySelector('[name="collector_edit[dbssl]"]');
            if (ssl && !ssl.checked) {
                body.set('collector_edit[dbssl]', '');
            }
            button.disabled = true;
            result.textContent = '';
            try {
                const response = await fetch(form.dataset.connectionUrl, {method: 'POST', body, headers: {'Accept': 'application/json'}});
                const payload = await response.json();
                result.textContent = payload.message || 'Connection test failed.';
            } catch {
                result.textContent = 'Connection test failed.';
            } finally {
                button.disabled = false;
            }
        });
    }
})();

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
'use strict';

const installer = document.getElementById('installer');
if (installer) {
    const error = document.getElementById('installer-error');
    let dirty = false;
    let submitting = false;
    const local = (value) => {
        const url = new URL(value, window.location.href);
        if (url.origin !== window.location.origin) {
            throw new Error('Invalid installer destination.');
        }
        return url;
    };
    document.querySelectorAll('[data-installer-form]').forEach((form) => {
        // Reveal optional controls before native validation moves focus.
        form.addEventListener('invalid', (event) => {
            if (!(event.target instanceof HTMLElement)) return;
            let group = event.target.closest('details');
            while (group) {
                group.open = true;
                group = group.parentElement?.closest('details');
            }
        }, true);
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitting || !form.reportValidity()) return;
            submitting = true;
            const buttons = Array.from(form.querySelectorAll('button'));
            buttons.forEach((button) => { button.disabled = true; });
            try {
                // The native action field shadows HTMLFormElement.action.
                const destination = local(form.getAttribute('action'));
                const send = () => fetch(destination, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    redirect: 'error',
                });
                let response = await send();
                let data = await response.json();
                // Only this explicit pre-dispatch rejection permits one retry.
                // A timeout or an unknown outcome must be reconciled via status.
                if (response.status === 403 && data.error === 'csrf_expired' && typeof data.token === 'string') {
                    document.querySelectorAll('input[name="__csrf_magic"]').forEach((input) => { input.value = data.token; });
                    response = await send();
                    data = await response.json();
                }
                if (!response.ok || data.status !== 'saved') {
                    throw new Error(typeof data.error === 'string' ? data.error
                        : typeof data.responseText === 'string' ? data.responseText : installer.dataset.requestRejected);
                }
                dirty = false;
                window.location.assign(destination);
            } catch (failure) {
                error.textContent = failure instanceof Error && !(failure instanceof TypeError) && !(failure instanceof SyntaxError)
                    ? failure.message : installer.dataset.unknownOutcome;
            } finally {
                submitting = false;
                buttons.forEach((button) => { button.disabled = false; });
            }
        });
    });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    const progress = document.querySelector('[data-installer-progress]');
    if (progress && ['waiting', 'running'].includes(progress.dataset.state)) {
        let failures = 0;
        const poll = async () => {
            try {
                const response = await fetch(local(installer.dataset.statusUrl), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Unable to read installation status.');
                const state = await response.json();
                if (typeof state.state !== 'string' || typeof state.phase !== 'string' || !Number.isInteger(state.progress)) {
                    throw new Error('Invalid installation status.');
                }
                document.getElementById('installer-state').textContent = state.state + ': ' + state.phase;
                document.getElementById('installer-progress').value = Math.max(0, Math.min(100, state.progress));
                failures = 0;
                if (['waiting', 'running'].includes(state.state)) {
                    window.setTimeout(poll, 3000);
                } else if (!dirty) {
                    window.location.reload();
                }
            } catch {
                failures += 1;
                error.textContent = installer.dataset.statusUnavailable;
                if (failures < 3) window.setTimeout(poll, failures * 5000);
            }
        };
        window.setTimeout(poll, 3000);
    }
}

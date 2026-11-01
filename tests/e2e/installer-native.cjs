/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
'use strict';

// Run only against an explicitly prepared fresh, disposable native installation.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const base = new URL(process.env.INSTALLER_TEST_URL);
    assert(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Disposable localhost fixture required');
    const password = fs.readFileSync(process.env.INSTALLER_TEST_PASSWORD_FILE, 'utf8');
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROME_PATH,
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    let checks = 0;
    const verify = (condition, message) => { assert(condition, message); checks += 1; };
    try {
        const page = await browser.newPage();
        const endpoint = new URL('install/install.php', base).href;
        await page.goto(endpoint);
        await page.locator('#login_username').fill('admin');
        await page.locator('#login_password').fill(password);
        await Promise.all([page.waitForNavigation(), page.locator('button[type=submit],input[type=submit]').first().click()]);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const response = await page.goto(endpoint);
        verify(response.status() === 200, 'Authenticated installer is reachable');
        const csp = response.headers()['content-security-policy'];
        verify(csp.includes("script-src 'self'") && csp.includes("base-uri 'none'") && !csp.includes('unsafe-inline'), 'Actual response enforces installer CSP');
        const status = async () => {
            const result = await page.request.get(endpoint + '?view=status');
            verify(result.status() === 200, 'Status observation succeeds');
            return result.json();
        };
        verify((await status()).state === 'not_started', 'Fresh fixture has no worker');
        const form = page.locator('form').filter({ has: page.locator('input[value=configure]') });
        let posts = 0;
        page.on('request', request => { if (request.method() === 'POST' && request.url() === endpoint) posts += 1; });
        await form.locator('button[type=submit]').click();
        verify(posts === 0, 'Untouched form does not submit or start a worker');
        const expired = await page.request.post(endpoint, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            form: { action: 'configure', __csrf_magic: 'sid:forged,1', form_complete: '1' },
        });
        verify(expired.status() === 403 && (await expired.json()).error === 'csrf_expired', 'Expired CSRF is rejected before dispatch');
        verify((await status()).state === 'not_started', 'CSRF rejection leaves installation untouched');
        await page.goto(endpoint);
        await form.locator('[name="options[Profile]"]').selectOption('1');
        await form.locator('[name="options[Eula]"]').check();
        await form.locator('summary').click();
        await form.locator('[name="options[Paths][path_php_binary]"]').fill(process.env.INSTALLER_TEST_PHP_BINARY || '/usr/local/bin/php');
        const beforeConfigure = posts;
        await form.locator('[name=__csrf_magic]').evaluate(input => { input.value = 'sid:forged,1'; });
        await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);
        verify(posts === beforeConfigure + 2, 'Shipped script refreshes rejected CSRF once before configuring');
        verify(await page.locator('#review').count() === 1, 'Saved configuration reaches executable review');
        const review = page.locator('form').filter({ has: page.locator('input[name=revision]') });
        const revision = await review.locator('[name=revision]').inputValue();
        verify(/^[a-f0-9]{64}$/.test(revision), 'Review includes authenticated fingerprint');
        const denied = await page.request.post(endpoint, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            form: { action: 'start', revision, __csrf_magic: await review.locator('[name=__csrf_magic]').inputValue() },
        });
        verify(denied.status() === 422, 'Missing execution consent is rejected');
        verify((await status()).state === 'not_started', 'Rejected start does not reserve a worker');
        await review.locator('[name=confirmed]').check();
        await Promise.all([page.waitForNavigation(), review.locator('button[type=submit]').click()]);
        const deadline = Date.now() + 180000;
        let state;
        do {
            state = await status();
            if (!['waiting', 'running'].includes(state.state)) break;
            await new Promise(resolve => setTimeout(resolve, 2000));
        } while (Date.now() < deadline);
        verify(state.state === 'succeeded' && state.progress === 100 && state.pending_collectors === 0, 'Native browser worker completes the installed fixture');
        await page.goto(endpoint + '?language=fr-FR');
        verify(await page.locator('h1').innerText() === 'Installation et mise à niveau', 'Native locale selection reaches Symfony French catalog');
        verify((await page.locator('main').innerText()).includes('Exécution terminée'), 'Completion phase is translated');
        const refresh = await page.request.post(endpoint, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            form: { __csrf_magic: 'sid:forged,1' },
        });
        const freshToken = (await refresh.json()).token;
        const invalid = await page.request.post(endpoint, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            form: { action: 'configure', form_complete: '1', 'options[Profile]': '1',
                __csrf_magic: freshToken },
        });
        verify(invalid.status() === 422 && (await invalid.json()).error === 'Acceptez la licence avant d’enregistrer le plan d’installation.', 'Native locale selection translates validation errors');
        await page.context().clearCookies();
        const expiredSession = await page.request.get(endpoint + '?view=status');
        const expiredBody = await expiredSession.json();
        verify(expiredSession.headers()['content-type'].includes('application/json')
            && expiredBody.status === '500' && typeof expiredBody.responseText === 'string'
            && !Object.hasOwn(expiredBody, 'schema'), 'Expired sessions receive the native JSON denial without protected installation data');
        verify(errors.length === 0, 'Shipped installer scripts execute without page errors under CSP');
        console.log(`Installer native browser checks passed: ${checks}`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });

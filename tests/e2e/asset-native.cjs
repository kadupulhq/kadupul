/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
'use strict';

const { chromium } = require('playwright');
const assert = require('node:assert/strict');

// The Docker runner prepares a fresh register and the ordinary fixture account.
(async () => {
    const base = new URL(process.env.ASSET_TEST_URL);
    assert(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Disposable localhost fixture required');
    assert(process.env.ASSET_TEST_PASSWORD, 'Fixture password required');
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROME_PATH,
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    let checks = 0;
    const verify = (condition, message) => { assert(condition, message); checks += 1; };
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        const endpoint = new URL('app.php/assets', base).href;
        const login = async (target, username) => {
            await target.goto(new URL('index.php', base).href);
            await target.locator('#login_username').fill(username);
            await target.locator('#login_password').fill(process.env.ASSET_TEST_PASSWORD);
            await Promise.all([target.waitForNavigation(), target.locator('button[type=submit],input[type=submit]').first().click()]);
        };
        verify((await page.goto(endpoint)).status() === 401, 'Enabled register refuses anonymous access');
        const restrictedContext = await browser.newContext();
        const restricted = await restrictedContext.newPage();
        await login(restricted, 'assetviewer');
        verify(await restricted.locator('#login_username').count() === 0, 'Console-only fixture successfully signs in');
        verify((await restricted.goto(new URL('app.php/console', base).href)).status() === 200
            && await restricted.locator('#cactiPageHead').count() === 1, 'Console-only fixture is admitted to its authorized console');
        verify((await restricted.goto(endpoint)).status() === 403, 'Authenticated console user without asset permission cannot read register');
        const permissionRefusal = await restricted.request.post(endpoint, { form: { action: 'configure' } });
        verify(permissionRefusal.status() === 403 && (await permissionRefusal.text()).includes('installation administrator access'),
            'Asset permission is checked before CSRF or command validation');
        await restrictedContext.close();
        await login(page, 'admin');
        const response = await page.goto(endpoint);
        verify(response.status() === 200, 'Ordinary administrator login reaches register');
        verify(await page.locator('h1').innerText() === 'Asset register', 'Actual asset page renders');
        const csp = response.headers()['content-security-policy'] || '';
        verify(csp === "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'", 'Actual response enforces the shipped CSP');
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const form = action => page.locator('form').filter({ has: page.locator(`input[name=action][value="${action}"]`) });
        const reveal = async action => {
            const target = form(action);
            const details = target.locator('xpath=ancestor::details[1]');
            if (await details.count() && !(await details.evaluate(element => element.open))) {
                await details.locator('summary').first().click();
            }
            return target;
        };
        const revision = () => form('configure').locator('[name=revision]').inputValue();
        const submit = async (action, expected = 200) => {
            const target = await reveal(action);
            const [result] = await Promise.all([page.waitForNavigation(), target.locator('button').click()]);
            verify(result.status() === expected, `${action} returns ${expected}`);
            if (expected === 200) verify(await page.getByRole('status').filter({ hasText: 'Change recorded.' }).count() === 1, `${action} reports committed outcome once`);
        };
        verify(await revision() === '0', 'Fresh fixture required; never mutate an existing register');
        let posts = 0;
        page.on('request', request => { if (request.method() === 'POST' && request.url().startsWith(endpoint)) posts += 1; });
        await form('configure').locator('button').click();
        verify(posts === 0, 'Untouched policy form blocks native submission');
        const rejected = await page.request.post(endpoint, { form: { action: 'configure' } });
        verify(rejected.status() === 403, 'Missing CSRF is refused');
        await page.reload();
        verify(await revision() === '0', 'Rejected requests do not start a committed operation');
        await form('configure').getByLabel('Population and scope').fill('Browser rehearsal equipment');
        await form('configure').getByLabel('Accountable steward').fill('Fixture operations');
        await submit('configure');
        const create = await reveal('create');
        for (const [key, value] of Object.entries({ name: 'Browser switch', owner: 'Network team', custodian: 'Fixture operator', service: 'Test service', purchase_cost_minor: '0', currency: 'USD' })) {
            await create.locator(`[name="fields[${key}]"]`).fill(value);
        }
        await submit('create');
        const assetId = new URL(page.url()).searchParams.get('asset');
        verify(/^[a-f0-9]{32}$/.test(assetId), 'Create selects the persisted stable asset ID');
        verify(await form('edit').locator('[name="fields[purchase_cost_minor]"]').inputValue() === '0', 'Zero purchase cost survives browser handoff');
        await form('edit').locator('[name="fields[contract]"]').fill('Fixture purchase agreement');
        await form('edit').getByLabel('Evidence or approval reference').fill('Fixture contract review');
        await submit('edit');
        verify(await form('edit').locator('[name="fields[contract]"]').inputValue() === 'Fixture purchase agreement', 'Edit form persists the changed contract');
        verify(await form('edit').locator('[name="fields[owner]"]').inputValue() === 'Network team', 'Edit preserves the other accepted fields');
        for (const state of ['received', 'active']) {
            await form('transition').getByLabel('New lifecycle state').selectOption(state);
            await form('transition').getByLabel('Evidence or approval reference').fill('Fixture handover ' + state);
            await submit('transition');
        }
        verify((await page.locator('table tbody').innerText()).includes('Active'), 'Commissioned record is active');
        const importForm = await reveal('import');
        await importForm.getByLabel('Source and account scope').fill('browser/fixture');
        await importForm.getByLabel('Observations', { exact: true }).fill(JSON.stringify([{
            key: 'switch-1', observed_at: new Date(Date.now() - 1000).toISOString().replace(/\.\d{3}Z$/, 'Z'),
            fields: { name: 'Observed switch', location: 'Fixture rack' },
        }]));
        await submit('import');
        const link = await reveal('link');
        await link.getByLabel('Asset ID (blank to unlink)').fill(assetId);
        await link.getByLabel('Identity evidence and reason').fill('Physical fixture comparison');
        await submit('link');
        const accept = await reveal('accept');
        await accept.locator('[name="fields[]"][value=location]').check();
        await accept.getByLabel('Evidence or approval reference').fill('Approved rack observation');
        await submit('accept');
        await page.goto(endpoint + '?asset=' + assetId);
        verify(await form('edit').locator('[name="fields[name]"]').inputValue() === 'Browser switch', 'Unselected observation never replaces accepted name');
        verify(await form('edit').locator('[name="fields[location]"]').inputValue() === 'Fixture rack', 'Selected observation reaches accepted record');
        verify(await form('edit').locator('[name="fields[owner]"]').inputValue() === 'Network team', 'Observation approval preserves ownership');
        await (await reveal('link')).getByLabel('Asset ID (blank to unlink)').fill('');
        await form('link').getByLabel('Identity evidence and reason').fill('Review mapping independently');
        await submit('link');
        verify(await form('accept').count() === 0, 'Unlinked observation cannot be accepted into an asset');
        await (await reveal('link')).getByLabel('Asset ID (blank to unlink)').fill(assetId);
        await form('link').getByLabel('Identity evidence and reason').fill('Mapping independently confirmed');
        await submit('link');
        const baseline = await reveal('baseline');
        await baseline.getByLabel('Independently verified asset IDs').fill(assetId);
        await baseline.getByLabel('Baseline evidence').fill('Independent fixture physical audit');
        await submit('baseline');
        const review = await reveal('review');
        await review.getByLabel('Review kind').selectOption('identity_sample');
        await review.getByLabel('Review evidence and decisions').fill('Fixture identity sample');
        await review.locator('[name=sample_size]').fill('1');
        await submit('review');
        const failure = await reveal('source_failure');
        await failure.getByLabel('Source and account scope').fill('browser/fixture');
        await submit('source_failure');
        const metrics = page.locator('section[aria-labelledby=asset-quality]');
        verify(await metrics.locator('dt').filter({ hasText: 'Active assets with fresh observations' }).locator('xpath=following-sibling::dd[1]').innerText() === '0 / 1', 'Failed collection removes freshness without retiring the asset');
        const empty = await reveal('import');
        await empty.getByLabel('Source and account scope').fill('browser/fixture');
        await empty.getByLabel('Observations', { exact: true }).fill('[]');
        await submit('import');
        verify((await page.locator('table tbody').innerText()).includes('Active'), 'Empty collection preserves active equipment');
        verify(await form('link').count() === 1, 'Empty collection retains identity and mapping');
        const stale = await context.newPage();
        await stale.goto(endpoint);
        const before = await revision();
        await (await reveal('review')).getByLabel('Review evidence and decisions').fill('New review after stale page opened');
        await submit('review');
        const staleForm = stale.locator('form').filter({ has: stale.locator('[name=action][value=configure]') });
        await staleForm.locator('xpath=ancestor::details[1]/summary').click();
        const [conflict] = await Promise.all([stale.waitForNavigation(), staleForm.locator('button').click()]);
        verify(conflict.status() === 409 && (await stale.getByRole('alert').innerText()).includes('register changed'), 'Stale browser form is refused with actionable feedback');
        await stale.close();
        await page.reload();
        verify(Number(await revision()) === Number(before) + 1, 'Stale submission adds no journal entry');

        await page.goto(endpoint);
        await (await reveal('create')).locator('[name="fields[name]"]').fill('Dependent equipment');
        await submit('create');
        const dependantId = new URL(page.url()).searchParams.get('asset');
        await form('dependency').getByLabel('Depends on asset ID').fill(assetId);
        await form('dependency').getByLabel('Evidence or approval reference').fill('Fixture service dependency');
        await submit('dependency');
        const beforeDependencyRefusals = await revision();
        await page.goto(endpoint + '?asset=' + assetId);
        await form('dependency').getByLabel('Depends on asset ID').fill(dependantId);
        await form('dependency').getByLabel('Evidence or approval reference').fill('Fixture cycle review');
        await submit('dependency', 422);
        verify((await page.getByRole('alert').innerText()).includes('cycle'), 'Cycle refusal is visible through the native form');
        await form('transition').getByLabel('New lifecycle state').selectOption('retired');
        await form('transition').getByLabel('Evidence or approval reference').fill('Fixture retirement review');
        await submit('transition', 422);
        verify((await page.getByRole('alert').innerText()).includes('still depends'), 'Retirement identifies the surviving dependency');
        verify(await revision() === beforeDependencyRefusals, 'Rejected dependency and retirement operations preserve the revision');
        await page.goto(endpoint + '?asset=' + dependantId);
        await form('dependency').getByLabel('Depends on asset ID').fill(assetId);
        await form('dependency').locator('select[name=remove]').selectOption('1');
        await form('dependency').getByLabel('Evidence or approval reference').fill('Dependent service moved');
        await submit('dependency');
        verify(await page.locator('h3').filter({ hasText: 'Service dependencies' }).locator('xpath=following-sibling::ul[1]/li').count() === 0, 'Dependency removal is reachable and persisted');

        const monitoring = await reveal('monitoring');
        await monitoring.getByLabel('Device IDs, separated by spaces').fill('1');
        await submit('monitoring');
        verify(await form('link').count() === 2, 'Monitoring import adds a separate scoped identity');
        const monitoringIdentity = page.locator('section[aria-labelledby=identity-heading] > details').filter({ hasText: 'kadupul-monitoring / 1' });
        await monitoringIdentity.locator('summary').click();
        const monitoringUrl = new URL(await monitoringIdentity.getByRole('link', { name: 'Monitoring device', exact: true }).getAttribute('href'), page.url());
        verify(monitoringUrl.pathname.startsWith(base.pathname), 'Monitoring link retains the installation subdirectory');
        verify((await page.request.get(monitoringUrl.href)).status() === 200, 'Monitoring link reaches the actual installed device');
        await page.goto(endpoint + '?asset=' + assetId);
        for (const state of ['retired', 'disposed']) {
            await form('transition').getByLabel('New lifecycle state').selectOption(state);
            await form('transition').getByLabel('Evidence or approval reference').fill('Fixture approval ' + state);
            await submit('transition');
        }
        verify((await page.locator('table tbody').innerText()).includes('Disposed'), 'Disposal remains visible in accepted records');
        verify(await page.locator('section[aria-labelledby=history-heading] > details').count() === Number(await revision()), 'Every successful browser mutation has a visible journal receipt');
        verify(errors.length === 0, 'Browser reports no JavaScript errors');
        console.log(JSON.stringify({ checks, revision: Number(await revision()), browser: browser.version(), basePath: base.pathname }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

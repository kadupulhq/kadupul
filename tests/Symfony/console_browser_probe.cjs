// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../e2e/node_modules/playwright');
const v8ToIstanbul = require('../e2e/node_modules/v8-to-istanbul');
const { createCoverageMap } = require('../e2e/node_modules/istanbul-lib-coverage');
const { browserSourceSnapshot, publishConsoleCoverage } = require('../e2e/browser-coverage');

async function main(input) {
    const origin = new URL(input.base);
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname));
    assert.ok(['default', 'nonce'].includes(input.mode));
    const snapshot = process.env.KADUPUL_BROWSER_COVERAGE ? browserSourceSnapshot() : null;
    const sourceFile = path.resolve(__dirname, '../../include/js/console-home.js');
    const source = fs.readFileSync(sourceFile, 'utf8');
    const browser = await chromium.launch({ headless: true });
    const deadline = setTimeout(() => browser.close().catch(() => {}), 60000);
    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await context.addCookies(input.cookies);
        const page = await context.newPage();
        await page.coverage.startJSCoverage({ resetOnNavigation: false });
        // Enter through home after the fixture invalidates the native policy
        // cache, just as an admitted viewer does before opening another page.
        assert.equal((await page.goto(`${input.base}/index.php`)).status(), 200);
        const legacyResponse = await page.goto(`${input.base}/graphs.php`);
        assert.equal(legacyResponse.status(), 200);
        const legacy = await page.evaluate(() => ({
            path: window.location.pathname,
            navigation: typeof window.loadPage,
            jquery: typeof window.jQuery,
            scripts: [...document.scripts].filter(script => script.src).map(script => new URL(script.src).pathname),
        }));
        assert.equal(legacy.navigation, 'function', `Native navigation unavailable: ${JSON.stringify(legacy)}`);
        // Use the shipped legacy navigation function. It must load the console
        // document so that its module runs after leaving a legacy page.
        const [response] = await Promise.all([
            page.waitForNavigation(),
            page.evaluate(url => loadPage(url, true), `${input.base}/index.php`),
        ]);
        assert.equal(response.status(), 200);
        const policy = response.headers()['content-security-policy'];
        assert.ok(policy);
        if (input.mode === 'nonce') assert.match(policy, /script-src[^;]*'nonce-/);
        assert.equal(await page.locator('#main').count(), 1);
        assert.equal(await page.locator('#console-fixture-before').count(), 1);
        assert.equal(await page.locator('#console-fixture-bottom').count(), 1);
        assert.equal(await page.locator('#console-fixture-form input[name="__csrf_magic"]').count(), 1);
        assert.equal(await page.locator('#console-fixture-foreign input[name="__csrf_magic"]').count(), 0);
        const frame = page.locator('#main iframe.content').first();
        await frame.waitFor();
        await page.waitForFunction(() => document.querySelector('#main iframe.content').style.width !== '');
        const width = await frame.evaluate(element => element.style.width);
        await page.setViewportSize({ width: 960, height: 900 });
        await page.waitForFunction(previous => document.querySelector('#main iframe.content').style.width !== previous, width);
        await page.waitForFunction(() => {
            const main = document.getElementById('main');
            return parseFloat(main.querySelector('iframe.content').style.width) === Math.round(main.getBoundingClientRect().width);
        });
        const help = await page.getByRole('link', { name: 'Help', exact: true }).getAttribute('href');
        assert.equal(new URL(help, input.base).origin, origin.origin);
        // Exercise the real resize guard after AJAX has removed the console.
        await page.locator('#main').evaluate(element => element.remove());
        await page.evaluate(() => window.dispatchEvent(new Event('resize')));
        const entries = await page.coverage.stopJSCoverage();
        const measured = entries.filter(entry => /\/console-home(?:-[a-zA-Z0-9]+)?\.js(?:\?|$)/.test(entry.url));
        assert.ok(measured.length > 0, 'The shipped console module must execute.');
        const map = createCoverageMap({});
        for (const entry of measured) {
            assert.equal(entry.source, source);
            const converter = v8ToIstanbul(sourceFile, 0, { source });
            await converter.load();
            converter.applyCoverage(entry.functions);
            map.merge(converter.toIstanbul());
        }
        if (snapshot) publishConsoleCoverage(map, snapshot, `${process.env.KADUPUL_BROWSER_COVERAGE_SESSION}:${input.mode}`);
        const helpResponse = await page.goto(new URL(help, input.base).href);
        assert.equal(helpResponse.status(), 200);
        assert.equal(await page.locator('main h1').innerText(), 'Help');
        console.log(JSON.stringify({ console_script_executed: true, iframe_resize_executed: true, plugin_forms_protected: true, help_navigation: true }));
    } finally {
        clearTimeout(deadline);
        await browser.close();
    }
}

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => main(JSON.parse(input)).catch(error => {
    console.error(error.message);
    process.exitCode = 1;
}));

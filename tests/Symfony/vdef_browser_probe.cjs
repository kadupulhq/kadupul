// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { publishVdefCoverage, browserSourceSnapshot } = require('../e2e/browser-coverage');
const { chromium } = require('../e2e/node_modules/playwright');
const v8ToIstanbul = require('../e2e/node_modules/v8-to-istanbul');
const { createCoverageMap } = require('../e2e/node_modules/istanbul-lib-coverage');

async function main(input) {
    const snapshot = browserSourceSnapshot();
    const origin = new URL(input.base);
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname));
    assert.match(String(input.vdefId), /^[1-9][0-9]{0,7}$/);
    assert.ok(['/app.php', '/public/index.php', '/cacti/app.php', '/cacti/public/index.php'].includes(input.front));
    const browser = await chromium.launch({ headless: true });
    const browserTimeout = setTimeout(() => browser.close().catch(() => {}), 80000);
    try {
        const context = await browser.newContext();
        await context.addCookies(input.cookies);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const sourceFile = path.resolve(__dirname, '../../public/js/vdef-item.js');
        const source = fs.readFileSync(sourceFile, 'utf8');
        const navigationLine = source.split('\n').findIndex(line => line.includes('window.location.assign(target)'));
        assert.ok(navigationLine >= 0);
        const cdp = await context.newCDPSession(page);
        const scriptSources = new Map();
        cdp.on('Debugger.scriptParsed', script => {
            if (/^https?:\/\//.test(script.url) && new URL(script.url).pathname.endsWith('/public/js/vdef-item.js')) {
                scriptSources.set(script.scriptId, cdp.send('Debugger.getScriptSource', { scriptId: script.scriptId }));
            }
        });
        await cdp.send('Debugger.enable');
        await cdp.send('Profiler.enable');
        await cdp.send('Profiler.startPreciseCoverage', { callCount: true, detailed: true });
        const scripts = [];
        async function captureHandlerCoverage() {
            const snapshot = await cdp.send('Profiler.takePreciseCoverage');
            for (const entry of snapshot.result) {
                if (!scriptSources.has(entry.scriptId)) continue;
                const captured = await scriptSources.get(entry.scriptId);
                assert.equal(captured.scriptSource, source);
                scripts.push({ ...entry, source: captured.scriptSource });
            }
        }
        const response = await page.goto(`${input.base}${input.front}/graph-definitions/vdefs/${input.vdefId}/items/0`);
        assert.equal(response.status(), 200);
        assert.ok(response.headers()['content-security-policy'].includes("default-src 'self'"));
        // Record the real callback before it destroys its document, then resume
        // the unchanged callback and verify the resulting navigation and save.
        const breakpoint = await cdp.send('Debugger.setBreakpointByUrl', {
            lineNumber: navigationLine, urlRegex: '/public/js/vdef-item\\.js$',
        });
        let callbackPaused;
        const navigationRequested = new Promise(resolve => {
            callbackPaused = resolve;
        });
        cdp.once('Debugger.paused', callbackPaused);
        const typeChanged = page.selectOption('#vdef_item_type', '6');
        typeChanged.catch(() => {});
        let navigationTimeout;
        let pendingNavigation;
        try {
            pendingNavigation = await Promise.race([
                navigationRequested,
                new Promise((_, reject) => {
                    navigationTimeout = setTimeout(() => reject(new Error('The real VDEF type callback was not reached.')), 15000);
                }),
            ]);
        } finally {
            clearTimeout(navigationTimeout);
        }
        assert.ok(pendingNavigation.hitBreakpoints.includes(breakpoint.breakpointId));
        assert.equal(pendingNavigation.callFrames[0].location.lineNumber, navigationLine);
        assert.ok(scriptSources.has(pendingNavigation.callFrames[0].location.scriptId));
        await captureHandlerCoverage();
        await cdp.send('Debugger.removeBreakpoint', { breakpointId: breakpoint.breakpointId });
        await cdp.send('Debugger.resume');
        await typeChanged;
        await page.waitForURL(url => url.searchParams.get('type') === '6');
        assert.equal(await page.locator('#vdef_item_value').evaluate(element => element.tagName), 'INPUT');
        await page.fill('#vdef_item_value', 'browser snowman ☃ 😁');
        const asset = await page.locator('script[src$="/public/js/vdef-item.js"]').getAttribute('src');
        assert.equal(asset, `${input.front.startsWith('/cacti/') ? '/cacti' : ''}/public/js/vdef-item.js`);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(url => url.pathname.endsWith(`/vdefs/${input.vdefId}/edit`));
        assert.ok((await page.locator('main').innerText()).includes('browser snowman ☃ 😁'));
        await page.goto(`${input.base}${input.front}/graph-definitions/vdefs`);
        assert.equal(await page.locator('#vdef_item_type').count(), 0);
        await page.addScriptTag({ url: new URL(asset, input.base).href });
        assert.deepEqual(errors, []);
        await captureHandlerCoverage();
        const handlerScripts = scripts.filter(entry => /^https?:\/\//.test(entry.url) && new URL(entry.url).pathname.endsWith('/public/js/vdef-item.js'));
        assert.ok(handlerScripts.length > 0, 'Browser did not measure the external VDEF type handler.');
        const map = createCoverageMap({});
        for (const script of handlerScripts) {
            assert.equal(script.source, fs.readFileSync(sourceFile, 'utf8'));
            assert.ok(script.functions.some(fn => fn.ranges.some(range => range.count > 0)));
            const converter = v8ToIstanbul(sourceFile, 0, { source: script.source });
            await converter.load();
            converter.applyCoverage(script.functions);
            map.merge(converter.toIstanbul());
        }
        assert.ok(Object.values(map.fileCoverageFor(sourceFile).getLineCoverage()).some(count => count > 0));
        assert.ok(map.getCoverageSummary().lines.pct >= 80,
            `The actual VDEF browser handler coverage is incomplete: ${JSON.stringify(map.getCoverageSummary().lines)}; lines=${JSON.stringify(map.fileCoverageFor(sourceFile).getLineCoverage())}`);
        if (process.env.KADUPUL_BROWSER_COVERAGE) {
            publishVdefCoverage(map, snapshot, `${process.env.KADUPUL_BROWSER_COVERAGE_SESSION}:${input.front}`);
        }
        console.log(JSON.stringify({ csp_handler_executed: true, custom_item_saved: true, script_measured: true, no_control_branch_measured: true }));
    } finally {
        clearTimeout(browserTimeout);
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

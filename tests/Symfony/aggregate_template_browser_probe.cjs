// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require('../e2e/node_modules/playwright');
const v8ToIstanbul = require('../e2e/node_modules/v8-to-istanbul');
const { createCoverageMap } = require('../e2e/node_modules/istanbul-lib-coverage');

async function main(input) {
    const origin = new URL(input.base);
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname));
    assert.match(String(input.sourceId), /^[1-9][0-9]{0,7}$/);
    assert.ok(['/app.php', '/public/index.php', '/cacti/app.php', '/cacti/public/index.php'].includes(input.front));
    const browser = await chromium.launch({ headless: true });
    const browserTimeout = setTimeout(() => browser.close().catch(() => {}), 80000);
    try {
        const context = await browser.newContext();
        await context.addCookies(input.cookies);
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Debugger.enable');
        await cdp.send('Profiler.enable');
        await cdp.send('Profiler.startPreciseCoverage', { callCount: true, detailed: true });
        const sourceFile = path.resolve(__dirname, '../../public/js/aggregate-template-source.js');
        const expectedSource = fs.readFileSync(sourceFile, 'utf8');
        const sources = new Map();
        let currentScriptId;
        cdp.on('Debugger.scriptParsed', script => {
            if (/^https?:\/\//.test(script.url) && new URL(script.url).pathname.endsWith('/public/js/aggregate-template-source.js')) {
                currentScriptId = script.scriptId;
                sources.set(script.scriptId, cdp.send('Debugger.getScriptSource', { scriptId: script.scriptId })
                    .then(result => result.scriptSource));
            }
        });
        const scripts = [];
        async function snapshot() {
            const observed = await cdp.send('Profiler.takePreciseCoverage');
            for (const script of observed.result.filter(entry => sources.has(entry.scriptId))) {
                const source = await sources.get(script.scriptId);
                assert.equal(source, expectedSource);
                scripts.push({ ...script, source });
            }
        }
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const response = await page.goto(`${input.base}${input.front}/aggregate-templates/0/edit`);
        assert.equal(response.status(), 200);
        assert.ok(response.headers()['content-security-policy'].includes("default-src 'self'"));
        const asset = await page.locator('script[src$="/public/js/aggregate-template-source.js"]').getAttribute('src');
        assert.equal(asset, `${input.front.startsWith('/cacti/') ? '/cacti' : ''}/public/js/aggregate-template-source.js`);
        // Snapshot the actual callback while its live document is paused just
        // before navigation, then resume that same unchanged production code.
        assert.ok(currentScriptId);
        assert.equal(await sources.get(currentScriptId), expectedSource);
        const lineNumber = expectedSource.split('\n').findIndex(line => line.includes('window.location.assign'));
        assert.ok(lineNumber >= 0);
        const breakpoint = await cdp.send('Debugger.setBreakpointByUrl', {
            urlRegex: '/public/js/aggregate-template-source\\.js$', lineNumber,
        });
        let pauseTimeout;
        const paused = new Promise((resolve, reject) => {
            pauseTimeout = setTimeout(() => reject(new Error('Source selection did not reach its navigation breakpoint.')), 15000);
            cdp.once('Debugger.paused', event => { clearTimeout(pauseTimeout); resolve(event); });
        });
        const sourceChanged = page.selectOption('#aggregate_template_graph_template_id', String(input.sourceId));
        sourceChanged.catch(() => {});
        let pause;
        try {
            pause = await paused;
        } finally {
            clearTimeout(pauseTimeout);
        }
        assert.ok(pause.hitBreakpoints.includes(breakpoint.breakpointId));
        assert.equal(pause.callFrames[0].location.lineNumber, lineNumber);
        assert.equal(pause.callFrames[0].location.scriptId, currentScriptId);
        assert.ok(sources.has(pause.callFrames[0].location.scriptId));
        await snapshot();
        await cdp.send('Debugger.removeBreakpoint', { breakpointId: breakpoint.breakpointId });
        await cdp.send('Debugger.resume');
        await sourceChanged;
        await page.waitForURL(url => url.searchParams.get('source') === String(input.sourceId));
        assert.equal(await page.locator('#aggregate_template_graph_template_id').inputValue(), String(input.sourceId));
        assert.equal(await page.locator('#aggregate_template_graph_type').inputValue(), '8');
        assert.ok(await page.locator('input[name="aggregate_template[items][0][id]"]').count() > 0);
        await page.goto(`${input.base}${input.front}/aggregate-templates`);
        assert.equal(await page.locator('#aggregate_template_graph_template_id').count(), 0);
        await page.addScriptTag({ url: new URL(asset, input.base).href });
        assert.deepEqual(errors, []);
        await snapshot();
        await cdp.send('Profiler.stopPreciseCoverage');
        await cdp.send('Profiler.disable');
        await cdp.send('Debugger.disable');
        const handlerScripts = scripts.filter(entry => /^https?:\/\//.test(entry.url)
            && new URL(entry.url).pathname.endsWith('/public/js/aggregate-template-source.js'));
        assert.ok(handlerScripts.length > 0, 'Browser did not measure the source selector.');
        const map = createCoverageMap({});
        for (const script of handlerScripts) {
            assert.equal(script.source, expectedSource);
            assert.ok(script.functions.some(fn => fn.ranges.some(range => range.count > 0)));
            const converter = v8ToIstanbul(sourceFile, 0, { source: script.source });
            await converter.load();
            converter.applyCoverage(script.functions);
            map.merge(converter.toIstanbul());
        }
        assert.ok(map.getCoverageSummary().lines.pct >= 80);
        if (process.env.KADUPUL_BROWSER_COVERAGE) {
            fs.mkdirSync(process.env.KADUPUL_BROWSER_COVERAGE, { recursive: true });
            fs.writeFileSync(path.join(process.env.KADUPUL_BROWSER_COVERAGE, `${crypto.randomUUID()}.json`), JSON.stringify(map.toJSON()));
        }
        console.log(JSON.stringify({ csp_selector_executed: true, source_items_loaded: true, stack_default_selected: true, script_measured: true, no_control_branch_measured: true }));
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

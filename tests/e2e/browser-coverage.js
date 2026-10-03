// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const v8ToIstanbul = require('v8-to-istanbul');
const { createCoverageMap } = require('istanbul-lib-coverage');

const root = path.resolve(__dirname, '../..');

const measuredSources = ['include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sourceHashes = sources => Object.fromEntries(sources.map(source => [source, hash(fs.readFileSync(path.join(root, source)))]));

// Producer inventory is captured before navigation. The merger below keeps its
// own required inventory rather than trusting the report's claimed sources.
function producerSources() {
  return ['package-lock.json', 'tests/e2e/package-lock.json', 'tests/e2e/browser-coverage.js',
    'tests/e2e/midwinter-listeners.spec.js', 'tests/e2e/selectmenu-scroll.spec.js', 'tests/e2e/theme-smoke.html', 'tests/e2e/playwright.config.js',
    'lib/html.php', 'include/js/jquery.js', 'include/js/jquery-ui.js', 'include/js/js.storage.js',
    'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js',
    'include/themes/classic/jquery-ui.css', 'include/fa/css/all.css', 'include/fa/webfonts/fa-solid-900.woff2',
    'include/themes/midwinter/vendor/mark/jquery.mark.js', 'include/themes/midwinter/vendor/hotkeys/hotkeys.js',
    'include/themes/midwinter/vendor/ua-parser/ua-parser.js', ...measuredSources];
}

const mergerSources = ['package-lock.json', 'tests/e2e/package-lock.json', 'tests/e2e/browser-coverage.js',
  'tests/e2e/midwinter-listeners.spec.js', 'tests/e2e/selectmenu-scroll.spec.js', 'tests/e2e/theme-smoke.html', 'tests/e2e/playwright.config.js',
  'lib/html.php', 'include/js/jquery.js', 'include/js/jquery-ui.js', 'include/js/js.storage.js',
  'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js',
  'include/themes/classic/jquery-ui.css', 'include/fa/css/all.css', 'include/fa/webfonts/fa-solid-900.woff2',
  'include/themes/midwinter/vendor/mark/jquery.mark.js', 'include/themes/midwinter/vendor/hotkeys/hotkeys.js',
  'include/themes/midwinter/vendor/ua-parser/ua-parser.js',
  'include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const mergerMeasuredSources = ['include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const scenarios = {
  'midwinter-listeners.spec.js': [
    'three page loads leave one handler per shortcut, menu link and keyword box',
    'a double-click on the page does not enter fullscreen',
    'a failed hotkeys load is retried on the next page load',
    'the user menu and content area keep one handler each across page loads',
    'the relocated filter keeps its production sliders glyph and controls',
    'auto colour mode follows the system scheme through one listener',
    'a system scheme change leaves a manual colour mode alone',
    'ESC outside fullscreen and the retired c+F1 shortcut raise no error or alert',
    'SHIFT+k enters fullscreen on the content area and leaves it again',
  ],
  'selectmenu-scroll.spec.js': [
    'select menu remains usable after the browser scrolls its button into view',
    'scrolling a panel after opening a select menu closes it',
    'a scroll queued before the menu opens does not immediately close it',
    'shared theme controls preserve filter icons, select widget sizing and both logos',
    'shared form controls retain import labels and theme widths',
  ],
};

function loadEvidence(file, expectedProducer, expectedScenario) {
  const receipt = JSON.parse(fs.readFileSync(`${file}.receipt`, 'utf8'));
  const bytes = fs.readFileSync(file);
  const requiredHits = expectedProducer.endsWith('midwinter-listeners.spec.js')
    ? ['include/themes/midwinter/main.js'] : ['include/layout.js'];
  if (receipt.version !== 1 || receipt.producer !== expectedProducer || receipt.scenario !== expectedScenario
      || receipt.root !== fs.realpathSync(root) || receipt.digest !== hash(bytes)
      || receipt.completed !== 'browser-test-passed-and-production-measured') throw new Error('Browser report identity or completion mismatch');
  const current = sourceHashes(mergerSources);
  if (Object.keys(receipt.sources || {}).length !== mergerSources.length) throw new Error('Browser source inventory mismatch');
  for (const source of mergerSources) {
    if (receipt.sources[source] !== current[source]) throw new Error(`Missing or stale browser source: ${source}`);
  }
  const data = JSON.parse(bytes);
  const map = createCoverageMap(data);
  if (map.files().length === 0) throw new Error('Empty browser coverage');
  for (const file of map.files()) {
    const canonical = fs.realpathSync(file);
    const relative = path.relative(fs.realpathSync(root), canonical).split(path.sep).join('/');
    if (file !== path.join(root, relative) || !mergerMeasuredSources.includes(relative)
        || !mergerSources.includes(relative) || data[file].path !== file) throw new Error('Unregistered browser measured source');
    for (const count of Object.values(map.fileCoverageFor(file).getLineCoverage())) {
      if (!Number.isSafeInteger(count) || count < 0) throw new Error('Invalid browser hit count');
    }
  }
  for (const source of requiredHits) {
    const file = path.join(root, source);
    if (!map.files().includes(file) || !Object.values(map.fileCoverageFor(file).getLineCoverage()).some(count => count > 0)) {
      throw new Error(`Missing positive browser hits: ${source}`);
    }
  }
  if (expectedScenario === 'the relocated filter keeps its production sliders glyph and controls'
      && !(map.fileCoverageFor(path.join(root, 'include/themes/midwinter/main.js')).getLineCoverage()[504] > 0)) {
    throw new Error('Missing actual filter glyph production line');
  }
  return map;
}

function verifyRejections(file, producer, scenario) {
  const originalReport = fs.readFileSync(file);
  const originalReceipt = fs.readFileSync(`${file}.receipt`);
  let count = 0;
  const refuse = edit => {
    fs.writeFileSync(file, originalReport);
    fs.writeFileSync(`${file}.receipt`, originalReceipt);
    edit();
    try {
      loadEvidence(file, producer, scenario);
    } catch {
      count++;
      return;
    }
    throw new Error('Browser negative evidence control was admitted');
  };
  const receiptEdit = edit => {
    const receipt = JSON.parse(originalReceipt);
    edit(receipt);
    fs.writeFileSync(`${file}.receipt`, JSON.stringify(receipt));
  };
  const reportEdit = edit => {
    const data = JSON.parse(originalReport);
    edit(data);
    const bytes = JSON.stringify(data);
    fs.writeFileSync(file, bytes);
    receiptEdit(receipt => { receipt.digest = hash(bytes); });
  };
  try {
    for (const field of ['version', 'producer', 'scenario', 'root', 'digest', 'completed']) {
      refuse(() => receiptEdit(receipt => { delete receipt[field]; }));
    }
    for (const source of mergerSources) {
      refuse(() => receiptEdit(receipt => { delete receipt.sources[source]; }));
      refuse(() => receiptEdit(receipt => { receipt.sources[source] = '0'.repeat(64); }));
    }
    refuse(() => fs.unlinkSync(file));
    refuse(() => fs.unlinkSync(`${file}.receipt`));
    refuse(() => reportEdit(data => { for (const key of Object.keys(data)) delete data[key]; }));
    refuse(() => reportEdit(data => {
      const entry = structuredClone(Object.values(data)[0]);
      const unregistered = path.join(root, 'include/js/jquery.js');
      entry.path = unregistered;
      data[unregistered] = entry;
    }));
    refuse(() => reportEdit(data => {
      for (const entry of Object.values(data)) {
        for (const key of Object.keys(entry.s)) entry.s[key] = 0;
        for (const key of Object.keys(entry.f)) entry.f[key] = 0;
        for (const key of Object.keys(entry.b)) entry.b[key] = entry.b[key].map(() => 0);
      }
    }));
  } finally {
    fs.writeFileSync(file, originalReport);
    fs.writeFileSync(`${file}.receipt`, originalReceipt);
  }
  return count;
}

function collectThemeCoverage(test) {
  const directory = process.env.KADUPUL_BROWSER_COVERAGE;
  if (!directory) return;
  test.beforeEach(async ({ page }, testInfo) => {
    testInfo.browserSourceSnapshot = sourceHashes(producerSources());
    await page.coverage.startJSCoverage();
  });
  test.afterEach(async ({ page }, testInfo) => {
    const map = createCoverageMap({});
    for (const entry of await page.coverage.stopJSCoverage()) {
      if (!/^https?:\/\//.test(entry.url)) continue;
      const pathname = new URL(entry.url).pathname;
      if (pathname !== '/include/layout.js' && !/^\/include\/themes\/[a-z0-9_-]+\/main\.js$/.test(pathname)) continue;
      const sourceFile = path.join(root, pathname);
      if (entry.source !== fs.readFileSync(sourceFile, 'utf8')) {
        throw new Error(`Browser coverage source differs from checkout: ${pathname}`);
      }
      const converter = v8ToIstanbul(sourceFile, 0, { source: entry.source });
      await converter.load();
      converter.applyCoverage(entry.functions);
      map.merge(converter.toIstanbul());
    }
    if (map.files().length === 0) throw new Error('No production layout or theme script was measured');
    // A failing case never receives a successful completion receipt.
    if (testInfo.status !== 'passed') return;
    const current = sourceHashes(producerSources());
    if (JSON.stringify(current) !== JSON.stringify(testInfo.browserSourceSnapshot)) throw new Error('Browser sources changed during execution');
    fs.mkdirSync(directory, { recursive: true });
    const file = path.join(directory, `${crypto.randomUUID()}.json`);
    const bytes = JSON.stringify(map.toJSON());
    fs.writeFileSync(file, bytes);
    const producer = path.relative(root, testInfo.file).split(path.sep).join('/');
    fs.writeFileSync(`${file}.receipt`, JSON.stringify({ version: 1, root: fs.realpathSync(root), producer,
      scenario: testInfo.title, sources: testInfo.browserSourceSnapshot, digest: hash(bytes),
      completed: 'browser-test-passed-and-production-measured' }));
  });
}

function verifyRegistryRejections(directory) {
  const owned = fs.mkdtempSync(path.join(directory, 'registry-probes-'));
  const reports = fs.readdirSync(directory).filter(file => file.endsWith('.json'));
  let count = 0;
  const restore = () => {
    for (const file of fs.readdirSync(owned)) fs.unlinkSync(path.join(owned, file));
    for (const file of reports) {
      fs.copyFileSync(path.join(directory, file), path.join(owned, file));
      fs.copyFileSync(path.join(directory, `${file}.receipt`), path.join(owned, `${file}.receipt`));
    }
  };
  const refuse = edit => {
    restore();
    edit();
    try {
      merge(owned, path.join(owned, 'unexpected.lcov'), false);
    } catch {
      count++;
      return;
    }
    throw new Error('Browser merger registry negative control was admitted');
  };
  try {
    const first = reports[0];
    refuse(() => {
      fs.unlinkSync(path.join(owned, first));
      fs.unlinkSync(path.join(owned, `${first}.receipt`));
    });
    refuse(() => {
      fs.copyFileSync(path.join(owned, first), path.join(owned, 'duplicate.json'));
      fs.copyFileSync(path.join(owned, `${first}.receipt`), path.join(owned, 'duplicate.json.receipt'));
    });
    for (const field of ['producer', 'scenario']) {
      refuse(() => {
        const file = path.join(owned, `${first}.receipt`);
        const receipt = JSON.parse(fs.readFileSync(file));
        receipt[field] = 'unregistered';
        fs.writeFileSync(file, JSON.stringify(receipt));
      });
    }
    refuse(() => {
      const glyphFile = reports.find(file => JSON.parse(fs.readFileSync(path.join(owned, `${file}.receipt`))).scenario
        === 'the relocated filter keeps its production sliders glyph and controls');
      const file = path.join(owned, glyphFile);
      const data = JSON.parse(fs.readFileSync(file));
      const production = data[path.join(root, 'include/themes/midwinter/main.js')];
      for (const [key, location] of Object.entries(production.statementMap)) {
        if (location.start.line <= 504 && location.end.line >= 504) production.s[key] = 0;
      }
      const bytes = JSON.stringify(data);
      fs.writeFileSync(file, bytes);
      const receipt = JSON.parse(fs.readFileSync(`${file}.receipt`));
      receipt.digest = hash(bytes);
      fs.writeFileSync(`${file}.receipt`, JSON.stringify(receipt));
    });
  } finally {
    for (const file of fs.readdirSync(owned)) fs.unlinkSync(path.join(owned, file));
    fs.rmdirSync(owned);
  }
  return count;
}

function merge(directory, output, runControls = true) {
  const map = createCoverageMap({});
  const observed = new Set();
  const verified = new Set();
  let rejectionControls = 0;
  for (const file of fs.readdirSync(directory).filter(file => file.endsWith('.json'))) {
    const report = path.join(directory, file);
    const receipt = JSON.parse(fs.readFileSync(`${report}.receipt`, 'utf8'));
    const driver = path.basename(receipt.producer || '');
    if (receipt.producer !== `tests/e2e/${driver}` || !scenarios[driver]?.includes(receipt.scenario)) throw new Error('Unregistered browser scenario');
    const identity = `${driver}:${receipt.scenario}`;
    if (observed.has(identity)) throw new Error('Duplicate browser scenario');
    observed.add(identity);
    map.merge(loadEvidence(report, receipt.producer, receipt.scenario));
    if (runControls && !verified.has(driver)) {
      rejectionControls += verifyRejections(report, receipt.producer, receipt.scenario);
      verified.add(driver);
    }
  }
  const expected = Object.entries(scenarios).flatMap(([driver, titles]) => titles.map(title => `${driver}:${title}`));
  if (observed.size !== expected.length || expected.some(identity => !observed.has(identity))) throw new Error('Missing completed browser scenarios');
  if (runControls) rejectionControls += verifyRegistryRejections(directory);
  if (map.files().length === 0) throw new Error('No browser coverage reports to merge');
  let covered = 0;
  const records = [];
  for (const file of map.files().sort()) {
    const lines = Object.entries(map.fileCoverageFor(file).getLineCoverage())
      .sort(([a], [b]) => Number(a) - Number(b));
    covered += lines.filter(([, count]) => count > 0).length;
    records.push(`SF:${file}\n${lines.map(([line, count]) => `DA:${line},${count}`).join('\n')}\nLF:${lines.length}\nLH:${lines.filter(([, count]) => count > 0).length}\nend_of_record`);
  }
  if (covered === 0) throw new Error('Browser coverage contains no covered source lines');
  fs.writeFileSync(output, `${records.join('\n')}\n`);
  console.log(`Browser coverage: ${covered} covered lines across ${map.files().length} theme scripts; ${observed.size} completed scenarios; ${rejectionControls} genuine rejection controls`);
}

module.exports = { collectThemeCoverage };
if (require.main === module) merge(process.argv[2], process.argv[3]);

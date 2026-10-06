// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const v8ToIstanbul = require('v8-to-istanbul');
const { createCoverageMap } = require('istanbul-lib-coverage');

const root = path.resolve(__dirname, '../..');

const measuredSources = ['public/js/vdef-item.js', 'include/realtime.js', 'include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sourceHashes = sources => Object.fromEntries(sources.map(source => [source, hash(fs.readFileSync(path.join(root, source)))]));

// Producer inventory is captured before navigation. The merger below keeps its
// own required inventory rather than trusting the report's claimed sources.
function producerSources() {
  return ['tests/Symfony/vdef_browser_probe.cjs', 'tests/Symfony/vdef_scenarios.py', 'tests/Symfony/session_bridge.py', 'package-lock.json', 'tests/e2e/package-lock.json', 'tests/e2e/browser-coverage.js',
    'tests/e2e/midwinter-listeners.spec.js', 'tests/e2e/selectmenu-scroll.spec.js', 'tests/e2e/theme-smoke.html', 'tests/e2e/playwright.config.js',
    'include/js/pace.js', 'include/js/jquery.zoom.js', 'lib/html.php', 'config/icons.json', 'include/fa/webfonts/fa-brands-400.woff2', 'include/js/jquery.js', 'include/js/jquery-ui.js', 'include/js/js.storage.js',
    'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js',
    'include/themes/dark/main.css', 'include/themes/classic/jquery-ui.css', 'include/fa/css/all.css', 'include/fa/webfonts/fa-solid-900.woff2',
    'include/themes/midwinter/vendor/mark/jquery.mark.js', 'include/themes/midwinter/vendor/hotkeys/hotkeys.js',
    'include/themes/midwinter/vendor/ua-parser/ua-parser.js', ...measuredSources];
}

const mergerSources = ['tests/Symfony/vdef_browser_probe.cjs', 'tests/Symfony/vdef_scenarios.py', 'tests/Symfony/session_bridge.py', 'public/js/vdef-item.js', 'package-lock.json', 'tests/e2e/package-lock.json', 'tests/e2e/browser-coverage.js',
  'tests/e2e/midwinter-listeners.spec.js', 'tests/e2e/selectmenu-scroll.spec.js', 'tests/e2e/theme-smoke.html', 'tests/e2e/playwright.config.js',
  'include/js/pace.js', 'include/js/jquery.zoom.js', 'lib/html.php', 'config/icons.json', 'include/fa/webfonts/fa-brands-400.woff2', 'include/js/jquery.js', 'include/js/jquery-ui.js', 'include/js/js.storage.js',
  'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js',
  'include/themes/dark/main.css', 'include/themes/classic/jquery-ui.css', 'include/fa/css/all.css', 'include/fa/webfonts/fa-solid-900.woff2',
  'include/themes/midwinter/vendor/mark/jquery.mark.js', 'include/themes/midwinter/vendor/hotkeys/hotkeys.js',
  'include/themes/midwinter/vendor/ua-parser/ua-parser.js',
  'include/realtime.js', 'include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const mergerMeasuredSources = ['public/js/vdef-item.js', 'include/realtime.js', 'include/layout.js', 'include/themes/classic/main.js', 'include/themes/modern/main.js',
  'include/themes/midwinter/main.js', 'include/themes/paw/main.js', 'include/themes/sunrise/main.js',
  'include/themes/paper-plane/main.js', 'include/themes/dark/main.js'];

const scenarios = {
  'vdef_browser_probe.cjs': ['files', 'database'].flatMap(handler =>
    ['/app.php', '/public/index.php', '/cacti/app.php', '/cacti/public/index.php'].map(front => `${handler}:${front}`)),
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
    'repeated native page setup keeps one search icon per input',
    'native compact navigation preserves menu glyphs and one dialog binding',
    'chrome windows: native client dialog displays parsed environment and glyphs',
    'internet explorer: native client dialog displays parsed environment and glyphs',
    'edge windows: native client dialog displays parsed environment and glyphs',
    'firefox ubuntu: native client dialog displays parsed environment and glyphs',
    'opera linux: native client dialog displays parsed environment and glyphs',
    'safari mac: native client dialog displays parsed environment and glyphs',
    'chrome os: native client dialog displays parsed environment and glyphs',
    'raspberry pi: native client dialog displays parsed environment and glyphs',
    'blackberry mobile: native client dialog displays parsed environment and glyphs',
    'android mobile: native client dialog displays parsed environment and glyphs',
    'ipad tablet: native client dialog displays parsed environment and glyphs',
    'playstation console: native client dialog displays parsed environment and glyphs',
    'smart television: native client dialog displays parsed environment and glyphs',
    'tesla embedded: native client dialog displays parsed environment and glyphs',
    'unknown client: native client dialog displays parsed environment and glyphs',
  ],
  'selectmenu-scroll.spec.js': [
    'select menu remains usable after the browser scrolls its button into view',
    'scrolling a panel after opening a select menu closes it',
    'a scroll queued before the menu opens does not immediately close it',
    'shared theme controls preserve filter icons, select widget sizing and both logos',
    'shared form controls retain import labels and theme widths',
    'responsive filters preserve control callbacks and visibility across clicks',
    'debug table actions and stored collapsible sections retain registry glyphs',
    'SNMP passphrase validation draws real status glyphs for each field state',
    'realtime graph activation preserves loading glyph response and original image',
    'dark graph hover retains handoff leave reinitialization and keyboard focus',
    'dark graph timer snapshots its element before a class hook changes shared state',
    'native own icon lookup preserves registry semantics with modern API available',
    'native own icon lookup preserves registry semantics with modern API absent',
  ],
};

function filterGlyphProductionLine() {
  const source = fs.readFileSync(path.join(root, 'include/themes/midwinter/main.js'), 'utf8');
  const lines = source.split('\n').flatMap((value, index) =>
    value.includes('<div class="cactiTableFilter">') && value.includes("iconClass('filter')")
      && value.includes(".prependTo('#filterTableOnTop .cactiTableTitle')") ? [index + 1] : []);
  if (lines.length !== 1) throw new Error('Missing or ambiguous filter glyph production statement');
  return lines[0];
}

// Bind a native scenario to its actual production statement, not just a
// loaded script or an arbitrary positive hit elsewhere in that file.
function nativeScenarioLines(scenario) {
  const layout = 'include/layout.js';
  const midwinter = 'include/themes/midwinter/main.js';
  const statements = scenario === 'dark graph hover retains handoff leave reinitialization and keyboard focus'
    ? [['include/themes/dark/main.js', "graphMenuElement = currentElement.attr('id').replace('dd', '');"], ['include/themes/dark/main.js', "currentElement.removeClass('iconsShown');"]]
    : scenario.startsWith('dark graph ')
      ? [['include/themes/dark/main.js', "graphMenuElement = currentElement.attr('id').replace('dd', '');"]]
    : scenario.startsWith('native own icon lookup ')
      ? [[layout, "typeof Object.hasOwn === 'function'"]]
      : scenario === 'realtime graph activation preserves loading glyph response and original image'
    ? [[layout, "class='drillDown "], ['include/realtime.js', "$.get(urlPath+'graph_realtime.php?action=countdown"]]
    : scenario === 'responsive filters preserve control callbacks and visibility across clicks'
    ? [[layout, "filterHeader.find('div.cactiTableButton').append($('<span style=\"display:none;\" class=\"cactiFilterExport\""]]
    : scenario === 'debug table actions and stored collapsible sections retain registry glyphs'
      ? [[layout, "anchors.filter('.cactiTableCopy').addClass(iconClass('copy'))"], [layout, "if ($(this).find('i').is(iconSelector('hide-section')))" ]]
      : scenario === 'SNMP passphrase validation draws real status glyphs for each field state'
        ? [[layout, "$(pass).after('<span id=\"'+spanconf+'\"><i class=\"goodpassword '"]]
        : scenario === 'native compact navigation preserves menu glyphs and one dialog binding'
          ? [[midwinter, "$(DOMPurify.sanitize(compact_user_menu_content)).appendTo('#compact_user_menu')"]]
          : scenario.endsWith(': native client dialog displays parsed environment and glyphs')
            ? [[midwinter, 'let uaObj = new UAParser()'], [midwinter, "$('#dialog_container').dialog({"]] : [];
  return statements.map(([source, text]) => {
    const lines = fs.readFileSync(path.join(root, source), 'utf8').split('\n')
      .flatMap((value, index) => value.includes(text) ? [index + 1] : []);
    if (lines.length !== 1) throw new Error(`Missing or ambiguous native browser statement: ${source}`);
    return [source, lines[0]];
  });
}

function loadEvidence(file, expectedProducer, expectedScenario) {
  const driver = path.basename(expectedProducer);
  const registeredProducer = driver === 'vdef_browser_probe.cjs' ? `tests/Symfony/${driver}` : `tests/e2e/${driver}`;
  if (expectedProducer !== registeredProducer || !scenarios[driver]?.includes(expectedScenario)) throw new Error('Unregistered browser scenario');
  const receipt = JSON.parse(fs.readFileSync(`${file}.receipt`, 'utf8'));
  const bytes = fs.readFileSync(file);
  const requiredHits = expectedScenario.startsWith('dark graph ')
    ? ['include/layout.js', 'include/themes/dark/main.js']
    : expectedProducer.endsWith('midwinter-listeners.spec.js')
    ? ['include/themes/midwinter/main.js']
    : expectedProducer.endsWith('vdef_browser_probe.cjs') ? ['public/js/vdef-item.js'] : ['include/layout.js'];
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
      && !(map.fileCoverageFor(path.join(root, 'include/themes/midwinter/main.js')).getLineCoverage()[filterGlyphProductionLine()] > 0)) {
    throw new Error('Missing actual filter glyph production line');
  }
  for (const [source, line] of nativeScenarioLines(expectedScenario)) {
    if (!(map.fileCoverageFor(path.join(root, source)).getLineCoverage()[line] > 0)) {
      throw new Error(`Missing actual native browser statement: ${source}:${line}`);
    }
  }
  if (expectedProducer.endsWith('vdef_browser_probe.cjs')) {
    const coverage = map.fileCoverageFor(path.join(root, 'public/js/vdef-item.js'));
    const line = fs.readFileSync(path.join(root, 'public/js/vdef-item.js'), 'utf8').split('\n')
      .findIndex(value => value.includes('window.location.assign(target)')) + 1;
    if (line < 1 || !(coverage.getLineCoverage()[line] > 0) || coverage.toSummary().lines.pct < 80) {
      throw new Error('Missing actual VDEF navigation or incomplete handler coverage');
    }
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
    for (const [source, line] of nativeScenarioLines(scenario)) {
      refuse(() => reportEdit(data => {
        const production = data[path.join(root, source)];
        for (const [key, location] of Object.entries(production.statementMap)) {
          if (location.start.line <= line && location.end.line >= line) production.s[key] = 0;
        }
      }));
    }
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
      if (pathname !== '/include/layout.js' && pathname !== '/include/realtime.js' && !/^\/include\/themes\/[a-z0-9_-]+\/main\.js$/.test(pathname)) continue;
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
    for (const scenario of Object.values(scenarios).flat().filter(title => nativeScenarioLines(title).length)) {
      for (const [source, line] of nativeScenarioLines(scenario)) {
        refuse(() => {
          const name = reports.find(file => JSON.parse(fs.readFileSync(path.join(owned, `${file}.receipt`))).scenario === scenario);
          const file = path.join(owned, name);
          const data = JSON.parse(fs.readFileSync(file));
          const production = data[path.join(root, source)];
          for (const [key, location] of Object.entries(production.statementMap)) {
            if (location.start.line <= line && location.end.line >= line) production.s[key] = 0;
          }
          const bytes = JSON.stringify(data);
          fs.writeFileSync(file, bytes);
          const receipt = JSON.parse(fs.readFileSync(`${file}.receipt`));
          receipt.digest = hash(bytes);
          fs.writeFileSync(`${file}.receipt`, JSON.stringify(receipt));
        });
      }
    }
    refuse(() => {
      const glyphFile = reports.find(file => JSON.parse(fs.readFileSync(path.join(owned, `${file}.receipt`))).scenario
        === 'the relocated filter keeps its production sliders glyph and controls');
      const file = path.join(owned, glyphFile);
      const data = JSON.parse(fs.readFileSync(file));
      const production = data[path.join(root, 'include/themes/midwinter/main.js')];
      const glyphLine = filterGlyphProductionLine();
      for (const [key, location] of Object.entries(production.statementMap)) {
        if (location.start.line <= glyphLine && location.end.line >= glyphLine) production.s[key] = 0;
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
    const expectedProducer = driver === 'vdef_browser_probe.cjs' ? `tests/Symfony/${driver}` : `tests/e2e/${driver}`;
    if (receipt.producer !== expectedProducer || !scenarios[driver]?.includes(receipt.scenario)) throw new Error('Unregistered browser scenario');
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

function publishVdefCoverage(map, snapshot, scenario) {
  const directory = process.env.KADUPUL_BROWSER_COVERAGE;
  if (!directory) return;
  if (!scenarios['vdef_browser_probe.cjs'].includes(scenario)) throw new Error('Unregistered VDEF browser scenario');
  if (JSON.stringify(sourceHashes(producerSources())) !== JSON.stringify(snapshot)) throw new Error('VDEF browser sources changed during execution');
  fs.mkdirSync(directory, { recursive: true });
  const file = path.join(directory, `${crypto.randomUUID()}.json`);
  const bytes = JSON.stringify(map.toJSON());
  fs.writeFileSync(file, bytes);
  fs.writeFileSync(`${file}.receipt`, JSON.stringify({ version: 1, root: fs.realpathSync(root),
    producer: 'tests/Symfony/vdef_browser_probe.cjs', scenario, sources: snapshot, digest: hash(bytes),
    completed: 'browser-test-passed-and-production-measured' }));
}

module.exports = { collectThemeCoverage, publishVdefCoverage, browserSourceSnapshot: () => sourceHashes(producerSources()),
  verifyBrowserReport: loadEvidence, verifyBrowserRejections: verifyRejections };
if (require.main === module) merge(process.argv[2], process.argv[3]);

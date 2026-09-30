// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const v8ToIstanbul = require('v8-to-istanbul');
const { createCoverageMap } = require('istanbul-lib-coverage');

const root = path.resolve(__dirname, '../..');

function collectThemeCoverage(test) {
  const directory = process.env.KADUPUL_BROWSER_COVERAGE;
  if (!directory) return;
  test.beforeEach(async ({ page }) => {
    await page.coverage.startJSCoverage();
  });
  test.afterEach(async ({ page }) => {
    const map = createCoverageMap({});
    for (const entry of await page.coverage.stopJSCoverage()) {
      if (!/^https?:\/\//.test(entry.url)) continue;
      const pathname = new URL(entry.url).pathname;
      if (!/^\/include\/themes\/[a-z0-9_-]+\/main\.js$/.test(pathname)) continue;
      const sourceFile = path.join(root, pathname);
      if (entry.source !== fs.readFileSync(sourceFile, 'utf8')) {
        throw new Error(`Browser coverage source differs from checkout: ${pathname}`);
      }
      const converter = v8ToIstanbul(sourceFile, 0, { source: entry.source });
      await converter.load();
      converter.applyCoverage(entry.functions);
      map.merge(converter.toIstanbul());
    }
    if (map.files().length === 0) throw new Error('No production theme script was measured');
    fs.mkdirSync(directory, { recursive: true });
    fs.writeFileSync(path.join(directory, `${crypto.randomUUID()}.json`), JSON.stringify(map.toJSON()));
  });
}

function merge(directory, output) {
  const map = createCoverageMap({});
  for (const file of fs.readdirSync(directory).filter(file => file.endsWith('.json'))) {
    map.merge(JSON.parse(fs.readFileSync(path.join(directory, file), 'utf8')));
  }
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
  console.log(`Browser coverage: ${covered} covered lines across ${map.files().length} theme scripts`);
}

module.exports = { collectThemeCoverage };
if (require.main === module) merge(process.argv[2], process.argv[3]);

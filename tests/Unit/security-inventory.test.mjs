// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runInNewContext } from 'node:vm';
import { collect, reconcile, priority } from '../../tools/security/inventory.mjs';

const issue = { key: 'sonar-1', rule: 'phpsecurity:S2076', component: 'project:host.php', line: 180 };

test('device reindex link cancels navigation and supplies a token to the POST loader', () => {
  const source = readFileSync(new URL('../../host.php', import.meta.url), 'utf8');
  const line = source.split('\n').find(value => value.includes('host.php?action=reindex&host_id='));
  assert.match(line, /data-post-action='true'/);
  const handler = line.match(/onclick='([^']+)'/)[1];
  const calls = [];
  const result = runInNewContext(`(function() { ${handler} }).call(link)`, {
    link: { href: 'host.php?action=reindex&host_id=7' },
    csrfMagicToken: 'test-token',
    loadPageUsingPost: (url, body) => calls.push([url, body.__csrf_magic, body.header]),
  });
  assert.equal(result, false);
  assert.deepEqual(calls, [['host.php?action=reindex&host_id=7', 'test-token', 'false']]);
});
function alert(key, number = 1) {
  return { number, rule: { id: issue.rule }, most_recent_instance: {
    message: { text: key ? `<!--SONAR_ISSUE_KEY:${key}-->finding` : 'finding' },
    location: { path: 'host.php', start_line: 180 }, commit_sha: 'abc',
  } };
}
test('matches stable Sonar keys, not coincident file locations', () => {
  const result = reconcile([issue], [alert('sonar-1'), alert('other', 2), alert(null, 3)]);
  assert.equal(result.rows.length, 1);
  assert.equal(result.rows[0].github.length, 1);
  assert.equal(result.unmatched.length, 2);
});
test('retains multiple GitHub references without duplicating a Sonar issue', () => {
  assert.equal(reconcile([issue], [alert('sonar-1'), alert('sonar-1', 2)]).rows[0].github.length, 2);
});
test('rejects duplicate source keys', () => {
  assert.throws(() => reconcile([issue, issue], []), /Duplicate/);
});
test('ranks injection before XSS and remaining controls without claiming exploitability', () => {
  assert.equal(priority('phpsecurity:S3649'), 1);
  assert.equal(priority('phpsecurity:S5131'), 2);
  assert.equal(priority('php:S2245'), 3);
  assert.equal(reconcile([issue], []).rows[0].assessment, 'unverified');
});

test('collects every page and writes counts, revision and ranked report', async () => {
  let dir;
  const pages = [];
  try {
    const collected = await collect({
      sonar: async (endpoint, params) => {
        if (endpoint === 'project_analyses/search') return { analyses: [{ key: 'analysis', revision: 'abc', date: '2026-09-20' }] };
        pages.push(params.p);
        return { paging: { total: 2 }, issues: [{ ...issue, key: params.p === '1' ? 'sonar-1' : 'sonar-2' }] };
      },
      runGh: (binary, args) => {
        assert.equal(binary, 'gh');
        assert.ok(args.includes('--paginate'));
        assert.ok(args.includes('ref=refs/heads/main'));
        return JSON.stringify([[alert('sonar-1')], [alert('sonar-2', 2)]]);
      },
    });
    dir = collected.output;
    assert.deepEqual(readdirSync(dir).sort(), ['TRIAGE.md', 'inventory.json']);
    if (process.platform !== 'win32') {
      assert.equal(statSync(dir).mode & 0o777, 0o700);
      for (const file of readdirSync(dir)) assert.equal(statSync(join(dir, file)).mode & 0o777, 0o600);
    }
    assert.deepEqual(pages, ['1', '2']);
    const result = JSON.parse(readFileSync(join(dir, 'inventory.json'), 'utf8'));
    assert.deepEqual(result.counts, { sonar: 2, github: 2, matchedGithub: 2, unmatchedGithub: 0, conservativeTotal: 2 });
    assert.equal(result.analysis.revision, 'abc');
    assert.match(readFileSync(join(dir, 'TRIAGE.md'), 'utf8'), /unverified|provisional/);
  } finally {
    if (dir) rmSync(dir, { recursive: true });
  }
});

test('retains older non-Sonar alerts and renders remote markup as report data', async () => {
  let dir;
  const unrelated = alert(null, 2);
  unrelated.tool = { name: 'CodeQL' };
  unrelated.most_recent_instance.commit_sha = 'older-codeql-scan';
  const payload = '<script>alert(1)</script>|[link](file:///tmp/a)\n**heading**';
  try {
    const collected = await collect({
      sonar: async endpoint => endpoint === 'project_analyses/search'
        ? { analyses: [{ key: 'analysis', revision: 'abc' }] }
        : { paging: { total: 1 }, issues: [{ ...issue, component: `project:${payload}` }] },
      runGh: () => JSON.stringify([[alert('sonar-1'), unrelated]]),
    });
    dir = collected.output;
    const result = JSON.parse(readFileSync(join(dir, 'inventory.json'), 'utf8'));
    assert.equal(result.counts.conservativeTotal, 2);
    assert.equal(result.counts.matchedGithub, 1);
    assert.equal(result.unmatched[0].tool, 'CodeQL');
    assert.equal(result.unmatched[0].revision, 'older-codeql-scan');
    // Assertion only: verifies JSON data round-trip; no HTML sink or rendering.
    // nosemgrep: javascript.lang.security.audit.unknown-value-with-script-tag.unknown-value-with-script-tag
    assert.equal(result.rows[0].path, payload);
    const markdown = readFileSync(join(dir, 'TRIAGE.md'), 'utf8');
    // Negative string assertions verify hostile markup is absent, not rendered.
    // nosemgrep: javascript.lang.security.audit.unknown-value-with-script-tag.unknown-value-with-script-tag
    assert.ok(!markdown.includes(payload));
    // nosemgrep: javascript.lang.security.audit.unknown-value-with-script-tag.unknown-value-with-script-tag
    assert.ok(!markdown.includes('<script>'));
    assert.ok(!markdown.includes('[link]'));
    assert.match(markdown, /&#60;script&#62;/);
  } finally {
    if (dir) rmSync(dir, { recursive: true });
  }
});

for (const response of [null, {}, { analyses: [] }, { analyses: [null] },
  { analyses: [{ key: 'analysis' }] }, { analyses: [{ key: '', revision: 'abc' }] },
  { analyses: [{ key: 'analysis', revision: ' ' }] }]) {
  test(`rejects unusable Sonar analysis ${JSON.stringify(response)} before collecting`, async () => {
    await assert.rejects(collect({
      sonar: async endpoint => {
        assert.equal(endpoint, 'project_analyses/search');
        return response;
      },
      runGh: () => assert.fail('Must not query GitHub without an analysis'),
    }), /No usable Sonar main analysis with a revision/);
  });
}

test('rejects an analysis disappearing during collection', async () => {
  let analyses = 0;
  await assert.rejects(collect({
    sonar: async endpoint => endpoint === 'project_analyses/search'
      ? { analyses: analyses++ === 0 ? [{ key: 'analysis', revision: 'abc' }] : [] }
      : { paging: { total: 1 }, issues: [issue] },
    runGh: () => JSON.stringify([[alert('sonar-1')]]),
  }), /No usable Sonar main analysis with a revision/);
});

for (const scenario of ['count changed', 'empty page', 'analysis changed', 'revision mismatch']) {
  test(`fails closed when ${scenario}`, async () => {
    let calls = 0;
    let issuePages = 0;
    await assert.rejects(collect({
      sonar: async endpoint => {
        if (endpoint === 'project_analyses/search') {
          calls++;
          return { analyses: [{ key: scenario === 'analysis changed' ? String(calls) : 'same', revision: scenario === 'revision mismatch' ? 'different' : 'abc' }] };
        }
        if (scenario === 'empty page') return { paging: { total: 1 }, issues: [] };
        if (scenario === 'count changed') return { paging: { total: 2 + issuePages++ }, issues: [issue] };
        return { paging: { total: 1 }, issues: [issue] };
      },
      runGh: () => JSON.stringify([[alert('sonar-1')]]),
    }), /Issue count changed|Incomplete Sonar pagination|Sonar analysis changed|GitHub and Sonar revisions differ/);
});
}

test('CLI rejects output path arguments without touching existing files', () => {
  const directory = mkdtempSync(join(tmpdir(), 'inventory-cli-test-'));
  const target = join(directory, 'inventory.json');
  writeFileSync(target, 'preserve me');
  try {
    for (const argument of [directory, '../outside', '/tmp', '--output=elsewhere']) {
      const result = spawnSync(process.execPath, ['tools/security/inventory.mjs', argument], {
        cwd: new URL('../../', import.meta.url), encoding: 'utf8',
      });
      assert.equal(result.status, 1);
      assert.match(result.stderr, /no output path accepted/);
      assert.equal(readFileSync(target, 'utf8'), 'preserve me');
      assert.deepEqual(readdirSync(directory), ['inventory.json']);
    }
  } finally {
    rmSync(directory, { recursive: true });
  }
});

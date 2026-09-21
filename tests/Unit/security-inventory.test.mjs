// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
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
  runInNewContext(`(function(event) { ${handler} }).call(link, event)`, {
    link: { href: 'host.php?action=reindex&host_id=7' },
    event: { preventDefault: () => calls.push('prevented') },
    csrfMagicToken: 'test-token',
    loadPageUsingPost: (url, body) => calls.push([url, body.__csrf_magic, body.header]),
  });
  assert.deepEqual(calls, ['prevented', ['host.php?action=reindex&host_id=7', 'test-token', 'false']]);
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
  const dir = mkdtempSync(join(tmpdir(), 'security-inventory-test-'));
  const pages = [];
  try {
    await collect(dir, {
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
    assert.deepEqual(pages, ['1', '2']);
    const result = JSON.parse(readFileSync(join(dir, 'inventory.json'), 'utf8'));
    assert.deepEqual(result.counts, { sonar: 2, github: 2, matchedGithub: 2, unmatchedGithub: 0, conservativeTotal: 2 });
    assert.equal(result.analysis.revision, 'abc');
    assert.match(readFileSync(join(dir, 'TRIAGE.md'), 'utf8'), /unverified|provisional/);
  } finally {
    rmSync(dir, { recursive: true });
  }
});

for (const scenario of ['count changed', 'empty page', 'analysis changed', 'revision mismatch']) {
  test(`fails closed when ${scenario}`, async () => {
    let calls = 0;
    let issuePages = 0;
    await assert.rejects(collect('/unused-on-error', {
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

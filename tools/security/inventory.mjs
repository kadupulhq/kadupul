// SPDX-License-Identifier: GPL-3.0-or-later
// Read-only scanner collection. No alerts are dismissed or modified.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export function priority(rule) {
  if (/:(S2076|S3649|S2068)$/.test(rule)) return 1;
  if (/:(S5131|S2083|S2631|S6350)$/.test(rule)) return 2;
  return 3;
}

export function reconcile(issues, alerts) {
  const rows = issues.map(issue => ({
    sonarKey: issue.key, rule: issue.rule, severity: issue.severity,
    path: issue.component.slice(issue.component.indexOf(':') + 1),
    line: issue.line ?? null, message: issue.message,
    priority: priority(issue.rule), assessment: 'unverified',
    github: [],
  }));
  const keys = new Map(rows.map(row => [row.sonarKey, row]));
  if (keys.size !== rows.length) throw new Error('Duplicate Sonar issue keys');
  const unmatched = [];
  for (const alert of alerts) {
    const instance = alert.most_recent_instance;
    const key = (instance.message.text ?? '').match(/<!--SONAR_ISSUE_KEY:([\w-]+?)-->/)?.[1];
    const reference = { number: alert.number, url: alert.html_url,
      revision: instance.commit_sha, sonarKey: key ?? null };
    if (key && keys.has(key)) keys.get(key).github.push(reference);
    else unmatched.push({ ...reference, rule: alert.rule.id,
      path: instance.location.path, line: instance.location.start_line,
      assessment: 'unmatched; investigate before counting as duplicate' });
  }
  rows.sort((a, b) => a.priority - b.priority || a.path.localeCompare(b.path) || (a.line ?? 0) - (b.line ?? 0));
  return { rows, unmatched };
}

async function fetchSonar(endpoint, parameters) {
  const url = new URL(`https://sonarcloud.io/api/${endpoint}`);
  url.search = new URLSearchParams(parameters);
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Sonar HTTP ${response.status}`);
  return response.json();
}

export async function collect(output, { sonar = fetchSonar, runGh = execFileSync } = {}) {
  const project = 'kadupulhq_kadupul';
  const analysis = async () => (await sonar('project_analyses/search', {
    project, branch: 'main', ps: '1',
  })).analyses[0];
  const before = await analysis();
  const issues = [];
  let expected;
  for (let page = 1; ; page++) {
    const result = await sonar('issues/search', { componentKeys: project,
      branch: 'main', resolved: 'false', types: 'VULNERABILITY', ps: '500', p: String(page) });
    expected ??= result.paging.total;
    if (expected !== result.paging.total) throw new Error('Issue count changed during pagination; retry');
    issues.push(...result.issues);
    if (issues.length >= expected) break;
    if (!result.issues.length) throw new Error('Incomplete Sonar pagination');
  }
  if (issues.length !== expected) throw new Error('Incomplete Sonar inventory');
  const alerts = JSON.parse(runGh('gh', ['api',
    'repos/kadupulhq/kadupul/code-scanning/alerts', '-X', 'GET',
    '-f', 'state=open', '-f', 'ref=refs/heads/main', '-f', 'per_page=100',
    '--paginate', '--slurp'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })).flat();
  const after = await analysis();
  if (before.key !== after.key) throw new Error('Sonar analysis changed during collection; retry');
  if (alerts.some(a => a.most_recent_instance.commit_sha !== before.revision)) {
    throw new Error('GitHub and Sonar revisions differ; wait for scans to converge');
  }
  const inventory = { collectedAt: new Date().toISOString(), branch: 'main',
    analysis: { key: before.key, date: before.date, revision: before.revision },
    ...reconcile(issues, alerts) };
  inventory.counts = { sonar: issues.length, github: alerts.length,
    matchedGithub: alerts.length - inventory.unmatched.length,
    unmatchedGithub: inventory.unmatched.length,
    conservativeTotal: inventory.rows.length + inventory.unmatched.length };
  const groups = new Map();
  for (const row of inventory.rows) {
    const key = `P${row.priority} | ${row.rule} | ${row.path}`;
    groups.set(key, (groups.get(key) ?? 0) + 1);
  }
  const report = `# Security remediation inventory\n\nGenerated: ${inventory.collectedAt}\n\nMain revision: ${before.revision}\n\n`
    + `Sonar: ${issues.length}; GitHub: ${alerts.length}; exact overlaps: ${inventory.counts.matchedGithub}; unmatched: ${inventory.unmatched.length}.\n\n`
    + 'Priorities are provisional rule-based triage, not proof of exploitability. P1: command/SQL injection and embedded secrets. P2: XSS, path, LDAP and template injection. P3: remaining controls. Authentication/authorization findings from manual review must be tracked separately.\n\n'
    + 'Fix batches: first host reindex argv/POST enforcement; next validate remote-agent command flows and SQL helper callers; then context-specific XSS batches by controller/shared rendering helper. Do not globally escape HTML helpers or dismiss validated-input flows without evidence. Preserve LTS and confirm closure only after a main scan.\n\n'
    + '| Priority | Rule | File | Findings |\n|---|---|---|---:|\n'
    + [...groups].map(([key, count]) => `| ${key} | ${count} |`).join('\n') + '\n';
  mkdirSync(output, { recursive: true });
  writeFileSync(resolve(output, 'inventory.json'), JSON.stringify(inventory, null, 2) + '\n');
  writeFileSync(resolve(output, 'TRIAGE.md'), report);
  console.log(JSON.stringify(inventory.counts));
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  if (!process.argv[2]) throw new Error('Usage: node tools/security/inventory.mjs OUTPUT_DIRECTORY');
  await collect(resolve(process.argv[2]));
}

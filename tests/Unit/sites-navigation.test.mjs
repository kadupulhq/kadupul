// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../include/layout.js', import.meta.url), 'utf8');
function implementation(name) {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0);
  const end = source.indexOf('\nfunction ', start + 10);
  return source.slice(start, end < 0 ? undefined : end);
}
function context(allow = true) {
  const locations = [];
  const scope = { URL, checkFormStatus: () => allow, window: { location: {
    href: 'https://example.test/kadupul/host.php', origin: 'https://example.test',
    assign: value => locations.push(value),
  } } };
  runInNewContext(['navigateToSymfonySites', 'loadPage', 'loadPageNoHeader'].map(implementation).join('\n'), scope);
  return { scope, locations };
}

test('legacy navigation opens Symfony Sites as a full document through either loader', () => {
  for (const loader of ['loadPage', 'loadPageNoHeader']) {
    for (const path of ['sites.php?action=edit&id=7', 'app.php/inventory/sites', 'public/index.php/inventory/sites/7/edit']) {
      const { scope, locations } = context();
      scope[loader](path);
      assert.deepEqual(locations, [new URL(path, scope.window.location.href).href]);
    }
  }
});

test('Sites navigation preserves the unsaved-form cancellation', () => {
  for (const loader of ['loadPage', 'loadPageNoHeader']) {
    const { scope, locations } = context(false);
    scope[loader]('sites.php');
    assert.deepEqual(locations, []);
  }
});

test('unrelated paths, foreign origins and lookalike query strings remain outside the handoff', () => {
  const { scope, locations } = context();
  for (const path of ['host.php?next=sites.php', 'sites.php.bak', 'app.php/inventory/sites-malicious', 'https://foreign.test/sites.php', 'javascript:alert(1)', 'http://[invalid']) {
    assert.equal(scope.navigateToSymfonySites(path), false, path);
  }
  assert.deepEqual(locations, []);
});

test('graph item input links from the legacy template page open the Symfony editor as a full document', () => {
  for (const loader of ['loadPage', 'loadPageNoHeader']) {
    for (const path of ['graph_templates_inputs.php?action=input_edit&id=4&graph_template_id=2', 'graph_templates_inputs.php?action=input_remove&id=4&graph_template_id=2&nostate=true',
      'app.php/graphing/graph-templates/2/inputs/new', 'app.php/graphing/graph-templates/2/inputs/4/edit', 'public/index.php/graphing/graph-templates/2/inputs/4/delete']) {
      const { scope, locations } = context();
      scope[loader](path);
      assert.deepEqual(locations, [new URL(path, scope.window.location.href).href], path);
    }
  }
  const { scope, locations } = context();
  for (const path of ['graph_templates.php?action=template_edit&id=2', 'graph_templates_items.php?action=item_edit', 'app.php/graphing/graph-templates', 'app.php/graphing/graph-templates/0/inputs/new', 'app.php/graphing/graph-templates/2/inputs-x', 'graph_templates_inputs.php.bak']) {
    assert.equal(scope.navigateToSymfonySites(path), false, path);
  }
  assert.deepEqual(locations, []);
});

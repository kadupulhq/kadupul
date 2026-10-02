// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const fixture = fileURLToPath(new URL('../Fixtures/permission-filter-native.php', import.meta.url));
for (const page of ['user_admin.php', 'user_group_admin.php']) {
  const tabs = { graph: 'permsg', device: 'permsd', template: 'permste', tree: 'permstr', member: 'members' };
  if (page === 'user_admin.php') tabs.group = 'permsgr';
  for (const [name, tab] of Object.entries(tabs)) {
    test(`${page} ${name} preserves filter URLs, callback arguments and all event bindings`, () => {
      const directory = mkdtempSync(join(tmpdir(), 'permission-filter-js-'));
      let result;
      try {
        result = spawnSync(process.env.PHP_BINARY || 'php', [fixture, JSON.stringify({ page, function: name }), directory], { encoding: 'utf8' });
      } finally {
        rmSync(directory, { recursive: true, force: true });
      }
      assert.equal(result.status, 0, result.stdout + result.stderr);
      assert.equal(result.stderr, '');
      const { scripts } = JSON.parse(result.stdout);
      assert.equal(scripts.length, 1);
      const script = scripts[0];
      const bindings = new Map();
      const calls = [];
      const fields = { '#rows': '25', '#filter': 'A & B#C+D?é', '#graph_template_id': '3', '#host_template_id': '4' };
      const context = vm.createContext({
        loadPageNoHeader(url) { calls.push(url); },
        $(selector) {
          if (typeof selector === 'function') return selector();
          return {
            val() { assert.ok(selector in fields); return fields[selector]; },
            is(value) { assert.equal(selector, '#associated'); assert.equal(value, ':checked'); return true; },
            on(event, callback) { bindings.set(`${selector}:${event}`, callback); },
          };
        },
      });
      vm.runInContext(script, context);
      const action = page === 'user_admin.php' ? 'user_edit' : 'edit';
      const base = `${page}?action=${action}&tab=${tab}&id=7`;
      const template = name === 'graph' ? '&graph_template_id=3' : name === 'device' ? '&host_template_id=4' : '';
      const expected = `${base}&rows=25${template}&associated=true&filter=${encodeURIComponent(fields['#filter'])}&header=false`;
      const extraField = name === 'graph' ? ', #graph_template_id' : name === 'device' ? ', #host_template_id' : '';
      assert.deepEqual([...bindings.keys()].sort(), ['#associated:click', '#clear:click', '#forms:submit', `#rows${extraField}:change`].sort());
      assert.equal(context.applyFilter.length, page === 'user_admin.php' && name === 'member' ? 1 : 0);
      assert.equal(context.clearFilter.length, page === 'user_admin.php' && ['graph', 'device', 'member'].includes(name) ? 1 : 0);
      context.applyFilter();
      const submitted = calls.pop();
      assert.equal(submitted, expected);
      assert.equal(new URL(submitted, 'https://fixture.invalid/').searchParams.get('filter'), fields['#filter']);
      bindings.get('#associated:click')();
      assert.equal(calls.pop(), expected);
      bindings.get(`#rows${extraField}:change`)();
      assert.equal(calls.pop(), expected);
      let prevented = false;
      bindings.get('#forms:submit')({ preventDefault() { prevented = true; } });
      assert.equal(prevented, true);
      assert.equal(calls.pop(), expected);
      bindings.get('#clear:click')();
      assert.equal(calls.pop(), `${base}&clear=true&header=false`);
      for (const search of ['', ' ', 'literal search', 'A & B', '#fragment', 'A+B', 'key=value?next', 'é 日本語', '%20']) {
        fields['#filter'] = search;
        context.applyFilter();
        const parsed = new URL(calls.pop(), 'https://fixture.invalid/');
        assert.equal(parsed.searchParams.get('filter'), search);
        assert.equal(parsed.searchParams.get('header'), 'false');
        assert.equal(parsed.searchParams.get('id'), '7');
        assert.equal(parsed.searchParams.get('tab'), tab);
        assert.equal(parsed.hash, '');
      }
    });
  }
}

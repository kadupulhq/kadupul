# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run each frozen original and its shim against the same database and compare."""
from pathlib import Path
import re
import shlex

ROOT = '/var/www/html'
QUIET = 'KADUPUL_CLI_QUIET_DEPRECATION=1'
ORIGINAL = 'tests/Fixtures/legacy-cli/analyze_database.php'
SHIM = 'cli/analyze_database.php'
COMPLETE = 'ANALYSIS STATS: Analyzing Kadupul Tables Complete'
TREE_CHECKS = [
    'tree CLI creates a node under an existing header in its tree',
    'tree CLI permits root placement',
    'tree CLI rejects a nonexistent parent without inserting a node',
    'tree CLI rejects a parent from another tree without inserting a node',
    'tree CLI rejects a graph item as a parent without inserting a node',
    'tree CLI rejects host site and empty header parents without writes',
    'tree CLI rejects missing tree and malformed parent grammar without writes',
    'tree CLI stores the complete site identity without a copied title',
    'tree CLI site placement preserves a valid header and rejects duplicates',
    'site tree nodes render renamed site identity and current devices',
    'tree CLI rejects invalid site identity with a diagnostic and no writes',
    'tree API rejects all non-header parents and preserves rejected updates',
]
# Only the wall-clock time of day may differ between two runs; the date
# layout and its separator come from settings and are compared as written.
CLOCK = re.compile(r'^(\S+) \d{2}:\d{2}:\d{2} - ')

# (label, arguments, allowed difference). Only argument sets the original
# accepts belong here; --as exists only in the shim and is checked on its own.
CASES = [
    ('analyze', [], None),
    ('analyze debug', ['-d'], None),
    ('analyze local on the primary', ['--local'], None),
    ('analyze version', ['--version'], 'copyright year only'),
    ('analyze help', ['-h'], None),
    ('analyze invalid flag', ['--bogus'], 'version line before the error'),
]


def run(harness, script, arguments, env=QUIET):
    # Quoted, so a table name with a backtick reaches PHP as typed.
    command = f"cd {ROOT} && {env} php {script} " + ' '.join(shlex.quote(argument) for argument in arguments)
    return harness.command('sh', '-c', command)


def normalise(text):
    return re.sub(r'Copyright \(C\) 2004-\d{4}', 'Copyright (C) 2004-YEAR', text)


def install_original(harness, fixture=ORIGINAL):
    # The image leaves tests/ out of its build context, so the frozen copy is
    # placed at the path its relative require expects.
    source = Path(__file__).resolve().parents[2] / fixture
    harness.command('mkdir', '-p', f'{ROOT}/{Path(fixture).parent}', check=True)
    harness.compose('cp', str(source), f'web:{ROOT}/{fixture}')


def log_lines(harness):
    return harness.command('cat', f'{ROOT}/log/cacti.log', check=True)['stdout'].splitlines()


def clock_free(lines, marker):
    return [CLOCK.sub(r'\1 HH:MM:SS - ', line) for line in lines if marker in line]


def verify_cli_parity(harness, check):
    install_original(harness)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the command acts as settings.admin_user, as the web
        # installer records it, so every shim case needs that row.
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
        verify_cases(harness, check)
        verify_shim_only(harness, check)
        verify_absent_admin_user(harness, check, admin)
    finally:
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")


def verify_tree_cli(harness, check):
    """Verify tree node creation rejects invalid tree and parent references."""
    prefix = 'kadupul-cli-parent-check'
    host_id = None
    old_host_site = None
    old_host_disabled = None
    site_id = None
    harness.sql(f"DELETE FROM graph_tree_items WHERE graph_tree_id IN (SELECT id FROM graph_tree WHERE name LIKE '{prefix}-%'); "
                f"DELETE FROM graph_tree WHERE name LIKE '{prefix}-%';")
    try:
        host = harness.sql('SELECT id, site_id, disabled, description FROM host ORDER BY id LIMIT 1').strip().split('\t')
        host_id, old_host_site, old_host_disabled, description = host
        site_name = prefix + '-site'
        harness.sql(f"DELETE FROM sites WHERE name = '{site_name}'; INSERT INTO sites (name) VALUES ('{site_name}')")
        site_id = int(harness.sql(f"SELECT id FROM sites WHERE name = '{site_name}'").strip())
        harness.sql(f"UPDATE host SET site_id = {site_id}, disabled = '' WHERE id = {host_id}")

        harness.sql(f"INSERT INTO graph_tree (name, sort_type) VALUES ('{prefix}-one', 1), ('{prefix}-two', 1)")
        tree_one = int(harness.sql(f"SELECT id FROM graph_tree WHERE name = '{prefix}-one'").strip())
        tree_two = int(harness.sql(f"SELECT id FROM graph_tree WHERE name = '{prefix}-two'").strip())
        harness.sql(f"INSERT INTO graph_tree_items (graph_tree_id, parent, title) VALUES ({tree_one}, 0, 'parent-one'), ({tree_two}, 0, 'parent-two')")
        harness.sql(f"INSERT INTO graph_tree_items (graph_tree_id, parent, local_graph_id) VALUES ({tree_one}, 0, 1)")
        parent_one = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id = {tree_one} AND title = 'parent-one'").strip())
        parent_two = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id = {tree_two} AND title = 'parent-two'").strip())
        graph_item = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id = {tree_one} AND local_graph_id = 1").strip())

        valid = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                  f'--parent-node={parent_one}', '--name=valid-child'])
        valid_parent = harness.sql(f"SELECT parent FROM graph_tree_items WHERE graph_tree_id = {tree_one} AND title = 'valid-child'").strip()
        check(valid['exit'] == 0 and valid_parent == str(parent_one), 'tree CLI creates a node under an existing header in its tree')

        root = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                 '--parent-node=0', '--name=root-child'])
        root_parent = harness.sql(f"SELECT parent FROM graph_tree_items WHERE graph_tree_id = {tree_one} AND title = 'root-child'").strip()
        check(root['exit'] == 0 and root_parent == '0', 'tree CLI permits root placement')

        before = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        missing = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                    '--parent-node=999999999', '--name=missing-parent'])
        after_missing = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        if not (missing['exit'] == 1 and 'does not exist' in missing['stderr'] and before == after_missing):
            print(f'tree CLI missing parent: result={missing!r}, rows={before!r}->{after_missing!r}', flush=True)
        check(missing['exit'] == 1 and 'does not exist' in missing['stderr'] and before == after_missing,
              'tree CLI rejects a nonexistent parent without inserting a node')

        foreign = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                    f'--parent-node={parent_two}', '--name=foreign-parent'])
        after_foreign = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        check(foreign['exit'] == 1 and 'does not exist in tree' in foreign['stderr'] and before == after_foreign,
              'tree CLI rejects a parent from another tree without inserting a node')

        non_container = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                          f'--parent-node={graph_item}', '--name=non-container-parent'])
        after_non_container = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        check(non_container['exit'] == 1 and 'not a header in tree' in non_container['stderr'] and before == after_non_container,
              'tree CLI rejects a graph item as a parent without inserting a node')

        invalid_tree = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', '--tree-id=999999999',
                                                         '--parent-node=0', '--name=missing-tree'])
        check(invalid_tree['exit'] == 1 and 'does not exist' in invalid_tree['stderr'],
              'tree CLI rejects a nonexistent target tree')

        valid_site = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--tree-id={tree_one}',
                                                       f'--site-id={site_id}'])
        site_item = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id = {tree_one} AND site_id = {site_id}").strip())
        site_row = harness.sql(f"SELECT graph_tree_id,parent,title,local_graph_id,host_id,site_id,host_grouping_type,sort_children_type FROM graph_tree_items WHERE id = {site_item}").rstrip('\n').split('\t')
        check(valid_site['exit'] == 0 and site_row == [str(tree_one), '0', '', '0', '0', str(site_id), '1', '2'],
              'tree CLI stores the complete site identity without a copied title')
        under_header = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--tree-id={tree_one}',
                                                          f'--site-id={site_id}', f'--parent-node={parent_one}'])
        header_row = harness.sql(f"SELECT parent,title,local_graph_id,host_id,site_id FROM graph_tree_items WHERE graph_tree_id={tree_one} AND parent={parent_one} AND site_id={site_id}").rstrip('\n').split('\t')
        count_before_duplicate = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id={tree_one}").strip()
        duplicate_site = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--tree-id={tree_one}',
                                                            f'--site-id={site_id}', f'--parent-node={parent_one}'])
        check(under_header['exit'] == 0 and header_row == [str(parent_one), '', '0', '0', str(site_id)]
              and duplicate_site['exit'] == 1 and 'Failed to create the node' in duplicate_site['stderr']
              and harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id={tree_one}").strip() == count_before_duplicate,
              'tree CLI site placement preserves a valid header and rejects duplicates')
        # Nonempty host/site labels isolate those guards from the separate
        # empty-title guard, including rows created by the old site CLI.
        harness.sql(f"INSERT INTO graph_tree_items (graph_tree_id,parent,host_id,title) VALUES ({tree_one},0,{host_id},'host-parent'); INSERT INTO graph_tree_items (graph_tree_id,parent,site_id,title) VALUES ({tree_one},0,{site_id},'site-parent'); INSERT INTO graph_tree_items (graph_tree_id,parent,title) VALUES ({tree_one},0,'')")
        host_parent = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id={tree_one} AND title='host-parent'").strip())
        site_parent = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id={tree_one} AND title='site-parent'").strip())
        empty_parent = int(harness.sql(f"SELECT id FROM graph_tree_items WHERE graph_tree_id={tree_one} AND title='' AND host_id=0 AND site_id=0 AND local_graph_id=0").strip())
        rejected_parents = []
        before_rejections = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items").strip()
        for parent in (host_parent, site_parent, empty_parent):
            rejected = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}', f'--parent-node={parent}', '--name=forbidden-child'])
            rejected_parents.append(rejected['exit'] == 1 and 'not a header in tree' in rejected['stderr'])
        check(all(rejected_parents) and harness.sql('SELECT COUNT(*) FROM graph_tree_items').strip() == before_rejections,
              'tree CLI rejects host site and empty header parents without writes')
        grammar = [(['--parent-node=abc', f'--tree-id={tree_one}'], 'non-negative integer'),
                   (['--parent-node=0'], 'existing --tree-id')]
        grammar_results = [run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', '--name=invalid-grammar', *args]) for args, _ in grammar]
        check(all(result['exit'] == 1 and message in result['stderr'] for result, (_, message) in zip(grammar_results, grammar))
              and harness.sql('SELECT COUNT(*) FROM graph_tree_items').strip() == before_rejections,
              'tree CLI rejects missing tree and malformed parent grammar without writes')
        renamed_site = site_name + '-renamed'
        harness.sql(f"UPDATE sites SET name='{renamed_site}' WHERE id={site_id}")

        from harness import Session
        session = Session(harness.base)
        login = session.login('behavior-admin')
        tree_response = session.opener.open(
            harness.base + f'/graph_view.php?action=get_node&id=tree_anchor-{tree_one}&tree_id=0'
        )
        rendered = tree_response.read().decode('utf-8', errors='replace')
        check(not login['login_form'] and f'tbranch-{site_item}-site-{site_id}' in rendered and description in rendered
              and renamed_site in rendered and harness.sql(f"SELECT title FROM graph_tree_items WHERE id={site_item}").rstrip('\n') == '',
              'site tree nodes render renamed site identity and current devices')

        before_invalid_site = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        invalid_site = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--tree-id={tree_one}',
                                                         '--site-id=999999999'])
        after_invalid_site = harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id = {tree_one}").strip()
        check(invalid_site['exit'] == 1 and 'No such site-id' in invalid_site['stdout'] and before_invalid_site == after_invalid_site,
              'tree CLI rejects a nonexistent site without creating a blank row')
        invalid_sites = [run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--tree-id={tree_one}', *args])
                         for args in (['--site-id=abc'], [])]
        missing_site_tree = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=site', f'--site-id={site_id}'])
        check(all(result['exit'] == 1 and 'No such site-id' in result['stdout'] for result in invalid_sites)
              and missing_site_tree['exit'] == 1 and 'existing --tree-id' in missing_site_tree['stderr']
              and harness.sql(f"SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id={tree_one}").strip() == before_invalid_site,
              'tree CLI rejects invalid site identity with a diagnostic and no writes')
        invalid_tree_id = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', '--tree-id=not-a-number',
                                                            '--parent-node=0', '--name=invalid-tree-id'])
        check(invalid_tree_id['exit'] == 1 and 'existing --tree-id' in invalid_tree_id['stderr'],
              'tree CLI rejects a malformed target tree id')

        invalid_parent_id = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                              '--parent-node=-1', '--name=invalid-parent-id'])
        check(invalid_parent_id['exit'] == 1 and 'non-negative integer' in invalid_parent_id['stderr'],
              'tree CLI rejects a negative parent id')

        harness.sql(f'INSERT INTO graph_tree_items (graph_tree_id, parent, host_id) VALUES ({tree_one}, 0, {host_id})')
        duplicate_host = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=host', f'--tree-id={tree_one}',
                                                           '--parent-node=0', f'--host-id={host_id}'])
        check(duplicate_host['exit'] == 1 and 'Failed to create the node' in duplicate_host['stderr'],
              'tree CLI reports duplicate node rejection as a failed command')

        api_probe = f'''\
require '/var/www/html/include/cli_check.php';
require_once '/var/www/html/lib/api_automation_tools.php';
require_once '/var/www/html/lib/api_tree.php';
$tree = {tree_one};
$foreignParent = {parent_two};
$graphParent = {graph_item};
$before = db_fetch_row_prepared('SELECT * FROM graph_tree_items WHERE graph_tree_id=? AND title=?', [$tree, 'valid-child']);
$rejected = [
    api_tree_item_save(0, 999999999, 1, 0, 'missing-tree', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, 999999999, 'missing-parent', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, $foreignParent, 'foreign-parent', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, $graphParent, 'graph-parent', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, {host_parent}, 'host-parent-child', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, {site_parent}, 'site-parent-child', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, {empty_parent}, 'empty-parent-child', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save((int)$before['id'], $tree, 1, $foreignParent, 'changed', 0, 0, 0, 1, 2, false) === false,
    db_fetch_row_prepared('SELECT * FROM graph_tree_items WHERE id=?', [$before['id']]) === $before,
];
echo json_encode($rejected);
'''
        api_result = harness.command('php', '-r', api_probe)
        check(api_result['exit'] == 0 and api_result['stdout'].strip() == '[true,true,true,true,true,true,true,true,true]',
              'tree API rejects all non-header parents and preserves rejected updates')
    finally:
        harness.sql(f"DELETE FROM graph_tree_items WHERE graph_tree_id IN (SELECT id FROM graph_tree WHERE name LIKE '{prefix}-%'); "
                    f"DELETE FROM graph_tree WHERE name LIKE '{prefix}-%';")
        if host_id is not None:
            harness.sql(f"UPDATE host SET site_id = {old_host_site}, disabled = '{old_host_disabled}' WHERE id = {host_id}")
        if site_id is not None:
            harness.sql(f"DELETE FROM sites WHERE id = {site_id}")


def verify_cases(harness, check):
    logged = []
    for label, arguments, allowed in CASES:
        marks = len(log_lines(harness))
        original = run(harness, ORIGINAL, arguments)
        between = len(log_lines(harness))
        shim = run(harness, SHIM, arguments)
        lines = log_lines(harness)
        # The elapsed seconds in the message are a clock reading too.
        original_log = [re.sub(r'Total time \d+ seconds', 'Total time N seconds', line) for line in clock_free(lines[marks:between], COMPLETE)]
        shim_log = [re.sub(r'Total time \d+ seconds', 'Total time N seconds', line) for line in clock_free(lines[between:], COMPLETE)]
        if shim_log != original_log:
            print(f'{label}: original log {original_log!r}\n{label}: shim log {shim_log!r}', flush=True)
        check(shim_log == original_log, f'{label}: shim logs the same completion line, date included')
        logged += shim_log
        expected = normalise(original['stdout'])
        actual = normalise(shim['stdout'])
        if allowed == 'version line before the error':
            # The original prints its version line inside the help that follows
            # the error; the shim reaches the error before it boots the kernel.
            expected = '\n'.join(line for line in expected.splitlines() if not line.startswith('Kadupul Analyze Database Utility'))
            actual = '\n'.join(actual.splitlines())
        if actual != expected:
            print(f'{label}: original {original!r}\n{label}: shim {shim!r}', flush=True)
        check(shim['exit'] == original['exit'], f'{label}: shim exit code matches the original')
        check(actual == expected, f'{label}: shim stdout matches the original')
        check(shim['stderr'] == original['stderr'], f'{label}: shim stderr matches the original')
        if label == 'analyze':
            # Matching output is only evidence if the shim reached ANALYZE
            # through the kernel container and the real database.
            check(shim['exit'] == 0 and "NOTE: Analyzing Table -> 'settings' Successful\n" in shim['stdout'],
                  'analyze: shim analyzes every table through the kernel container')
    # Three run cases each for the original and the shim.
    check(len(logged) == 3, 'both the original and the shim log the completion line')


def verify_shim_only(harness, check):
    # A real ANALYZE through the kernel container: the command and
    # AnalyzeDatabase share one CliConsoleAccess, so select() must reach the
    # instance the use case resolves the actor from.
    named = run(harness, SHIM, ['--as=admin'])
    check(named['exit'] == 0 and named['stdout'].startswith('NOTE: Analyzing All Kadupul Database Tables\n')
          and "NOTE: Analyzing Table -> 'settings' Successful\n" in named['stdout'] and named['stderr'] == '',
          'shim analyzes every table as the operator named by --as')
    noisy = run(harness, SHIM, ['-h'], env='')
    check(noisy['exit'] == 0 and 'is deprecated; use bin/console kadupul:database:analyze' in noisy['stderr']
          and 'deprecated' not in noisy['stdout'], 'shim prints its deprecation note on stderr only')
    denied = run(harness, SHIM, ['--as=nobody'])
    check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n',
          'unknown operator is refused without naming the account')
    empty = run(harness, SHIM, ['--as='])
    check(empty['exit'] == 1 and empty['stdout'].startswith('ERROR: Invalid Parameter --as=\n'),
          'empty --as is refused rather than falling back to the admin account')


def verify_absent_admin_user(harness, check, admin):
    # read_config_option() falls back to the declared default, user 1, when the
    # row is missing, so the shim must act as that account just as the
    # original ran without asking.
    check(admin == '1', 'absent admin_user: the harness admin account is user 1')
    try:
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        original = run(harness, ORIGINAL, [])
        shim = run(harness, SHIM, [])
    finally:
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
    if shim['stdout'] != original['stdout']:
        print(f'absent admin_user: original {original!r}\nabsent admin_user: shim {shim!r}', flush=True)
    check(shim['exit'] == original['exit'] == 0 and shim['stdout'] == original['stdout'] and shim['stderr'] == original['stderr'],
          'absent admin_user: shim matches the original')

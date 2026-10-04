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
    harness.sql(f"DELETE FROM graph_tree_items WHERE graph_tree_id IN (SELECT id FROM graph_tree WHERE name LIKE '{prefix}-%'); "
                f"DELETE FROM graph_tree WHERE name LIKE '{prefix}-%';")
    try:
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

        invalid_tree_id = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', '--tree-id=not-a-number',
                                                            '--parent-node=0', '--name=invalid-tree-id'])
        check(invalid_tree_id['exit'] == 1 and 'existing --tree-id' in invalid_tree_id['stderr'],
              'tree CLI rejects a malformed target tree id')

        invalid_parent_id = run(harness, 'cli/add_tree.php', ['--type=node', '--node-type=header', f'--tree-id={tree_one}',
                                                              '--parent-node=-1', '--name=invalid-parent-id'])
        check(invalid_parent_id['exit'] == 1 and 'non-negative integer' in invalid_parent_id['stderr'],
              'tree CLI rejects a negative parent id')

        host_id = int(harness.sql('SELECT id FROM host ORDER BY id LIMIT 1').strip())
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
$rejected = [
    api_tree_item_save(0, 999999999, 1, 0, 'missing-tree', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, 999999999, 'missing-parent', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, $foreignParent, 'foreign-parent', 0, 0, 0, 1, 2, false) === false,
    api_tree_item_save(0, $tree, 1, $graphParent, 'graph-parent', 0, 0, 0, 1, 2, false) === false,
];
echo json_encode($rejected);
'''
        api_result = harness.command('php', '-r', api_probe)
        check(api_result['exit'] == 0 and api_result['stdout'].strip() == '[true,true,true,true]',
              'tree API rejects missing trees and non-header parents')
    finally:
        harness.sql(f"DELETE FROM graph_tree_items WHERE graph_tree_id IN (SELECT id FROM graph_tree WHERE name LIKE '{prefix}-%'); "
                    f"DELETE FROM graph_tree WHERE name LIKE '{prefix}-%';")


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

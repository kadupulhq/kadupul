# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run each frozen original and its shim against the same database and compare."""
from pathlib import Path
import re

ROOT = '/var/www/html'
QUIET = 'KADUPUL_CLI_QUIET_DEPRECATION=1'
ORIGINAL = 'tests/Fixtures/legacy-cli/analyze_database.php'
SHIM = 'cli/analyze_database.php'
COMPLETE = 'ANALYSIS STATS: Analyzing Kadupul Tables Complete'

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
    command = f"cd {ROOT} && {env} php {script} " + ' '.join(arguments)
    return harness.command('sh', '-c', command)


def normalise(text):
    return re.sub(r'Copyright \(C\) 2004-\d{4}', 'Copyright (C) 2004-YEAR', text)


def install_original(harness):
    # The image leaves tests/ out of its build context, so the frozen copy is
    # placed at the path its relative require expects.
    source = Path(__file__).resolve().parents[2] / ORIGINAL
    harness.command('mkdir', '-p', f'{ROOT}/{Path(ORIGINAL).parent}', check=True)
    harness.compose('cp', str(source), f'web:{ROOT}/{ORIGINAL}')


def completions(harness):
    log = harness.command('cat', f'{ROOT}/log/cacti.log', check=True)['stdout']
    return log.count(COMPLETE)


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


def verify_cases(harness, check):
    before = completions(harness)
    for label, arguments, allowed in CASES:
        original = run(harness, ORIGINAL, arguments)
        shim = run(harness, SHIM, arguments)
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
    check(completions(harness) - before == 6, 'both the original and the shim log the completion line')


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

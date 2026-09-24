# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Drive script_server.php through its stdin protocol and reject it over HTTP."""
import base64
import shlex

SERVER = '/var/www/html/script_server.php'
SCRIPTS = '/var/www/html/scripts/'
STARTED = 'PHP Script Server has Started - Parent is '
SHUTDOWN = 'PHP Script Server Shutdown request received, exiting'
THEME = '/var/www/html/include/themes/midwinter'


def serve(harness, arguments, lines):
    """Pipe protocol lines into one server process, as cmd.php does."""
    feed = base64.b64encode(''.join(line + '\n' for line in lines).encode()).decode()
    command = f"printf %s {feed} | base64 -d | php {SERVER} {' '.join(map(shlex.quote, arguments))}"
    return harness.command('sh', '-c', command)


def cacti_log(harness):
    return harness.command('cat', '/var/www/html/log/cacti.log', check=True)['stdout']


def theme_hashes(harness):
    return harness.command('sh', '-c', f"find {THEME} -name '*.css' -type f -exec sha256sum {{}} + | sort", check=True)['stdout']


def verify_script_server(harness, check):
    device = harness.sql("SELECT id FROM host ORDER BY id LIMIT 1").strip()
    saved = harness.sql(f'SELECT failed_polls FROM host WHERE id={device}').strip()
    saved_settings = harness.sql("SELECT name,value FROM settings WHERE name IN ('stats_thold','log_verbosity','poller_interval')")
    try:
        harness.sql(f'UPDATE host SET failed_polls=7 WHERE id={device}')
        # Medium verbosity records the signal handler's warning without the
        # per-line debug trace.
        harness.sql("REPLACE INTO settings (name,value) VALUES ('stats_thold','Time:2.5 Tholds:4'),('log_verbosity','3')")
        verify_arguments(harness, check)
        verify_protocol(harness, device, check)
        verify_shutdown(harness, check)
        verify_runtime_limit(harness, check)
    finally:
        harness.sql(f'UPDATE host SET failed_polls={saved} WHERE id={device}')
        harness.sql("DELETE FROM settings WHERE name IN ('stats_thold','log_verbosity','poller_interval')")
        for row in saved_settings.splitlines():
            name, value = row.split('\t', 1)
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('{name}',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
    verify_http_guard(harness, check)


def verify_arguments(harness, check):
    version = harness.sql('SELECT cacti FROM version').strip()
    result = serve(harness, ['--version'], [])
    check(result['exit'] == 0 and result['stdout'].startswith(f'Kadupul Script Server, Version {version} ')
          and STARTED not in result['stdout'], 'script server --version prints the version without serving')
    result = serve(harness, ['--help'], [])
    check(result['exit'] == 0 and 'usage: script_server.php [environ poller_id]' in result['stdout']
          and STARTED not in result['stdout'], 'script server --help prints usage without serving')
    # cmd.php and spine pass the legacy positional form: environ, then poller id.
    for arguments, parent in ([], 'cmd'), (['spine', '1'], 'spine'), (['realtime', '1'], 'realtime'), \
            (['cmd.php'], 'cmd'), (['--bogus'], 'other'), (['--poller=1', '--mode=offline'], 'cmd'):
        result = serve(harness, arguments, ['quit'])
        check(result['exit'] == 0 and result['stdout'] == STARTED + parent + '\n' + SHUTDOWN + '\n',
              f'script server started with {arguments} reports parent {parent} and quits')


def verify_protocol(harness, device, check):
    before = len(cacti_log(harness))
    hstats = SCRIPTS + 'ss_hstats.php'
    requests = [
        (f'{hstats} ss_hstats {device} failed_polls', '7'),
        (f"{hstats} ss_hstats '{device}' \"failed_polls\"", '7'),
        (f'{SCRIPTS}ss_poller.php ss_thold_time', '2.5'),
        ('', None),
        ('/etc/passwd ss_hstats 1 failed_polls', 'U'),
        (f'{SCRIPTS}../../../../etc/passwd ss_hstats 1 failed_polls', 'U'),
        (f'{SCRIPTS}missing.php ss_missing', 'U'),
        (f'{hstats} system id', 'U'),
        (f'{hstats} ss_no_such_function 1', 'U'),
        # errors.php is the harness prepend, loaded from outside base_path.
        (f'{hstats} behavior_install_error_handler', 'U'),
        (f"{hstats} ss_hstats 'unterminated", f"ERROR: Parse error attempting to parse string ''unterminated'\nU"),
        (f"{hstats} ss_hstats '1' `id`", "ERROR: Backtic (`) characters not allowed ''1' `id`'\nU"),
        # Escaped quotes and backslashes reach the script as literal text, so
        # ss_hstats sees an unknown statistic rather than a parse error.
        (f"{hstats} ss_hstats \\' '{device}'", '0'),
        (f"{hstats} ss_hstats '\\{device}' \\\\ \\\"", '0'),
        ('garbage', 'U'),
    ]
    result = serve(harness, [], [line for line, _ in requests] + ['quit'])
    expected = STARTED + 'cmd\n' + ''.join(reply + '\n' for _, reply in requests if reply is not None)
    check(result['exit'] == 0, 'script server session exits cleanly after quit')
    if result['stdout'] != expected:
        print(result['stdout'], flush=True)
    check(result['stdout'] == expected, 'script server answers valid calls and refuses invalid ones with U')
    check('uid=' not in result['stdout'], 'script server never dispatches PHP internals')
    log = cacti_log(harness)[before:]
    for message in ("Script file '/etc/passwd' resolves outside base path. Rejected.",
                    f"Script file '{SCRIPTS}missing.php' could not be resolved. Rejected.",
                    "Refusing to dispatch PHP internal function 'system' from script server.",
                    "Function does not exist  INC: 'ss_hstats.php' FUNC: 'ss_no_such_function'",
                    "Function 'behavior_install_error_handler' defined outside base path"):
        check(message in log, 'script server logs refusal: ' + message)
    check(log.count('resolves outside base path. Rejected.') == 2, 'script server refuses includes outside the base path')


def verify_shutdown(harness, check):
    # After a dispatch the server stays quiet on quit so it never adds a line
    # the caller would read as a script result.
    result = serve(harness, [], [SCRIPTS + 'ss_poller.php ss_thold_time', 'quit now'])
    check(result['exit'] == 0 and result['stdout'] == STARTED + 'cmd\n2.5\n', 'script server quits silently after a dispatch')

    # A signal ends the process through its handler, not the default action,
    # so the exit status is 0 rather than 128 + SIGTERM.
    before = len(cacti_log(harness))
    signalled = harness.command('sh', '-c', (
        'rm -f /tmp/ss-signal; mkfifo /tmp/ss-signal; '
        f'php {SERVER} < /tmp/ss-signal > /tmp/ss-signal.out & pid=$!; '
        'exec 3> /tmp/ss-signal; '
        'for i in $(seq 1 100); do grep -q "has Started" /tmp/ss-signal.out && break; sleep 0.1; done; '
        # The handler runs only once the blocked read returns, so close stdin.
        'kill -TERM $pid; exec 3>&-; wait $pid; status=$?; cat /tmp/ss-signal.out; exit $status'))
    check(signalled['exit'] == 0 and signalled['stdout'] == STARTED + 'cmd\n', 'script server handles SIGTERM and exits cleanly')
    check("Script Server terminated with signal '15'" in cacti_log(harness)[before:], 'script server logs the terminating signal')

    # Once its stdin closes and the parent is gone, the server must not spin.
    orphan = harness.command('sh', '-c', (
        'rm -f /tmp/ss-orphan.out; '
        f'php {SERVER} < /dev/null > /tmp/ss-orphan.out 2>&1 & echo $! > /tmp/ss-orphan.pid; sleep 1'))
    check(orphan['exit'] == 0, 'orphaned script server fixture starts')
    wait = harness.command('sh', '-c', (
        'pid=$(cat /tmp/ss-orphan.pid); '
        'for i in $(seq 1 100); do '
        '  if ! [ -d /proc/$pid ] || grep -q "^State:.*Z" /proc/$pid/status 2>/dev/null; then cat /tmp/ss-orphan.out; exit 0; fi; '
        '  sleep 0.1; '
        'done; kill -KILL $pid; exit 1'))
    check(wait['exit'] == 0 and wait['stdout'] == STARTED + 'cmd\n' + SHUTDOWN + '\n', 'script server exits when its parent is lost')


def verify_runtime_limit(harness, check):
    harness.sql("REPLACE INTO settings (name,value) VALUES ('poller_interval','1')")
    result = harness.command('sh', '-c', f'(sleep 2; echo {SCRIPTS}ss_poller.php ss_thold_time) | php {SERVER}')
    check(result['exit'] == 255 and result['stdout'].startswith(STARTED + 'cmd\n2.5\n')
          and 'Maximum runtime of 1 seconds exceeded for the Script Server. Exiting.' in result['stdout'],
          'script server exits once it outlives the polling interval')


def verify_http_guard(harness, check):
    # Output buffering holds the shebang, which PHP prints outside the CLI, so
    # the status still reaches the client. Nothing after the guard may run.
    status = harness.command('curl', '-s', '-w', '%{http_code}', 'http://127.0.0.1/script_server.php')
    if status['stdout'] != '#!/usr/bin/env php\n404':
        print(repr(status['stdout']), flush=True)
    check(status['stdout'] == '#!/usr/bin/env php\n404', 'script server answers 404 over HTTP')
    # A stale import hash is what update_hash.php would rewrite, so an
    # unguarded request would change the file.
    stale = harness.command('sh', '-c', f"sed -i \"s#fonts.css?[0-9a-f]*'#fonts.css?stale'#\" {THEME}/main.css && grep -q 'fonts.css?stale' {THEME}/main.css")
    check(stale['exit'] == 0, 'theme fixture carries a stale import hash')
    before = theme_hashes(harness)
    status = harness.command('curl', '-s', '-w', '%{http_code}', 'http://127.0.0.1/include/themes/midwinter/update_hash.php')
    check(status['stdout'] == '404', 'theme hash builder answers 404 over HTTP')
    check(theme_hashes(harness) == before, 'theme hash builder leaves CSS unchanged over HTTP')
    rebuilt = harness.command('php', THEME + '/update_hash.php')
    check(rebuilt['exit'] == 0 and 'fonts.css?stale' not in harness.command('cat', THEME + '/main.css')['stdout'],
          'theme hash builder still rewrites stale CSS from the CLI')

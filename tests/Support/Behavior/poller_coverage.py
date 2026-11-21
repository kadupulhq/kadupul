#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Measure real poller execution in the disposable behavior harness."""
import argparse
import json
from pathlib import Path
import re
import time
from types import SimpleNamespace

from harness import Harness, ROOT, run

EXPECTED = {
    'database/fresh-schema', 'upgrade/install', 'poller/run-reachable',
    'graphs/definition', 'poller/rrd-failure', 'poller/device-unreachable',
    'faults/missing-rrd-file', 'faults/database-unreachable', 'poller/subminute-loop',
}

# Supported intervals that loop with the least wall clock: a one-minute cron
# and a 30-second poller give two runs and a single sleep between them.
SUBMINUTE = {'cron_interval': '60', 'poller_interval': '30'}
SUBMINUTE_RUNS = 2
SUBMINUTE_SETTINGS = ('alerting_enable', 'cron_interval', 'dsstats_enable', 'path_php_binary', 'poller_interval')
# The poller runs every PHP child through path_php_binary. This recorder runs
# each one unchanged and appends one line per alert evaluator it starts.
LAUNCHER = '/tmp/behavior-php-launcher'
LAUNCHES = '/artifacts/alerting-launches.log'
LAUNCHER_SOURCE = """#!/bin/sh
for argument in "$@"; do
    if [ "$argument" = kadupul:alerting:evaluate ]; then
        output=$(/usr/local/bin/php "$@" 2>&1)
        status=$?
        printf '%s %s\\n' "$status" "$(printf '%s' "$output" | tr '\\n' ' ')" >> """ + LAUNCHES + """
        exit "$status"
    fi
done
exec /usr/local/bin/php "$@"
"""
# Alerting on and dsstats off: each evaluator completes with the inactive outcome.
# Exit status 2, then the command's whole output; delivery still drains an empty queue.
INACTIVE = ('2 inactive: rate source unavailable (dsstats_enable must be on). '
            'Notifications: 0 sent, 0 retrying, 0 failed, 0 suppressed, 0 lost.')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def evaluator_launches(h, poll):
    """Run one poll and return its result with the evaluators it started."""
    h.truncate_artifacts('alerting-launches.log')
    result = poll()
    # The observation boundary waits for the poller's whole process group,
    # which includes each background evaluator, so the record is final.
    launches = h.command('sh', '-c', f'test -f {LAUNCHES} && cat {LAUNCHES} || true', check=True)['stdout']
    return result, [line.strip() for line in launches.splitlines() if line.strip()]


def subminute_poller_scenario(h):
    """A poller configured to run more than once per cron interval.

    Between runs the poller calls each core bottom task itself, including the
    native alerting launcher. A single-run poll starts the evaluator once, from
    its end-of-run tasks; the looping poll must start it once more for each
    sleep between runs. Every evaluator must reach the inactive outcome.
    """
    names = ', '.join("'" + name + "'" for name in SUBMINUTE_SETTINGS)
    original = h.rows("SELECT JSON_OBJECT('name',name,'value',value) FROM settings WHERE name IN (" + names + ") ORDER BY name")
    try:
        install = h.compose('exec', '-T', '-u', 'www-data', 'web', 'sh', '-c', f'cat > {LAUNCHER} && chmod 0755 {LAUNCHER}',
                            data=LAUNCHER_SOURCE, check=True)
        require(install['exit'] == 0, 'Cannot install the PHP launcher recorder')
        h.sql("REPLACE INTO settings(name,value) VALUES ('alerting_enable','on'),('dsstats_enable',''),"
              "('path_php_binary','" + LAUNCHER + "');")
        control, control_launches = evaluator_launches(h, lambda: h.php('poller.php', '--force'))
        require(control['exit'] == 0, f"Single-run control poll exited {control['exit']}: {control['stdout'][-500:]}")
        require(control_launches == [INACTIVE], f'Single-run poll started evaluators {control_launches}, expected one inactive run')

        h.sql("REPLACE INTO settings(name,value) VALUES " + ','.join(
            "('" + name + "','" + value + "')" for name, value in SUBMINUTE.items()) + ';')
        started = time.monotonic()
        # The poller's own runtime limit is 58 seconds here. A poll that does
        # not finish within this window is terminated and fails the boundary.
        looping, launches = evaluator_launches(h, lambda: h.php('poller.php', '--force', '--debug', observation_timeout=90))
        elapsed = time.monotonic() - started
        output = looping['stdout']
        require(looping['exit'] == 0, f"Sub-minute poll exited {looping['exit']}: {output[-500:]} {looping['stderr'][-500:]}")
        runs = re.findall(r"Poller Runs: '(\d+)'", output)
        require(runs == [str(SUBMINUTE_RUNS)], f'Poller derived runs {runs} from the sub-minute intervals, expected {SUBMINUTE_RUNS}')
        sleeps = len(re.findall(r'^Sleep Time is: ', output, re.MULTILINE))
        require(sleeps == SUBMINUTE_RUNS - 1, f'Poller slept {sleeps} times between runs, expected {SUBMINUTE_RUNS - 1}')
        require('Polling Cycle Exceeded Poller Interval' not in output,
                'A run overran the poller interval, so the between-run tasks were skipped')
        # The second run is due one poller interval after the poller started.
        require(elapsed >= int(SUBMINUTE['poller_interval']), f'Poller finished after {elapsed:.1f}s, before its second run was due')
        require(launches == [INACTIVE] * SUBMINUTE_RUNS,
                f'Sub-minute poll started evaluators {launches}, expected {SUBMINUTE_RUNS} inactive runs')
        h.capture('poller/subminute-loop', {
            'exit': looping['exit'],
            'poller_runs': int(runs[0]),
            'sleeps_between_runs': sleeps,
            'evaluators': {'single_run': control_launches, 'subminute': launches},
        })
    finally:
        h.sql('DELETE FROM settings WHERE name IN (' + names + ');'
              # Hex literals restore each stored value byte for byte.
              + ''.join("INSERT INTO settings(name,value) VALUES ('" + row['name'] + "',X'" + row['value'].encode().hex() + "');"
                        for row in original))
        h.command('rm', '-f', LAUNCHER, LAUNCHES, check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    # Never mix coverage from separate builds or reuse a successful manifest.
    output.mkdir(parents=True, exist_ok=False)
    raw = output / 'raw'
    raw.mkdir(mode=0o777)
    raw.chmod(0o777)  # Container www-data writes to this dedicated directory.
    ini = output / 'coverage.ini'
    ini.write_text('pcov.directory=/var/www/html\n'
                   'pcov.exclude="~^/var/www/html/(include/vendor|tests|var/cache)/~"\n'
                   'auto_prepend_file=/harness/coverage.php\n')
    override = output / 'compose.json'
    override.write_text(json.dumps({'services': {'web': {
        'build': {'args': {'POLLER_COVERAGE': '1'}},
        'volumes': [f'{raw}:/coverage',
                    f'{ini}:/usr/local/etc/php/conf.d/zz-coverage.ini:ro'],
    }}}))
    h = Harness(SimpleNamespace(target='poller-coverage', only=None,
                                update_golden=False, project='kadupul-poller-coverage'))
    h.dc += ['-f', str(override)]
    command = h.command

    def instrumented(*args, **kwargs):
        return command(*(arg.replace('auto_prepend_file=/harness/errors.php',
                                     'auto_prepend_file=/harness/coverage.php')
                         if isinstance(arg, str) else arg for arg in args), **kwargs)

    h.command = instrumented
    try:
        h.setup()
        h.poller_scenarios()
        subminute_poller_scenario(h)
        h.fault_scenarios()
        if set(h.observed) != EXPECTED:
            raise RuntimeError('Incomplete poller scenario inventory')
        if not list(raw.glob('coverage-*.json')):
            raise RuntimeError('No integration coverage recorded')
        # Booting Symfony during a poller run must never include its generated
        # container in source coverage: those files vary between environments.
        for report in raw.glob('coverage-*.json'):
            files = json.loads(report.read_text())['files']
            if any(path.startswith('/var/www/html/var/cache/') for path in files):
                raise RuntimeError('Generated Symfony cache leaked into source coverage')
        evidence = {'scope': 'poller and dependency-failure integration coverage',
                    'revision': run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'])['stdout'].strip(),
                    'scenarios': h.observed}
    finally:
        try:
            h.compose('down', '--volumes', '--remove-orphans', check=True, timeout=120)
        finally:
            h.lock.close()
    # Only publish success after scenario assertions and cleanup both pass.
    (output / 'observations.json').write_text(json.dumps(evidence, indent=2) + '\n')
    print('Poller integration coverage captured successfully', flush=True)


if __name__ == '__main__':
    main()

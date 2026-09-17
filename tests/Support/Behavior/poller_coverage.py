#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Measure real poller execution in the disposable behavior harness."""
import argparse
import json
from pathlib import Path
from types import SimpleNamespace

from harness import Harness, ROOT, run

EXPECTED = {
    'database/fresh-schema', 'upgrade/install', 'poller/run-reachable',
    'graphs/definition', 'poller/rrd-failure', 'poller/device-unreachable',
    'faults/missing-rrd-file', 'faults/database-unreachable',
}


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
                   'pcov.exclude="~/(include/vendor|tests)/~"\n'
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
        h.fault_scenarios()
        if set(h.observed) != EXPECTED:
            raise RuntimeError('Incomplete poller scenario inventory')
        if not list(raw.glob('coverage-*.json')):
            raise RuntimeError('No integration coverage recorded')
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

# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Checks that the legacy page ratchet only lets the root page count fall.

Each case commits a base baseline in a scratch repository, changes the working
baseline, and runs the script the way CI does.
"""
import subprocess
import sys
import tempfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / 'check_legacy_page_ratchet.py'
PATH = 'tests/security/baselines/entry_points.baseline.tsv'
HEADER = 'entry\tgate\tdetail\n'


def rows(*entries):
    return HEADER + ''.join('%s\t%s\tx\n' % entry for entry in entries)


BASE = rows(
    ('a.php', 'realm:5'), ('b.php', 'guest-or-realm:3'), ('c.php', 'symfony:forward'),
    ('poller.php', 'cli-only'), ('.htaccess', 'web-server-denied'),
)


def git(root, *args):
    subprocess.run(['git', '-C', str(root), *args], check=True, capture_output=True)


def run(base, head, base_ref='HEAD', second_commit=False):
    with tempfile.TemporaryDirectory(prefix='legacy-ratchet-') as directory:
        root = Path(directory)
        (root / PATH).parent.mkdir(parents=True)
        git(root, 'init', '-q')
        git(root, 'config', 'user.email', 'test@example.invalid')
        git(root, 'config', 'user.name', 'test')
        git(root, 'config', 'commit.gpgsign', 'false')
        if base is not None:
            (root / PATH).write_text(base)
            git(root, 'add', '-A')
            git(root, 'commit', '-q', '-m', 'base')
        else:
            (root / 'x').write_text('x')
            git(root, 'add', '-A')
            git(root, 'commit', '-q', '-m', 'base')
        (root / PATH).write_text(head)
        if second_commit:
            # CI compares against HEAD^1, so the head baseline is committed.
            git(root, 'add', '-A')
            git(root, 'commit', '-q', '--allow-empty', '-m', 'head')
        result = subprocess.run(
            [sys.executable, str(SCRIPT), '--root', str(root), '--base', base_ref],
            capture_output=True, text=True, cwd=root,
        )
        return result.returncode, result.stdout + result.stderr


def main():
    failures = []
    cases = 0

    def expect(label, outcome, status, *needles):
        nonlocal cases
        cases += 1
        code, output = outcome
        if code != status or any(n not in output for n in needles):
            failures.append('%s: exit %d, wanted %d, output %r' % (label, code, status, output))

    fewer = rows(('a.php', 'symfony:forward'), ('b.php', 'guest-or-realm:3'))
    expect('count falls', run(BASE, fewer), 0, 'at HEAD: 1', ': 2')
    expect('count equal', run(BASE, BASE), 0)
    expect('new realm page', run(BASE, BASE + 'd.php\trealm:5\tx\n'), 1, 'rose from 2 to 3', 'd.php')
    expect('shim added', run(BASE, BASE + 'e.php\tsymfony:forward\tx\n'), 0)
    expect('unknown symfony gate counts', run(BASE, BASE + 'h.php\tsymfony:something\tx\n'), 1, 'rose from 2 to 3', 'h.php')
    expect('front controller gate on another file counts', run(BASE, BASE + 'i.php\tsymfony:front-controller\tx\n'), 1, 'rose from 2 to 3', 'i.php')
    expect('app.php front controller is not legacy', run(BASE, BASE + 'app.php\tsymfony:front-controller\tx\n'), 0)
    expect('non-root ignored', run(BASE, BASE + 'lib/f.php\trealm:5\tx\ninclude/g.php\tanonymous-allowed\tx\n'), 0)
    expect('push: count equal against HEAD^1', run(BASE, BASE, 'HEAD^1', True), 0, ': 2')
    expect('push: new realm page against HEAD^1',
           run(BASE, BASE + 'd.php\trealm:5\tx\n', 'HEAD^1', True), 1, 'rose from 2 to 3', 'd.php')
    expect('missing base baseline', run(None, BASE), 2, 'cannot read')
    expect('malformed head', run(BASE, 'entry\tgate\tdetail\na.php\n'), 2, 'malformed')

    for failure in failures:
        print('FAIL: ' + failure)
    if failures:
        return 1
    print('PASS: legacy page ratchet cases (%d)' % cases)
    return 0


if __name__ == '__main__':
    sys.exit(main())

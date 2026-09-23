# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Print every HTTP entry point with the gate that protects it, as TSV.

This script reads what the web server decides: the Nginx deny locations in
the reference vhost and the Apache .htaccess denies. Every file Nginx serves
goes to classify_entry_points.php, which reads the gate from the PHP AST (the
include/auth.php realm map, the CLI guards, the self-gated pages and the
Symfony routes) and reports unknown for anything it cannot prove.

Set PHP to choose the interpreter; the classifier needs composer install.
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CLASSIFIER = Path(__file__).resolve().parent / 'classify_entry_points.php'


def git_files(pattern, root=None):
    out = subprocess.run(['git', '-C', str(root or ROOT), 'ls-files', pattern],
                         capture_output=True, text=True, check=True).stdout
    return sorted(out.splitlines())


def nginx_denies():
    """Deny rules and the literal paths they name, from the reference vhost."""
    text = (ROOT / 'tests/e2e/nginx.conf').read_text()
    rules, named = [], set()
    for flag, pattern in re.findall(r'location\s+(~\*?)\s+(\S+)\s*\{\s*return\s+404;', text):
        rules.append(re.compile(pattern, re.I if flag == '~*' else 0))
        m = re.match(r'\^/((?:[\w-]+/)*)\(([^()]*)\)(.*)$', pattern)
        if not m:
            continue
        directory = m[3].startswith(('(/|$)', '/'))
        for alternative in m[2].split('|'):
            path = m[1] + alternative.replace('\\.', '.')
            if re.search(r'[\\\[\]()?*+^$]', path):
                continue
            if directory:
                named.add(path + '/')
            elif path.endswith('.php'):
                named.add(path)
    if not rules:
        raise SystemExit('ERROR: no deny locations parsed from tests/e2e/nginx.conf')
    return rules, named


def apache_denied(path, htaccess):
    """True when a tracked .htaccess in the path or an ancestor denies all."""
    parent = Path(path.rstrip('/')) if path.endswith('/') else Path(path).parent
    while str(parent) not in ('', '.'):
        if str(parent / '.htaccess') in htaccess:
            return True
        parent = parent.parent
    return False


def plugin_realms(root):
    """api_plugin_load_realms() maps plugin_realms rows to id + 100."""
    sql = (root / 'cacti.sql').read_text() if (root / 'cacti.sql').is_file() else ''
    realms = {}
    for realm_id, files in re.findall(r"INSERT INTO `plugin_realms` VALUES \((\d+), '[^']*', '([^']*)'", sql.replace('REPLACE INTO', 'INSERT INTO')):
        for name in files.split(','):
            realms[name] = int(realm_id) + 100
    return realms


def classify(root, files, served):
    """Rows for the served files and the Symfony routes, from the PHP classifier."""
    request = {'root': str(root), 'files': files, 'served': served, 'plugin_realms': plugin_realms(root)}
    proc = subprocess.run([os.environ.get('PHP', 'php'), str(CLASSIFIER)], input=json.dumps(request),
                          capture_output=True, text=True)
    if proc.returncode != 0:
        raise SystemExit(proc.stderr.strip() or 'ERROR: classify_entry_points.php exited %d' % proc.returncode)
    return [tuple(row) for row in json.loads(proc.stdout)['rows']]


def main():
    denies, named = nginx_denies()
    files = git_files('*.php')

    served, denied = [], []
    for path in files:
        if any(rule.search('/' + path) for rule in denies):
            denied.append(path)
        else:
            served.append(path)

    rows = classify(ROOT, files, served)

    # Denied paths are listed as Nginx names them, whether or not the tree
    # tracks PHP there: include/vendor/ and cache/ fill up at install time.
    htaccess = {f for f in git_files('*.htaccess')
                if re.search(r'Require\s+all\s+denied', (ROOT / f).read_text())}
    entries = set(named)
    for path in denied:
        owner = [n for n in named if path == n or (n.endswith('/') and path.startswith(n))]
        entries.add(max(owner, key=len) if owner else path)
    for entry in entries:
        apache = 'apache .htaccess' if apache_denied(entry, htaccess) else 'no apache .htaccess deny'
        rows.append((entry, 'web-server-denied', 'nginx; ' + apache))

    print('entry\tgate\tdetail')
    for row in sorted(rows):
        print('\t'.join(row))
    return 0


if __name__ == '__main__':
    sys.exit(main())

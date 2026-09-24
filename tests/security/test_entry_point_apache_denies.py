# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Show that the inventory's Apache verdicts come from the .htaccess rules.

Each Apache-denied row must turn back into a gap when the .htaccess files on
its path are dropped, and the browser assets beside the denied PHP must stay
served. Opt-in rows must be denied only once .htaccess.dist is enabled. Without
this, a generator that always answered "denied" would pass.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_entry_point_inventory as inventory  # noqa: E402

ROOT = inventory.ROOT
SERVED = ['include/vendor/csrf/csrf-magic.js', 'include/vendor/flag-icons/css/flag-icons.css',
          'include/layout.js', 'include/themes/modern/main.css', 'index.php', 'include/auth.php']
# Denied by default beyond the baseline rows: static docs, a name in another
# case, a bootstrap-name prefix and a nested vendor path.
DENIED = ['docs/Table-of-Contents.html', 'docs/html/install_unix.html', 'include/Config.php',
          'include/config.php.dist', 'include/VENDOR/autoload.php']
# Served even with .htaccess.dist enabled.
OPT_IN_SERVED = ['.well-known/acme-challenge/token', '.well-known/', 'index.php', 'include/js/jquery.js']
# Denied only with .htaccess.dist enabled; these have no baseline row. The
# nested .well-known checks that the ACME exception is anchored at the root.
OPT_IN_DENIED = ['composer.json', 'Composer.Lock', '.git/config', '.env', 'plugins/x/.well-known/token']


def main():
    rules = {f: inventory.htaccess_rules((ROOT / f).read_text()) for f in inventory.git_files('*.htaccess')}
    baseline = (ROOT / 'tests/security/baselines/entry_points.baseline.tsv').read_text().splitlines()[1:]
    denied = [row.split('\t')[0] for row in baseline if row.endswith('\tnginx; apache .htaccess')]
    failures = []
    if not denied:
        failures.append('no Apache-denied rows in the baseline')
    for entry in denied:
        if not inventory.apache_denied(entry, rules):
            failures.append(entry + ': baseline says denied, rules say served')
            continue
        probe = entry + 'index.php' if entry.endswith('/') else entry
        parts = probe.split('/')
        own = {('/'.join(parts[:i]) + '/' if i else '') + '.htaccess' for i in range(len(parts))}
        remaining = {f: r for f, r in rules.items() if f not in own}
        if inventory.apache_denied(entry, remaining):
            failures.append(entry + ': still denied with its .htaccess files removed')
    for path in SERVED:
        if inventory.apache_denied(path, rules):
            failures.append(path + ': served path is denied')
    for path in DENIED:
        if not inventory.apache_denied(path, rules):
            failures.append(path + ': denied path is served')

    enabled = {**rules, '.htaccess': inventory.htaccess_rules((ROOT / '.htaccess.dist').read_text())}
    opt_in = [row.split('\t')[0] for row in baseline if row.endswith('\tnginx; apache: opt-in via .htaccess.dist')]
    for path in opt_in + OPT_IN_DENIED:
        if inventory.apache_denied(path, rules):
            failures.append(path + ': opt-in path is denied by default')
        if not inventory.apache_denied(path, enabled):
            failures.append(path + ': opt-in path is served with .htaccess.dist enabled')
    for path in OPT_IN_SERVED:
        if inventory.apache_denied(path, enabled):
            failures.append(path + ': served path is denied with .htaccess.dist enabled')
    # Section forms the parser must read, or refuse, rather than take a
    # per-file Require as a directory-wide one.
    deny = '\n    Require all denied\n'
    if inventory.htaccess_rules('<Files x.php>' + deny + '</Files>\n')[0] is not None:
        failures.append('unquoted <Files> read as a directory-wide verdict')
    if not inventory.apache_denied('d/x.php', {'d/.htaccess': inventory.htaccess_rules('<FilesMatch ^x>' + deny + '</FilesMatch>\n')}):
        failures.append('unquoted <FilesMatch> deny not applied')
    try:
        inventory.htaccess_rules('<RequireAll>' + deny + '</RequireAll>\n')
        failures.append('unsupported <RequireAll> section accepted')
    except SystemExit:
        pass
    for failure in failures:
        print('FAIL ' + failure)
    if failures:
        return 1
    print('PASS: %d Apache-denied rows depend on their .htaccess; %d served paths stay served; %d opt-in rows need .htaccess.dist'
          % (len(denied), len(SERVED), len(opt_in) + len(OPT_IN_DENIED)))
    return 0


if __name__ == '__main__':
    sys.exit(main())

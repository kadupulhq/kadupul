# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Plugin console menu entries through the real worker, Apache, sessions and MariaDB."""
import html
import re

from permission_denied_scenarios import page

PAGE = '/app.php/utilities/system'


def menu_items(body):
    """(href, label) pairs from the Twig console navigation only."""
    navigation = re.search(r'<nav id="navigation".*?</nav>', body, re.S)
    if navigation is None:
        return []
    return [(href, html.unescape(label)) for href, label in
            re.findall(r'<a class="pic[^"]*" href="([^"]*)"[^>]*>([^<]*)</a>', navigation.group(0))]


def verify_plugin_menu(harness, check):
    from harness import Session
    session = Session(harness.base)
    login = session.login('behavior-admin')
    check(login['status'] == 200 and not login['login_form'], 'plugin menu fixture signs in')
    user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
    status, body, _ = page(session, PAGE)
    check(status == 200 and not any('menu_parity' in href for href, _ in menu_items(body)),
          'Twig menu has no plugin entries before the plugin is enabled')
    try:
        for operation in ('--install', '--enable'):
            result = harness.php('cli/plugin_manage.php', '--plugin=menu_parity', operation)
            check(result['exit'] == 0, 'plugin menu fixture lifecycle ' + operation)
        realm = int(harness.sql("SELECT id + 100 FROM plugin_realms WHERE plugin='menu_parity'").strip())
        check(harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='menu_parity' AND hook='config_arrays' AND status=1").strip() == '1',
              'config_arrays registered through the native plugin API')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user}, {realm})')

        status, body, _ = page(session, PAGE)
        items = menu_items(body)
        check(status == 200 and ('/plugins/menu_parity/parity.php', 'Parity <Checks>') in items,
              'real worker reports the config_arrays entry and its plugin realm (plugin_realms.id + 100)')
        check(('/plugins/menu_parity/parity_tools.php?action=list', 'Parity Tools') in items,
              'plugin section and query path render in the Twig menu')
        check('Parity &lt;Checks&gt;' in body and 'Parity <Checks>' not in body, 'plugin menu label is escaped as text')
        check(not any('parity.example.invalid' in href or 'unregistered' in href or 'EXTERNAL' in href for href, _ in items),
              'absolute, external and realm-less plugin menu entries are dropped')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user} AND realm_id={realm}')
        status, body, _ = page(session, PAGE)
        check(status == 200 and not any('menu_parity' in href for href, _ in menu_items(body)),
              'revoking plugin realm id + 100 hides its menu entries')
    finally:
        harness.sql("DELETE FROM user_auth_realm WHERE realm_id IN (SELECT id + 100 FROM plugin_realms WHERE plugin='menu_parity')")
        harness.php('cli/plugin_manage.php', '--plugin=menu_parity', '--uninstall')

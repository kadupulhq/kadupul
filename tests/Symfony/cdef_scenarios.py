# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""CDEF list, editor, items, duplication and deletion through Symfony over HTTP."""
from html.parser import HTMLParser
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request
import json
import re

from device_edit_scenarios import Inputs
from harness import Session

BASE = '/app.php/graph-definitions/cdefs'


class PageLinks(HTMLParser):
    """Links of the page itself, without the console menu."""
    def __init__(self):
        super().__init__()
        self.links = []
        self.in_menu = False

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'nav' and attrs.get('id') == 'navigation':
            self.in_menu = True
        if not self.in_menu and tag == 'a' and 'href' in attrs:
            self.links.append(attrs['href'])

    def handle_endtag(self, tag):
        if tag == 'nav':
            self.in_menu = False


def verify_cdefs(harness, session, user_id, check):
    marker = 'symfony-cdef-' + harness.sql('SELECT COALESCE(MAX(id),0)+100 FROM cdef').strip()
    created = []
    prior_preference = harness.rows(f"SELECT JSON_OBJECT('value',value) FROM settings_user WHERE user_id={user_id} AND name='cdef_filters'")

    def fetch(path, fields=None, origin=None, client=None):
        data = None if fields is None else urlencode(fields).encode()
        request = Request(harness.base + path, data=data, headers={'Origin': origin or harness.base})
        try:
            response = (client or session).opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    def form(path, prefix):
        status, body, _ = fetch(path)
        parser = Inputs()
        parser.feed(body)
        fields = {name: value for name, value in parser.fields.items() if name.startswith(prefix + '[')}
        check(status == 200 and prefix + '[_token]' in fields, 'CDEF form renders a CSRF token: ' + path)
        return fields, body

    def scalar(query):
        return harness.sql(query).strip()

    def items(cdef):
        return scalar(f"SELECT COALESCE(GROUP_CONCAT(CONCAT(type,':',value) ORDER BY sequence, id),'') FROM cdef_items WHERE cdef_id={cdef}")

    def create(name):
        fields, _ = form(BASE + '/new', 'cdef')
        status, body, url = fetch(BASE + '/new', fields | {'cdef[name]': name})
        match = re.search(r'/graph-definitions/cdefs/([1-9][0-9]*)/edit', url)
        check(status == 200 and match is not None and 'saved=1' in url, 'CDEF creation redirects to its editor')
        created.append(int(match.group(1)))
        return int(match.group(1)), body

    def add_item(cdef, item_type, value, expected=200):
        path = f'{BASE}/{cdef}/items/0?type={item_type}'
        fields, _ = form(path, 'cdef_item')
        status, body, _ = fetch(path, fields | {'cdef_item[value]': value})
        check(status == expected, f'CDEF item type {item_type} value {value!r} answers {expected} (got {status})')
        return body

    try:
        anonymous = Session(harness.base)
        for path in (BASE, BASE + '/new', '/cdef.php', '/cdef.php?action=edit&id=1'):
            check(fetch(path, client=anonymous)[0] == 401, 'CDEF anonymous refusal: ' + path)
        check(fetch('/cdef.php', {'action': 'actions'}, client=anonymous)[0] == 401, 'CDEF anonymous legacy post refused')

        status, _, url = fetch('/cdef.php?header=false')
        check(status == 200 and urlsplit(url).path == BASE, 'legacy cdef.php GET forwards to the Symfony list')

        first, body = create(marker + ' <b>A</b>')
        check('&lt;b&gt;A&lt;/b&gt;' in body and '<b>A</b>' not in body, 'CDEF names are escaped in Twig')
        check(scalar(f'SELECT name FROM cdef WHERE id={first}') == marker + ' <b>A</b>'
              and re.fullmatch(r'[0-9a-f]{32}', scalar(f'SELECT hash FROM cdef WHERE id={first}')) is not None,
              'CDEF name is stored as entered with a legacy-shaped hash')
        add_item(first, 4, 'CURRENT_DATA_SOURCE')
        add_item(first, 6, '8')
        add_item(first, 2, '3')
        status, body, _ = fetch(f'{BASE}/{first}/edit')
        check(status == 200 and 'cdef=CURRENT_DATA_SOURCE,8,*' in body, 'CDEF preview resolves the stored items')
        check(items(first) == '4:CURRENT_DATA_SOURCE,6:8,2:3', 'CDEF items keep their type, value and order')
        add_item(first, 1, '999', 422)
        add_item(first, 2, 'CURRENT_DATA_SOURCE', 422)
        check(items(first) == '4:CURRENT_DATA_SOURCE,6:8,2:3', 'invalid CDEF item values are refused without writing')

        fields, _ = form(f'{BASE}/{first}/edit', 'cdef')
        check(fetch(f'{BASE}/{first}/edit', {k: v for k, v in fields.items() if k != 'cdef[_token]'} | {'cdef[name]': 'no token'})[0] == 422,
              'CDEF save without a token is refused')
        check(fetch(f'{BASE}/{first}/edit', fields | {'cdef[name]': 'cross origin'}, origin='https://attacker.invalid')[0] == 422,
              'CDEF cross-origin save is refused')
        harness.sql(f"UPDATE cdef SET name='{marker} concurrent' WHERE id={first}")
        check(fetch(f'{BASE}/{first}/edit', fields | {'cdef[name]': 'stale overwrite'})[0] == 409
              and scalar(f'SELECT name FROM cdef WHERE id={first}') == marker + ' concurrent',
              'stale CDEF form is refused without overwriting the newer name')

        second, _ = create(marker + ' B')
        add_item(second, 5, str(first))
        add_item(first, 5, str(second), 422)
        check(items(first) == '4:CURRENT_DATA_SOURCE,6:8,2:3', 'a CDEF reference that closes a cycle is refused')
        status, body, _ = fetch(f'{BASE}/{second}/edit')
        check('cdef=CURRENT_DATA_SOURCE,8,*' in body, 'nested CDEF preview expands the referenced definition')

        moves, _ = form(f'{BASE}/{first}/edit', 'cdef_item_change_' + scalar(f'SELECT id FROM cdef_items WHERE cdef_id={first} ORDER BY sequence LIMIT 1'))
        item = scalar(f'SELECT id FROM cdef_items WHERE cdef_id={first} ORDER BY sequence LIMIT 1')
        status, _, _ = fetch(f'{BASE}/{first}/items/{item}/move/down', moves)
        check(status == 200 and items(first) == '6:8,4:CURRENT_DATA_SOURCE,2:3', 'CDEF item moves down after a POST with its revision')
        check(fetch(f'{BASE}/{first}/items/{item}/move/down', moves)[0] == 409, 'a second move with the old revision is refused')

        duplicate = f'{BASE}/actions/duplicate?' + urlencode([('ids[]', str(first))])
        fields, _ = form(duplicate, 'cdef_action')
        status, _, url = fetch(duplicate, fields | {'cdef_action[title_format]': '<cdef_title> copy'})
        copy = int(scalar(f"SELECT COALESCE(MAX(id),0) FROM cdef WHERE name='{marker} concurrent copy'"))
        if copy:
            created.append(copy)
        check(status == 200 and 'duplicated=1' in url and copy > 0 and items(copy) == items(first),
              'CDEF duplication copies the definition and its items')

        fields, _ = form(duplicate, 'cdef_action')
        harness.sql("CREATE TRIGGER cdef_scenario_copy_refusal BEFORE INSERT ON cdef_items FOR EACH ROW "
                    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
        try:
            before = scalar('SELECT COUNT(*) FROM cdef')
            status, body, _ = fetch(duplicate, fields | {'cdef_action[title_format]': '<cdef_title> refused'})
            check(status == 502 and 'uncertain' in body and scalar('SELECT COUNT(*) FROM cdef') == before,
                  'a failed CDEF duplication leaves no partial copy')
        finally:
            harness.sql('DROP TRIGGER IF EXISTS cdef_scenario_copy_refusal')

        referenced = f'{BASE}/actions/delete?' + urlencode([('ids[]', str(first))])
        fields, body = form(referenced, 'cdef_action')
        check('cannot be deleted' in body and 'title_format' not in body, 'delete confirmation marks a referenced CDEF and has no title input')
        check(fetch(referenced, fields)[0] == 422 and scalar(f'SELECT COUNT(*) FROM cdef WHERE id={first}') == '1'
              and items(first) == '6:8,4:CURRENT_DATA_SOURCE,2:3',
              'a forged delete of a referenced CDEF is refused and preserves its items')

        unused = f'{BASE}/actions/delete?' + urlencode([('ids[]', str(copy))])
        fields, _ = form(unused, 'cdef_action')
        harness.sql(f"UPDATE cdef SET name='{marker} renamed copy' WHERE id={copy}")
        check(fetch(unused, fields)[0] == 409 and scalar(f'SELECT COUNT(*) FROM cdef WHERE id={copy}') == '1',
              'a stale CDEF delete is refused')
        fields, _ = form(unused, 'cdef_action')
        status, _, url = fetch(unused, fields)
        check(status == 200 and 'deleted=1' in url and scalar(f'SELECT COUNT(*) FROM cdef WHERE id={copy}') == '0'
              and scalar(f'SELECT COUNT(*) FROM cdef_items WHERE cdef_id={copy}') == '0',
              'an unused CDEF and its items are deleted through the reference contract')

        item = scalar(f'SELECT id FROM cdef_items WHERE cdef_id={first} ORDER BY sequence DESC LIMIT 1')
        status, _, url = fetch(f'/cdef.php?action=item_remove_confirm&id={first}&cdef_id={item}')
        check(status == 200 and urlsplit(url).path == f'{BASE}/{first}/items/{item}/delete', 'legacy item removal link opens the confirmation')
        fields, _ = form(f'{BASE}/{first}/items/{item}/delete', 'cdef_item_change')
        check(fetch(f'{BASE}/{first}/items/{item}/delete', fields)[0] == 200 and items(first) == '6:8,4:CURRENT_DATA_SOURCE',
              'CDEF item removal deletes only that item')

        status, body, _ = fetch(BASE + '?' + urlencode({'filter': marker, 'sort_column': 'graphs', 'sort_direction': 'DESC'}))
        parser = PageLinks()
        parser.feed(body)
        page_links = [link for link in parser.links if link.startswith('/')]
        check(status == 200 and page_links and all(urlsplit(link).path.startswith(BASE) for link in page_links),
              'CDEF page links stay on the Symfony routes: ' + repr(page_links))
        sort_links = [parse_qs(urlsplit(link).query) for link in page_links if 'sort_column=name' in link]
        check(any(query.get('filter') == [marker] for query in sort_links), 'CDEF sort links keep the active filter')
        saved = json.loads(scalar(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='cdef_filters'"))
        check(saved['filter'] == marker and saved['sort_column'] == 'graphs', 'CDEF list remembers explicit filters')
        status, body, _ = fetch('/cdef.php?clear=1&header=false')
        check(status == 200 and json.loads(scalar(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='cdef_filters'"))['filter'] == '',
              'legacy clear resets the remembered CDEF filters')

        check(fetch('/cdef.php', {'action': 'item_remove', 'id': str(item), 'cdef_id': str(first)})[0] == 409
              and items(first) == '6:8,4:CURRENT_DATA_SOURCE', 'legacy CDEF posts are refused without writing')

        fields, _ = form(f'{BASE}/{first}/edit', 'cdef')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=14')
        try:
            check(fetch(BASE)[0] == 403 and fetch('/cdef.php')[0] == 403 and fetch('/cdef.php', {'action': 'actions'})[0] == 403
                  and fetch(f'{BASE}/{first}/edit', fields | {'cdef[name]': 'revoked'})[0] == 403
                  and scalar(f'SELECT name FROM cdef WHERE id={first}') == marker + ' concurrent',
                  'CDEF realm revocation refuses reads, legacy posts and writes')
        finally:
            harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 14)')
    finally:
        harness.sql('DROP TRIGGER IF EXISTS cdef_scenario_copy_refusal')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 14)')
        for ident in sorted(created, reverse=True):
            harness.sql(f'DELETE FROM cdef_items WHERE cdef_id={ident}')
        for ident in sorted(created, reverse=True):
            harness.sql(f'DELETE FROM cdef WHERE id={ident}')
        harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='cdef_filters'")
        if prior_preference:
            value = prior_preference[0]['value'].replace("'", "''")
            harness.sql(f"INSERT INTO settings_user (user_id, name, value) VALUES ({user_id}, 'cdef_filters', '{value}')")

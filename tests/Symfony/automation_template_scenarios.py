# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Device automation templates (Device Rules) through Symfony over HTTP."""
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request
import json
import re

from device_edit_scenarios import Inputs
from harness import Session

BASE = '/app.php/automation/templates'
# The match automation_find_os() runs during discovery, limited to this scenario's rows.
CONSUMER = ("SELECT at.id FROM automation_templates AS at INNER JOIN host_template AS ht ON ht.id=at.host_template "
            "WHERE ('{device}' REGEXP CONCAT('/', sysDescr, '/') OR '{device}' LIKE CONCAT('%', sysDescr, '%')) "
            "AND at.id IN ({ids}) ORDER BY sequence LIMIT 1")


def verify_automation_templates(harness, session, user_id, check):
    marker = 'symfony-autotemplate-' + harness.sql('SELECT COALESCE(MAX(id),0)+100 FROM automation_templates').strip()
    created = []
    created_device_templates = []
    prior_order = harness.rows("SELECT JSON_OBJECT('id',id,'sequence',sequence) FROM automation_templates")
    prior_preference = harness.rows(f"SELECT JSON_OBJECT('value',value) FROM settings_user WHERE user_id={user_id} AND name='automation_template_filters'")

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
        check(status == 200 and prefix + '[_token]' in fields, 'automation template form renders a CSRF token: ' + path)
        return fields, body

    def scalar(query):
        return harness.sql(query).strip()

    def order():
        return [int(x) for x in scalar('SELECT GROUP_CONCAT(id ORDER BY sequence, id) FROM automation_templates').split(',') if x]

    def stored(ident):
        return scalar(f"SELECT CONCAT_WS('|', host_template, availability_method, sysDescr, sysName, sysOid) FROM automation_templates WHERE id={ident}")

    def create(device_template, sys_descr, expected=303):
        fields, _ = form(BASE + '/new', 'automation_template')
        before = int(scalar('SELECT COALESCE(MAX(id),0) FROM automation_templates'))
        status, body, url = fetch(BASE + '/new', fields | {
            'automation_template[host_template]': str(device_template),
            'automation_template[availability_method]': '3',
            'automation_template[sysDescr]': sys_descr,
            'automation_template[sysName]': marker,
        })
        ident = int(scalar('SELECT COALESCE(MAX(id),0) FROM automation_templates'))
        if ident > before:
            created.append(ident)
        if expected == 303:
            check(status == 200 and urlsplit(url).path == BASE and 'saved=1' in url and ident > before,
                  'automation template creation returns to the list: ' + sys_descr)
        return ident if ident > before else None, status, body

    try:
        anonymous = Session(harness.base)
        for path in (BASE, BASE + '/new', '/automation_templates.php', '/automation_templates.php?action=edit&id=1'):
            check(fetch(path, client=anonymous)[0] == 401, 'automation template anonymous refusal: ' + path)
        check(fetch('/automation_templates.php', {'action': 'actions'}, client=anonymous)[0] == 401,
              'automation template anonymous legacy post refused')
        status, _, url = fetch('/automation_templates.php?header=false')
        check(status == 200 and urlsplit(url).path == BASE, 'legacy automation_templates.php GET forwards to the Symfony list')

        for name in (marker + ' <b>device</b>', marker + ' second'):
            created_device_templates.append(int(harness.sql(
                f"INSERT INTO host_template (hash, name) VALUES (MD5('{name}'), '{name}'); SELECT LAST_INSERT_ID()").strip()))
        device, other = created_device_templates

        first, _, _ = create(device, '<b>Kadupul</b> & ' + marker)
        check(stored(first) == f'{device}|3|&lt;b&gt;Kadupul&lt;/b&gt; &amp; {marker}|{marker}|'
              and scalar(f'SELECT sequence FROM automation_templates WHERE id={first}') == '0',
              'automation template keeps the legacy description encoding and sequence 0')
        status, body, _ = fetch(BASE + '?' + urlencode({'filter': marker}))
        check(status == 200 and '&lt;b&gt;device&lt;/b&gt;' in body and '<b>device</b>' not in body,
              'device template names are escaped in the list')

        fields, body = form(f'{BASE}/{first}/edit', 'automation_template')
        check(fields['automation_template[host_template]'] == str(device) and fields['automation_template[availability_method]'] == '3',
              'automation template editor selects the stored device template and availability')
        status, _, url = fetch(f'{BASE}/{first}/edit', fields)
        check(status == 200 and 'saved=1' in url and stored(first) == f'{device}|3|&lt;b&gt;Kadupul&lt;/b&gt; &amp; {marker}|{marker}|',
              'an unchanged automation template form saves the same stored text')

        fields, _ = form(f'{BASE}/{first}/edit', 'automation_template')
        before = stored(first)
        for case, change, expected in (
            ('a pattern the database cannot compile', {'automation_template[sysDescr]': '(' + marker}, 'not a regular expression'),
            ('an unknown device template', {'automation_template[host_template]': '99999999'}, None),
            ('an over-long OID match', {'automation_template[sysOid]': '1' * 61}, 'at most 60 characters'),
            ('an availability method that does not exist', {'automation_template[availability_method]': '9'}, None),
            ('a missing token', {'automation_template[_token]': ''}, None),
        ):
            status, body, _ = fetch(f'{BASE}/{first}/edit', fields | change)
            check(status == 422 and (expected is None or expected in body) and stored(first) == before,
                  'automation template refuses ' + case + ' without writing')
        array_fields = {k: v for k, v in fields.items() if k != 'automation_template[sysName]'} | {'automation_template[sysName][]': 'x'}
        check(fetch(f'{BASE}/{first}/edit', array_fields)[0] == 422 and stored(first) == before,
              'automation template refuses an array-shaped match')
        check(fetch(f'{BASE}/{first}/edit', fields, origin='https://attacker.invalid')[0] == 422 and stored(first) == before,
              'automation template refuses a cross-origin save')
        harness.sql(f"UPDATE automation_templates SET sysName='{marker} concurrent' WHERE id={first}")
        check(fetch(f'{BASE}/{first}/edit', fields | {'automation_template[sysName]': 'stale overwrite'})[0] == 409
              and scalar(f'SELECT sysName FROM automation_templates WHERE id={first}') == marker + ' concurrent',
              'a stale automation template form is refused without overwriting the newer value')

        second, _, _ = create(other, 'Kadupul ' + marker)
        third, _, _ = create(device, 'Kadupul ' + marker)
        ours = ','.join(str(x) for x in (first, second, third))
        device_text = 'Kadupul ' + marker + ' x86_64'
        consumer = CONSUMER.format(device=device_text, ids=ours)
        start = order()
        check(start.index(second) < start.index(third), 'new templates share sequence 0 and list in ID order')
        check(scalar(consumer) in (str(second), str(third)), 'discovery matches one of the two applicable templates')
        fields, _ = form(f'{BASE}/{first}/edit', 'automation_template')
        status, _, url = fetch(f'{BASE}/{first}/edit', fields | {'automation_template[sysOid]': r'^1\.3\.6\.1'})
        check(status == 200 and 'saved=1' in url and scalar(f'SELECT HEX(sysOid) FROM automation_templates WHERE id={first}') == r'^1\.3\.6\.1'.encode().hex().upper(),
              'a regular expression the database compiles is saved as entered')

        status, body, _ = fetch(BASE + '?' + urlencode({'filter': marker, 'rows': '-1'}))
        parser = Inputs()
        parser.feed(body)
        move = {k: v for k, v in parser.fields.items() if k.startswith(f'automation_template_order_{third}[')}
        check(status == 200 and f'automation_template_order_{third}[_token]' in move, 'the list renders a move form per template')
        status, _, _ = fetch(f'{BASE}/{third}/move/up', move)
        moved = order()
        expected = list(start)
        position = expected.index(third)
        expected[position - 1], expected[position] = expected[position], expected[position - 1]
        sequences = [int(x) for x in scalar('SELECT GROUP_CONCAT(sequence ORDER BY sequence, id) FROM automation_templates').split(',')]
        check(status == 200 and moved == expected and sequences == list(range(1, len(moved) + 1)),
              'moving a template up swaps it with its neighbour and renumbers the match order densely')
        check(moved.index(third) < moved.index(second) and scalar(consumer) == str(third),
              'discovery follows the new order: the moved template matches first')
        check(fetch(f'{BASE}/{third}/move/up', move)[0] == 409 and order() == moved,
              'a second move with the old order is refused')
        status, body, _ = fetch(BASE + '?' + urlencode({'filter': marker, 'rows': '-1'}))
        parser = Inputs()
        parser.feed(body)
        move = {k: v for k, v in parser.fields.items() if k.startswith(f'automation_template_order_{third}[')}
        status, _, _ = fetch(f'{BASE}/{third}/move/down', move)
        check(status == 200 and order() == start and scalar(consumer) == str(second),
              'moving it back down restores the order and discovery matches the other template first')
        moved = order()

        status, body, _ = fetch(BASE + '?' + urlencode({'filter': '', 'rows': '5000'}))
        parser = Inputs()
        parser.feed(body)
        top = moved[0]
        move = {k: v for k, v in parser.fields.items() if k.startswith(f'automation_template_order_{top}[')}
        check(f'/automation/templates/{top}/move/up' not in body and f'/automation/templates/{moved[-1]}/move/down' not in body,
              'the first and last templates in match order offer no move past the boundary')
        check(fetch(f'{BASE}/{top}/move/up', move)[0] == 409 and order() == moved,
              'a forged move above the first template is refused without writing')

        missing = f'{BASE}/delete?' + urlencode([('ids[]', '99999999')])
        check(fetch(missing)[0] == 404, 'deleting a template that does not exist answers 404')
        selection = f'{BASE}/delete?' + urlencode([('ids[]', str(second)), ('ids[]', str(third))])
        fields, body = form(selection, 'automation_template_delete')
        check('Click Continue to delete the following Automation Templates.' in body, 'delete confirmation lists the selection')
        harness.sql(f"UPDATE automation_templates SET sysOid='1.3.6' WHERE id={third}")
        check(fetch(selection, fields)[0] == 409 and scalar(f'SELECT COUNT(*) FROM automation_templates WHERE id IN ({second},{third})') == '2',
              'a stale delete is refused and keeps every selected template')
        fields, _ = form(selection, 'automation_template_delete')
        status, _, url = fetch(selection, fields)
        check(status == 200 and 'deleted=1' in url and scalar(f'SELECT COUNT(*) FROM automation_templates WHERE id IN ({second},{third})') == '0'
              and scalar(f'SELECT COUNT(*) FROM automation_templates WHERE id={first}') == '1',
              'deleting selected templates removes only those rows')

        check(fetch('/automation_templates.php', {'action': 'remove', 'id': str(first)})[0] == 409
              and fetch('/automation_templates.php', {'action': 'ajax_dnd', 'template_ids[]': f'line{first}'})[0] == 409
              and scalar(f'SELECT COUNT(*) FROM automation_templates WHERE id={first}') == '1',
              'legacy automation template posts are refused without writing')
        check(fetch(f'/automation_templates.php?action=movedown&id={first}')[0] == 405, 'legacy move links answer 405')
        status, _, url = fetch(f'/automation_templates.php?action=edit&id={first}')
        check(status == 200 and urlsplit(url).path == f'{BASE}/{first}/edit', 'legacy edit link opens the Symfony editor')

        fetch(BASE + '?' + urlencode({'filter': marker}))
        saved = json.loads(scalar(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='automation_template_filters'"))
        check(saved['filter'] == marker, 'automation template list remembers explicit filters')
        status, body, _ = fetch(BASE)
        check(status == 200 and f'value="{marker}"' in body, 'a plain list visit restores the remembered filter')
        fetch('/automation_templates.php?clear=1&header=false')
        check(json.loads(scalar(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='automation_template_filters'"))['filter'] == '',
              'legacy clear resets the remembered automation template filters')

        fields, _ = form(f'{BASE}/{first}/edit', 'automation_template')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23')
        try:
            check(fetch(BASE)[0] == 403 and fetch('/automation_templates.php')[0] == 403
                  and fetch('/automation_templates.php', {'action': 'actions'})[0] == 403
                  and fetch(f'{BASE}/{first}/edit', fields | {'automation_template[sysName]': 'revoked'})[0] == 403
                  and scalar(f'SELECT sysName FROM automation_templates WHERE id={first}') == marker + ' concurrent',
                  'Automation realm revocation refuses reads, legacy posts and writes')
        finally:
            harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 23)')
    finally:
        harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 23)')
        for ident in created:
            harness.sql(f'DELETE FROM automation_templates WHERE id={ident}')
        for ident in created_device_templates:
            harness.sql(f'DELETE FROM host_template WHERE id={ident}')
        for row in prior_order:
            harness.sql(f"UPDATE automation_templates SET sequence={int(row['sequence'])} WHERE id={int(row['id'])}")
        harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='automation_template_filters'")
        if prior_preference:
            value = prior_preference[0]['value'].replace("'", "''")
            harness.sql(f"INSERT INTO settings_user (user_id, name, value) VALUES ({user_id}, 'automation_template_filters', '{value}')")

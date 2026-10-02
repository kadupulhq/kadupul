# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""VDEF workflow through real Symfony forms and primary MariaDB transactions."""
import json
from pathlib import Path
import re
import subprocess
import urllib.parse
import urllib.request
import urllib.error
import uuid
from html.parser import HTMLParser

class Forms(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.forms: list[dict] = []
        self.current: dict | None = None
        self.select: str | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "form":
            self.current = {"action": attributes.get("action", ""), "method": attributes.get("method", "get").lower(), "fields": {}}
            self.forms.append(self.current)
        elif self.current is not None and tag == "input":
            name = attributes.get("name")
            if name:
                self.current["fields"][name] = attributes.get("value", "") or ""
        elif self.current is not None and tag == "select":
            self.select = attributes.get("name")
            if self.select:
                self.current["fields"][self.select] = ""
        elif self.current is not None and tag == "option" and self.select:
            option = attributes.get("value", "") or ""
            if not self.current["fields"][self.select] or "selected" in attributes:
                self.current["fields"][self.select] = option

    def handle_endtag(self, tag: str) -> None:
        if tag == "select":
            self.select = None
        elif tag == "form":
            self.current = None


class Scenario:
    def __init__(self, harness, session) -> None:
        self.base = harness.base
        self.client = session.opener

    def request(self, path: str, data: dict | None = None) -> tuple[int, str, str]:
        if path.startswith("/graph-definitions/"):
            path = "/app.php" + path
        url = path if path.startswith("http") else self.base + path
        body = urllib.parse.urlencode(data or {}, doseq=True).encode() if data is not None else None
        headers = {"Origin": self.base} if data is not None else {}
        request = urllib.request.Request(url, data=body, headers=headers, method="POST" if data is not None else "GET")
        try:
            with self.client.open(request) as response:
                return response.status, response.geturl(), response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, error.geturl(), error.read().decode("utf-8", "replace")

    def form(self, html: str, predicate) -> dict:
        parser = Forms()
        parser.feed(html)
        for form in parser.forms:
            if predicate(form):
                return form
        raise AssertionError("Expected form was not rendered")

    def submit(self, form: dict, fields: dict) -> tuple[int, str, str]:
        values = dict(form["fields"])
        values.update(fields)
        action = urllib.parse.urljoin(self.base + "/", form["action"])
        return self.request(action, values)


def verify_vdefs(harness, session, user_id, check):
    original_direct = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=14').strip() != '0'
    harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},14)')
    scenario = Scenario(harness, session)
    status, _, html = scenario.request("/graph-definitions/vdefs")
    check(status == 200 and "Variable definitions and graph usage" in html, "VDEF list route did not render")
    before_arrays = harness.sql('SELECT COUNT(*) FROM vdef').strip()
    for field in ['filter', 'sort', 'direction', 'has_graphs']:
        status, _, body = scenario.request('/graph-definitions/vdefs?' + urllib.parse.urlencode({field + '[]': 'malformed'}))
        check(status == 400 and 'Invalid VDEF list options.' in body
              and harness.sql('SELECT COUNT(*) FROM vdef').strip() == before_arrays,
              'VDEF malformed list arrays return controlled 400 before catalog reads: ' + field)
    _verify_selected_reference_deletion(harness, scenario, check)
    name = "E2E " + uuid.uuid4().hex[:16]
    status, _, html = scenario.request("/graph-definitions/vdefs/new")
    check(status == 200, "VDEF create route did not render")
    edit_form = scenario.form(html, lambda form: "vdef_edit[name]" in form["fields"])
    status, url, html = scenario.submit(edit_form, {"vdef_edit[name]": name})
    check(status == 200 and name in html, "VDEF create/save over HTTP failed")
    match = re.search(r"/graph-definitions/vdefs/([1-9][0-9]*)/edit$", urllib.parse.urlparse(url).path)
    check(match is not None, f"VDEF save did not redirect to its editor: {url}")
    vdef_id = int(match.group(1))

    item_count = harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id={vdef_id}').strip()
    status, _, html = scenario.request(f'/graph-definitions/vdefs/{vdef_id}/items/0?type[]=6')
    check(status == 400 and 'Invalid VDEF item type.' in html
          and harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id={vdef_id}').strip() == item_count,
          'VDEF array type query returns controlled 400 without mutation')

    from harness import Session
    language_names = "'i18n_language_support','i18n_auto_detection','i18n_default_language'"
    language_settings = harness.rows(f"SELECT JSON_OBJECT('name',name,'value',value) FROM settings WHERE name IN ({language_names})")
    user_language = harness.rows(f"SELECT JSON_OBJECT('value',value) FROM settings_user WHERE user_id={user_id} AND name='user_language'")
    unknown_hash = uuid.uuid4().hex
    def sql_text(value):
        return "CONVERT(0x" + value.encode('utf-8').hex() + " USING utf8mb4)" if value else "''"
    try:
        harness.sql(f"INSERT INTO vdef_items (hash,vdef_id,sequence,type,value) VALUES ('{unknown_hash}',{vdef_id},1,99,'legacy')")
        unknown_id = int(harness.sql(f"SELECT id FROM vdef_items WHERE hash='{unknown_hash}'").strip())
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_auto_detection','0'),('i18n_default_language','en-US')")
        harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','fr-FR')")
        french_session = Session(harness.base)
        check(french_session.login('behavior-admin')['status'] == 200, 'VDEF French fixture login succeeds')
        status, _, html = Scenario(harness, french_session).request(f'/graph-definitions/vdefs/{vdef_id}/items/{unknown_id}/delete')
        check(status == 200 and '<html lang="fr">' in html and '<em>Élément VDEF</em>' in html
              and '<em>VDEF item</em>' not in html,
              'VDEF unknown item deletion uses French catalog label')
        check(harness.sql(f'SELECT type FROM vdef_items WHERE id={unknown_id}').strip() == '99',
              'VDEF translated confirmation preserves unsupported item')
    finally:
        harness.sql(f"DELETE FROM vdef_items WHERE hash='{unknown_hash}'")
        harness.sql(f"DELETE FROM settings WHERE name IN ({language_names})")
        for setting in language_settings:
            harness.sql(f"INSERT INTO settings (name,value) VALUES ({sql_text(setting['name'])},{sql_text(setting['value'])})")
        harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='user_language'")
        for setting in user_language:
            harness.sql(f"INSERT INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language',{sql_text(setting['value'])})")

    for action, parent, child, label in (
        ('edit', '', '100000000', 'VDEF oversized legacy edit ID falls back'),
        ('item_edit', '100000000', '1', 'VDEF oversized legacy parent ID falls back'),
        ('item_edit', str(vdef_id), '100000000', 'VDEF oversized legacy child ID falls back'),
        ('item_edit', '01', '1', 'VDEF noncanonical legacy parent ID falls back'),
    ):
        query = urllib.parse.urlencode({'action': action, 'vdef_id': parent, 'id': child})
        status, url, _ = scenario.request('/graph-definitions/vdefs/legacy?' + query)
        check(status == 200 and urllib.parse.urlparse(url).path.endswith('/graph-definitions/vdefs'), label)

    reference_hash = uuid.uuid4().hex
    harness.sql(f"INSERT INTO vdef_items (hash,vdef_id,sequence,type,value) VALUES ('{reference_hash}',{vdef_id},1,5,'{vdef_id}')")
    reference_id = int(harness.sql(f"SELECT id FROM vdef_items WHERE hash='{reference_hash}'").strip())
    try:
        status, _, html = scenario.request(f'/graph-definitions/vdefs/{vdef_id}/items/{reference_id}?type=1')
        check(status == 200 and 'Its stored reference is preserved.' in html and '<form' not in html, 'VDEF nested reference renders read-only without function coercion')
        _, _, html = scenario.request(f'/graph-definitions/vdefs/{vdef_id}/edit')
        reference_form = scenario.form(html, lambda form: 'vdef_edit[revision]' in form['fields'])
        status, _, _ = scenario.request(f'/graph-definitions/vdefs/{vdef_id}/items/{reference_id}', {
            'vdef_item[id]': str(reference_id), 'vdef_item[vdef_id]': str(vdef_id),
            'vdef_item[type]': '1', 'vdef_item[value]': '1',
            'vdef_item[revision]': reference_form['fields']['vdef_edit[revision]'],
            'vdef_item[_token]': reference_form['fields']['vdef_edit[_token]'],
        })
        check(status == 409 and harness.sql(f"SELECT CONCAT(type,':',value) FROM vdef_items WHERE id={reference_id}").strip() == f'5:{vdef_id}', 'VDEF nested reference refuses function overwrite')
    finally:
        harness.sql(f'DELETE FROM vdef_items WHERE id={reference_id}')

    cookies = [{'name': cookie.name, 'value': cookie.value, 'url': harness.base + '/'}
               for handler in session.opener.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor)
               for cookie in handler.cookiejar]
    browser_fronts = []

    def verify_browser_front(front):
        try:
            browser = subprocess.run(['mise', 'exec', 'node@22.22.2', '--', 'node', str(Path(__file__).with_name('vdef_browser_probe.cjs'))],
                                     input=json.dumps({'base': harness.base, 'vdefId': vdef_id, 'front': front, 'cookies': cookies}),
                                     capture_output=True, text=True, timeout=90)
        except subprocess.TimeoutExpired as error:
            diagnostic = error.stderr.decode('utf8', 'replace') if isinstance(error.stderr, bytes) else error.stderr
            raise RuntimeError(f'VDEF browser fixture timed out for {front}: {diagnostic}') from error
        if browser.returncode != 0:
            raise RuntimeError(f'VDEF browser fixture failed for {front}: {browser.stderr}')
        check(json.loads(browser.stdout) == {'csp_handler_executed': True, 'custom_item_saved': True, 'script_measured': True, 'no_control_branch_measured': True},
              'VDEF browser CSP asset/type/save verified: ' + front)
        browser_value_hex = 'browser snowman ☃ 😁'.encode('utf8').hex()
        browser_id = int(harness.sql(f"SELECT id FROM vdef_items WHERE vdef_id={vdef_id} AND BINARY value=0x{browser_value_hex}").strip())
        check(browser_id > 0, 'VDEF browser preserves four-byte custom item in MariaDB: ' + front)
        _, _, html = scenario.request(harness.base + front + f'/graph-definitions/vdefs/{vdef_id}/items/{browser_id}/delete')
        browser_delete = scenario.form(html, lambda form: 'confirm[revision]' in form['fields'])
        check(scenario.submit(browser_delete, {})[0] == 200 and harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE id={browser_id}').strip() == '0',
              'VDEF browser fixture is removed through the guarded form: ' + front)
        browser_fronts.append(front)

    verify_browser_front('/app.php')
    verify_browser_front('/public/index.php')
    harness.command('php', '-r', 'if (!copy("include/config.php", "/tmp/vdef-prefix-config.php")) { throw new RuntimeException("Cannot back up prefix fixture configuration."); }', check=True)
    try:
        harness.command('php', '-r', 'if (file_put_contents("include/config.php", PHP_EOL . chr(36) . "url_path = " . var_export("/cacti/", true) . ";" . PHP_EOL, FILE_APPEND) === false) { throw new RuntimeException("Cannot write prefix fixture configuration."); }', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', "printf 'Alias /cacti/ /var/www/html/\\n' > /etc/apache2/conf-available/vdef-prefix.conf; a2enconf vdef-prefix; apachectl -k graceful", check=True)
        verify_browser_front('/cacti/app.php')
        verify_browser_front('/cacti/public/index.php')
    finally:
        harness.command('php', '-r', 'if (!copy("/tmp/vdef-prefix-config.php", "include/config.php") || !unlink("/tmp/vdef-prefix-config.php")) { throw new RuntimeException("Cannot restore prefix fixture configuration."); }', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', 'a2disconf vdef-prefix; apachectl -k graceful', check=True)
    check(browser_fronts == ['/app.php', '/public/index.php', '/cacti/app.php', '/cacti/public/index.php'], 'VDEF browser type change and save pass under CSP')

    # Two editor tabs with the same initial revision: the first update saves,
    # and the stale tab must not overwrite it.
    status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/edit")
    stale_edit = scenario.form(html, lambda form: "vdef_edit[revision]" in form["fields"])
    fresh_edit = dict(stale_edit)
    status, _, html = scenario.submit(fresh_edit, {"vdef_edit[name]": name + " first"})
    check(status == 200 and name + " first" in html, "initial VDEF edit did not save")
    status, _, html = scenario.submit(stale_edit, {"vdef_edit[name]": name + " stale"})
    check(status == 409 and "changed after this form was opened" in html.lower(), f"stale VDEF edit was not rejected (HTTP {status})")

    # Add two custom string items, then retain an old order form while a third
    # item is saved. Submitting the old set must be rejected as stale.
    for value in ("first", "second"):
        status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/items/0?type=6")
        item_form = scenario.form(html, lambda form: "vdef_item[value]" in form["fields"])
        status, _, html = scenario.submit(item_form, {"vdef_item[type]": "6", "vdef_item[value]": value})
        check(status == 200 and value in html, f"saving VDEF item {value!r} failed")

    status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/edit")
    order_form = scenario.form(html, lambda form: "order[items]" in form["fields"])
    old_order = order_form["fields"]["order[items]"]
    old_ids = json.loads(old_order)
    same_set_reorder = dict(order_form)
    same_set_reorder["fields"] = dict(order_form["fields"])
    same_set_reorder["fields"]["order[moveUp]"] = str(old_ids[1])
    status, _, _ = scenario.submit(same_set_reorder, {})
    check(status == 200, f"initial same-set reorder did not save (HTTP {status})")
    stale_same_set = dict(order_form)
    stale_same_set["fields"] = dict(order_form["fields"])
    stale_same_set["fields"]["order[moveUp]"] = str(old_ids[1])
    status, _, html = scenario.submit(stale_same_set, {})
    check(status == 409 and "changed" in html.lower(), "same-ID stale reorder was not rejected")

    status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/edit")
    invalid_csrf = scenario.form(html, lambda form: "order[items]" in form["fields"])
    status, _, _ = scenario.submit(invalid_csrf, {"order[_token]": "invalid-token"})
    check(status == 422, "invalid CSRF token was not rejected")

    status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/items/0?type=6")
    item_form = scenario.form(html, lambda form: "vdef_item[value]" in form["fields"])
    scenario.submit(item_form, {"vdef_item[type]": "6", "vdef_item[value]": "third"})

    # Submit valid CSRF with a genuinely stale item set. This must conflict.
    status, _, html = scenario.request(f"/graph-definitions/vdefs/{vdef_id}/edit")
    current_order_form = scenario.form(html, lambda form: "order[items]" in form["fields"])
    ids = json.loads(old_order)
    current_order_form["fields"]["order[items]"] = old_order
    current_order_form["fields"]["order[moveUp]"] = str(ids[1])
    status, _, _ = scenario.submit(current_order_form, {})
    check(status == 409, "stale VDEF item order was not rejected")

    harness.sql(f"INSERT INTO graph_templates_item (vdef_id) VALUES ({vdef_id})")
    status, _, html = scenario.request("/graph-definitions/vdefs")
    check(status == 200 and "E2E" in html, "VDEF list failed after MariaDB graph dependency was added")
    action = f"/graph-definitions/vdefs/actions/delete?ids[]={vdef_id}"
    status, _, html = scenario.request(action)
    check(status == 200 and name in html, "delete confirmation did not render for referenced VDEF")
    delete_form = scenario.form(html, lambda form: "vdef_action[_token]" in form["fields"])
    status, _, html = scenario.submit(delete_form, {})
    check(status == 409 and "in use" in html.lower(), "deletion of graph-referenced VDEF was not refused")

    # Remove the new reference and exercise successful duplicate and deletion
    # handoff, then prove a child delete failure rolls the whole parent back.
    harness.sql(f'DELETE FROM graph_templates_item WHERE vdef_id={vdef_id}')
    action = f'/graph-definitions/vdefs/actions/duplicate?ids[]={vdef_id}'
    status, _, html = scenario.request(action)
    duplicate_form = scenario.form(html, lambda form: 'vdef_action[selection]' in form['fields'])
    check(scenario.submit(duplicate_form, {'vdef_action[title_format]': name + ' copy'})[0] == 200, 'VDEF duplicate workflow succeeds')
    duplicate_id = int(harness.sql(f"SELECT id FROM vdef WHERE name='{name} copy'").strip())
    check(harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id={duplicate_id}').strip() == '3', 'VDEF duplicate preserves all item rows')
    item_id = int(harness.sql(f'SELECT id FROM vdef_items WHERE vdef_id={duplicate_id} ORDER BY sequence LIMIT 1').strip())
    item_path = f'/graph-definitions/vdefs/{duplicate_id}/items/{item_id}/delete'
    status, _, html = scenario.request(item_path)
    item_delete = scenario.form(html, lambda form: 'confirm[revision]' in form['fields'])
    check(scenario.submit(item_delete, {})[0] == 200, 'VDEF item delete workflow succeeds')
    check(harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE id={item_id}').strip() == '0', 'VDEF item deletion persists')
    check(scenario.request(f'/graph-definitions/vdefs/{vdef_id}/items/{item_id}')[0] == 404, 'VDEF child ownership is enforced')
    deletion = f'/graph-definitions/vdefs/actions/delete?ids[]={duplicate_id}'
    status, _, html = scenario.request(deletion)
    delete_form = scenario.form(html, lambda form: 'vdef_action[selection]' in form['fields'])
    harness.sql("CREATE TRIGGER vdef_review_failure BEFORE DELETE ON vdef_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
    try:
        check(scenario.submit(delete_form, {})[0] == 502, 'VDEF child failure returns honest rejection')
        check(harness.sql(f'SELECT COUNT(*) FROM vdef WHERE id={duplicate_id}').strip() == '1', 'VDEF failure rolls back parent deletion')
        check(harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id={duplicate_id}').strip() == '2', 'VDEF failure retains child rows')
    finally:
        harness.sql('DROP TRIGGER vdef_review_failure')
    check(scenario.submit(delete_form, {})[0] == 200, 'VDEF unused definition deletion succeeds')
    check(harness.sql(f'SELECT COUNT(*) FROM vdef WHERE id={duplicate_id}').strip() == '0', 'VDEF duplicate deletion persists')
    check(scenario.request('/vdef.php?action=edit&id=' + str(vdef_id))[0] == 200, 'VDEF legacy editor redirects')
    check(scenario.request('/vdef.php', {'action': 'save'})[0] == 409, 'VDEF expired legacy POST never mutates')
    original_groups = harness.sql(f'SELECT group_id FROM user_auth_group_members WHERE user_id={user_id}').split()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=14; DELETE FROM user_auth_group_members WHERE user_id={user_id}')
    try:
        check(scenario.request('/graph-definitions/vdefs?page[]=bad')[0] == 403, 'VDEF console-only actor refused before malformed query')
        check(scenario.request('/graph-definitions/vdefs/actions/delete')[0] == 403, 'VDEF console-only actor refused before missing selection')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},14)')
        for group in original_groups:
            harness.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({int(group)},{user_id})')
    status, _, html = scenario.request(f'/graph-definitions/vdefs/{vdef_id}/edit')
    editor_form = scenario.form(html, lambda form: 'vdef_edit[name]' in form['fields'])
    before_name = harness.sql(f'SELECT name FROM vdef WHERE id={vdef_id}').strip()
    for table in ('vdef', 'vdef_items', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'):
        # Settings has a utf8mb4 key wider than MyISAM supports. Aria is
        # another non-InnoDB engine and preserves that schema for this probe.
        engine = 'Aria' if table == 'settings' else 'MyISAM'
        harness.sql(f'ALTER TABLE {table} ENGINE={engine}')
        try:
            check(scenario.submit(editor_form, {'vdef_edit[name]': 'must-not-save'})[0] == 502, 'VDEF nontransactional table refused: ' + table)
            check(harness.sql(f'SELECT name FROM vdef WHERE id={vdef_id}').strip() == before_name, 'VDEF nontransactional refusal preserves name: ' + table)
        finally:
            harness.sql(f'ALTER TABLE {table} ENGINE=InnoDB')
    harness.compose('cp', str(Path(__file__).with_name('vdef_transaction_probe.php')), 'web:/tmp/vdef_transaction_probe.php')
    probe = harness.command('php', '-d', 'auto_prepend_file=/harness/errors.php', '/tmp/vdef_transaction_probe.php', str(user_id), str(vdef_id), check=True)
    check(json.loads(probe['stdout']) == {'caller_preserved': True, 'native_preserved': True, 'remote_refused': True, 'primary_confirmed': True, 'temporary_shadow_refused': True, 'persistent_shadows_refused': True}, 'VDEF caller transaction and remote collector guards verified on MariaDB')
    check(json.loads(probe['stdout'])['persistent_shadows_refused'] is True, 'VDEF writes reject every InnoDB temporary participant and preserve persistent observer rows')
    harness.sql(f'DELETE FROM vdef_items WHERE vdef_id={vdef_id}; DELETE FROM vdef WHERE id={vdef_id}')
    if not original_direct:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=14')


def _verify_selected_reference_deletion(harness, scenario, check):
    owned = []
    try:
        formats = [lambda identity: '0' + str(identity), lambda identity: str(identity) + ' ',
                   lambda identity: ' ' + str(identity), lambda identity: '+' + str(identity),
                   lambda identity: str(identity) + 'tail', lambda identity: str(identity) + '.9',
                   lambda identity: str(identity) + 'e0', lambda identity: str(identity * 10) + 'e-1']
        for index, value in enumerate(formats):
            parent_hash = uuid.uuid4().hex
            harness.sql(f"INSERT INTO vdef (hash,name) VALUES ('{parent_hash}','Owned self reference {parent_hash}')")
            identity = int(harness.sql(f"SELECT id FROM vdef WHERE hash='{parent_hash}'").strip())
            owned.append((identity, parent_hash))
            reference = value(identity)
            harness.sql(f"INSERT INTO vdef_items (hash,vdef_id,sequence,type,value) VALUES ('{uuid.uuid4().hex}',{identity},1,5,'{reference}')")
            status, _, body = scenario.request(f'/graph-definitions/vdefs/actions/delete?ids[]={identity}')
            confirmation = scenario.form(body, lambda form: 'vdef_action[_token]' in form['fields'])
            deleted = scenario.submit(confirmation, {})[0]
            check(status == 200 and deleted == 200
                  and harness.sql(f'SELECT COUNT(*) FROM vdef WHERE id={identity}').strip() == '0'
                  and harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id={identity}').strip() == '0',
                  'VDEF own legacy reference deletes through CSRF form: ' + str(index))
        pair = []
        for _ in range(2):
            parent_hash = uuid.uuid4().hex
            harness.sql(f"INSERT INTO vdef (hash,name) VALUES ('{parent_hash}','Owned selected reference {parent_hash}')")
            identity = int(harness.sql(f"SELECT id FROM vdef WHERE hash='{parent_hash}'").strip())
            owned.append((identity, parent_hash))
            pair.append(identity)
        left, right = pair
        harness.sql(f"INSERT INTO vdef_items (hash,vdef_id,sequence,type,value) VALUES ('{uuid.uuid4().hex}',{left},1,5,'{right} '),('{uuid.uuid4().hex}',{right},1,5,'0{left}')")
        status, _, body = scenario.request(f'/graph-definitions/vdefs/actions/delete?ids[]={left}&ids[]={right}')
        confirmation = scenario.form(body, lambda form: 'vdef_action[_token]' in form['fields'])
        deleted = scenario.submit(confirmation, {})[0]
        check(status == 200 and deleted == 200
              and harness.sql(f'SELECT COUNT(*) FROM vdef WHERE id IN ({left},{right})').strip() == '0'
              and harness.sql(f'SELECT COUNT(*) FROM vdef_items WHERE vdef_id IN ({left},{right})').strip() == '0',
              'VDEF whole selected reference set deletes through CSRF form')
    finally:
        for identity, parent_hash in owned:
            harness.sql(f'DELETE FROM vdef_items WHERE vdef_id={identity}')
            harness.sql(f"DELETE FROM vdef WHERE id={identity} AND hash='{parent_hash}'")

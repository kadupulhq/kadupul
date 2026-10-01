"""VDEF workflow through real Symfony forms and primary MariaDB transactions."""
import json
import re
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
    name = "E2E " + uuid.uuid4().hex[:16]
    status, _, html = scenario.request("/graph-definitions/vdefs/new")
    check(status == 200, "VDEF create route did not render")
    edit_form = scenario.form(html, lambda form: "vdef_edit[name]" in form["fields"])
    status, url, html = scenario.submit(edit_form, {"vdef_edit[name]": name})
    check(status == 200 and name in html, "VDEF create/save over HTTP failed")
    match = re.search(r"/graph-definitions/vdefs/([1-9][0-9]*)/edit$", urllib.parse.urlparse(url).path)
    check(match is not None, f"VDEF save did not redirect to its editor: {url}")
    vdef_id = int(match.group(1))

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
    import json
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
    harness.sql(f'DELETE FROM vdef_items WHERE vdef_id={vdef_id}; DELETE FROM vdef WHERE id={vdef_id}')
    if not original_direct:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=14')

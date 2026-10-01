#!/usr/bin/env python3
"""Exercise VDEF admin over nginx/PHP-FPM with the Compose MariaDB schema.

Start a dedicated tests/e2e stack first, then run this from the repository
root, for example:

  E2E_BASE_URL=http://localhost:8094 python3 tests/e2e/vdef_http_mariadb.py

The script uses the test stack's mariadb service only to create a genuine
graph_templates_item dependency after exercising the public HTTP forms.
"""

from __future__ import annotations

import http.cookiejar
import os
import re
import subprocess
import urllib.parse
import urllib.request
import uuid
from html.parser import HTMLParser


BASE_URL = os.environ.get("E2E_BASE_URL", "http://localhost:8094").rstrip("/")
COMPOSE_PROJECT = os.environ.get("E2E_COMPOSE_PROJECT", "kadupul-vdef-e2e")
COMPOSE_FILE = os.environ.get("E2E_COMPOSE_FILE", "tests/e2e/docker-compose.yml")
COMPOSE_OVERRIDE = os.environ.get("E2E_COMPOSE_OVERRIDE", "")


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
    def __init__(self) -> None:
        jar = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    def request(self, path: str, data: dict | None = None) -> tuple[int, str, str]:
        if path.startswith("/graph-definitions/"):
            path = "/app.php" + path
        url = path if path.startswith("http") else BASE_URL + path
        body = urllib.parse.urlencode(data or {}, doseq=True).encode() if data is not None else None
        headers = {"Origin": BASE_URL} if data is not None else {}
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
        action = urllib.parse.urljoin(BASE_URL + "/", form["action"])
        return self.request(action, values)


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def seed_graph_dependency(vdef_id: int) -> None:
    command = ["docker", "compose", "-p", COMPOSE_PROJECT, "-f", COMPOSE_FILE]
    if COMPOSE_OVERRIDE:
        command.extend(["-f", COMPOSE_OVERRIDE])
    command.extend([
        "exec", "-T", "mariadb", "mariadb", "-ucactiuser", "-pcactipass", "cacti",
        "-e", f"INSERT INTO graph_templates_item (vdef_id) VALUES ({vdef_id});",
    ])
    subprocess.run(command, check=True, stdout=subprocess.DEVNULL)


def main() -> None:
    scenario = Scenario()
    status, url, body = scenario.request('/graph-definitions/vdefs?page[]=invalid')
    check(status == 401 and 'Access denied' in body,
          'unauthenticated VDEF HTTP request is rejected before malformed query parsing '
          f'(HTTP {status}, URL {url}, bootstrap fatal: {"Fatal error" in body})')

    status, _, html = scenario.request("/")
    check(status == 200 and "login_username" in html, "login page did not load")
    login = scenario.form(html, lambda form: "login_username" in form["fields"])
    status, _, html = scenario.submit(login, {"login_username": "admin", "login_password": "admin"})
    check(status == 200 and "login_username" not in html, "admin login did not complete")

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

    seed_graph_dependency(vdef_id)
    status, _, html = scenario.request("/graph-definitions/vdefs")
    check(status == 200 and "E2E" in html, "VDEF list failed after MariaDB graph dependency was added")
    action = f"/graph-definitions/vdefs/actions/delete?ids[]={vdef_id}"
    status, _, html = scenario.request(action)
    check(status == 200 and name in html, "delete confirmation did not render for referenced VDEF")
    delete_form = scenario.form(html, lambda form: "vdef_action[_token]" in form["fields"])
    status, _, html = scenario.submit(delete_form, {})
    check(status == 409 and "in use" in html.lower(), "deletion of graph-referenced VDEF was not refused")

    print(f"PASS VDEF HTTP/MariaDB scenario id={vdef_id}: save, stale edit/reorder, invalid CSRF, graph dependency delete refusal")


if __name__ == "__main__":
    main()

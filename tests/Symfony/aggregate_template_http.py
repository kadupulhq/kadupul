#!/usr/bin/env python3
"""Real HTTP/MariaDB aggregate-template create/edit/delete scenario."""

from __future__ import annotations

import http.cookiejar
import os
import re
import subprocess
import urllib.parse
import urllib.request
import uuid
from html.parser import HTMLParser


BASE_URL = os.environ.get("E2E_BASE_URL", "http://localhost:8095").rstrip("/")
COMPOSE_PROJECT = os.environ.get("E2E_COMPOSE_PROJECT", "kadupul-aggregate-e2e")
COMPOSE_FILE = os.environ.get("E2E_COMPOSE_FILE", "tests/e2e/docker-compose.yml")
COMPOSE_OVERRIDE = os.environ.get("E2E_COMPOSE_OVERRIDE", "/private/tmp/aggregate-compose-unique.yml")


class Forms(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.forms: list[dict] = []
        self.current: dict | None = None
        self.select: str | None = None
        self.option_selected = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "form":
            self.current = {"action": attributes.get("action", ""), "method": attributes.get("method", "get").lower(), "fields": {}}
            self.forms.append(self.current)
        elif self.current is not None and tag == "input":
            name = attributes.get("name")
            kind = (attributes.get("type") or "text").lower()
            if name and (kind != "checkbox" or "checked" in attributes):
                self.current["fields"][name] = attributes.get("value", "") or "1" if kind == "checkbox" else attributes.get("value", "") or ""
        elif self.current is not None and tag == "select":
            self.select = attributes.get("name")
            self.option_selected = False
            if self.select:
                self.current["fields"][self.select] = ""
        elif self.current is not None and tag == "option" and self.select:
            value = attributes.get("value", "") or ""
            if "selected" in attributes or not self.option_selected:
                self.current["fields"][self.select] = value
            self.option_selected = self.option_selected or "selected" in attributes

    def handle_endtag(self, tag: str) -> None:
        if tag == "select":
            self.select = None
        elif tag == "form":
            self.current = None


class Scenario:
    def __init__(self, client=None, base_url: str = BASE_URL) -> None:
        self.client = client or urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.base_url = base_url.rstrip("/")

    def request(self, path: str, data: dict | None = None) -> tuple[int, str, str]:
        if path.startswith("/aggregate-templates"):
            path = "/app.php" + path
        url = path if path.startswith("http") else self.base_url + path
        payload = urllib.parse.urlencode(data or {}).encode() if data is not None else None
        request = urllib.request.Request(url, data=payload, headers={"Origin": self.base_url} if data is not None else {})
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
        raise AssertionError("Expected HTML form was not rendered")

    def submit(self, form: dict, fields: dict) -> tuple[int, str, str]:
        values = dict(form["fields"])
        values.update(fields)
        return self.request(urllib.parse.urljoin(self.base_url + "/", form["action"]), values)


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def sql(statement: str) -> str:
    command = ["docker", "compose", "-p", COMPOSE_PROJECT, "-f", COMPOSE_FILE, "-f", COMPOSE_OVERRIDE,
               "exec", "-T", "mariadb", "mariadb", "-ucactiuser", "-pcactipass", "cacti", "-N", "-e", statement]
    return subprocess.run(command, check=True, text=True, stdout=subprocess.PIPE).stdout.strip()


def seed_child_graph(template_id: int, source_id: int, marker: str) -> int:
    sql(f"SET @gt={source_id}; "
        "INSERT INTO graph_local (graph_template_id,host_id,snmp_query_id,snmp_index) VALUES (0,0,0,''); SET @base=LAST_INSERT_ID(); "
        "INSERT INTO graph_templates_graph (local_graph_id,graph_template_id,title,title_cache,width,height) "
        "VALUES (@base,@gt,'Base title','Base title',480,120); "
        "INSERT INTO graph_templates_item (local_graph_template_item_id,local_graph_id,graph_template_id,graph_type_id,consolidation_function_id,text_format,value,sequence) "
        "SELECT id,@base,@gt,graph_type_id,consolidation_function_id,text_format,value,sequence FROM graph_templates_item WHERE graph_template_id=@gt AND local_graph_id=0 ORDER BY sequence; "
        "INSERT INTO graph_local (graph_template_id,host_id,snmp_query_id,snmp_index) VALUES (0,0,0,''); SET @child=LAST_INSERT_ID(); "
        "INSERT INTO graph_templates_graph (local_graph_id,graph_template_id,title,title_cache,width,height) "
        "VALUES (@child,@gt,'Child title','Child title',480,120); "
        f"INSERT INTO aggregate_graphs (aggregate_template_id,template_propogation,local_graph_id,title_format,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,user_id) "
        f"VALUES ({template_id},'on',@child,'{marker}_child',@gt,'','',0,1,1,'',1,1); SET @aggregate=LAST_INSERT_ID(); "
        "INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id,sequence) VALUES (@aggregate,@base,0); "
        "SELECT @child;")
    return int(sql("SELECT id FROM graph_local ORDER BY id DESC LIMIT 1"))


def main(scenario: Scenario | None = None, authenticate: bool = True, authenticated_session=None, harness=None) -> None:
    sql("DROP TRIGGER IF EXISTS aggregate_e2e_fail_child_item")
    marker = "AGG_E2E_" + uuid.uuid4().hex[:14]
    source_name = marker + "_source"
    sql(f"INSERT INTO graph_templates (name) VALUES ('{source_name}'); SET @gt=LAST_INSERT_ID(); "
        "INSERT INTO graph_templates_graph (graph_template_id,local_graph_id,title,title_cache,width,height) "
        "VALUES (@gt,0,'Source title','Source title',480,120); "
        "INSERT INTO graph_templates_item (graph_template_id,graph_type_id,consolidation_function_id,text_format,value,sequence) "
        "VALUES (@gt,4,1,'source item one','test_value_one',0),(@gt,4,1,'source item two','test_value_two',1);")
    source_id = int(sql(f"SELECT id FROM graph_templates WHERE name='{source_name}'"))
    sql(f"INSERT INTO graph_templates (name) VALUES ('{source_name}')")
    duplicate_source_id = int(sql(f"SELECT MAX(id) FROM graph_templates WHERE name='{source_name}'"))

    if authenticated_session is not None:
        scenario = Scenario(client=authenticated_session.opener, base_url=authenticated_session.base)
        authenticate = False
    scenario = scenario or Scenario()
    if authenticate:
        status, _, page = scenario.request("/")
        check(status == 200 and "login_username" in page, "login page did not render")
        login = scenario.form(page, lambda form: "login_username" in form["fields"])
        status, _, page = scenario.submit(login, {"login_username": "admin", "login_password": "admin"})
        check(status == 200 and "login_username" not in page, "admin login did not complete")

    status, _, page = scenario.request("/aggregate_templates.php", {"action": "edit", "id": "1"})
    check(status == 409 and "form has expired" in page.lower(), "legacy POST was replayed instead of rejected")

    status, _, page = scenario.request("/aggregate-templates")
    check(status == 200 and "Aggregate graph templates" in page, "aggregate list did not render")
    if harness is not None:
        from aggregate_template_browser import verify_source_selector
        verify_source_selector(harness, scenario, source_id, check)
    status, _, page = scenario.request(f"/aggregate-templates/0/edit?source={source_id}")
    check(status == 200, f"create form did not render (HTTP {status}): {page[:600]}")
    form = scenario.form(page, lambda item: "aggregate_template[name]" in item["fields"])
    source_select = re.search(r'<select\b[^>]*id="aggregate_template_graph_template_id"[^>]*>(.*?)</select>', page, re.S)
    check(source_select is not None and all(
        f'value="{identity}"' in source_select.group(1) and f'{source_name} (#{identity})' in source_select.group(1)
        for identity in [source_id, duplicate_source_id]
    ), 'duplicate-name graph templates retain both selectable identities')
    check(form['fields']['aggregate_template[graph_type]'] == '8', 'new aggregate template uses the supported STACK default')
    item_controls = re.findall(r'name="(aggregate_template\[items\][^"]+)"', page)
    check(len(item_controls) >= 8, f"template item controls were not rendered as expected: {item_controls}")
    status, url, page = scenario.submit(form, {
        "aggregate_template[name]": marker,
        "aggregate_template[graph_template_id]": str(source_id),
        "aggregate_template[graphSettings_width][value]": "640",
        "aggregate_template[graphSettings_width][override]": "1",
        "aggregate_template[items][0][skip]": "1",
        "aggregate_template[items][1][total]": "1",
    })
    check(status == 200 and marker in page, f"template create failed with HTTP {status}: {page[:500]}")
    match = re.search(r"/aggregate-templates/([1-9][0-9]*)/edit\?saved=1$", urllib.parse.urlparse(url).path + ("?" + urllib.parse.urlparse(url).query if urllib.parse.urlparse(url).query else ""))
    check(match is not None, f"save did not redirect to the editor: {url}")
    template_id = int(match.group(1))
    check(sql(f"SELECT CONCAT(name,':',graph_template_id,':',user_id) FROM aggregate_graph_templates WHERE id={template_id}") == f"{marker}:{source_id}:1", "database row does not match submitted actor and source")
    check(sql(f"SELECT graph_type FROM aggregate_graph_templates WHERE id={template_id}") == '8', 'supported STACK default survives the worker data handoff')
    check(sql(f"SELECT CONCAT(t_width,':',width) FROM aggregate_graph_templates_graph WHERE aggregate_template_id={template_id}") == "on:640", "graph override data handoff failed")
    check(int(sql(f"SELECT COUNT(*) FROM aggregate_graph_templates_item WHERE aggregate_template_id={template_id}")) == 2, "source graph items were not handed off")
    item_flags = sql(f"SELECT GROUP_CONCAT(CONCAT(sequence,':',item_skip,':',item_total) ORDER BY sequence SEPARATOR ',') FROM aggregate_graph_templates_item WHERE aggregate_template_id={template_id}")
    check(item_flags == "0:on:,1::on", f"skip and total item controls were not handed off: {item_flags}")
    child_graph_id = seed_child_graph(template_id, source_id, marker)

    status, _, page = scenario.request(f"/aggregate-templates/{template_id}/edit")
    stale_form = scenario.form(page, lambda item: "aggregate_template[revision]" in item["fields"])
    status, _, page = scenario.submit(stale_form, {
        "aggregate_template[name]": marker + "_fresh",
        "aggregate_template[graphSettings_width][value]": "700",
    })
    check(status == 200 and marker + "_fresh" in page, f"fresh update failed with HTTP {status}: {page[:500]}")
    status, _, page = scenario.submit(stale_form, {"aggregate_template[name]": marker + "_stale"})
    check(status == 409 and "changed" in page.lower(), "stale concurrent editor was accepted")
    check(sql(f"SELECT name FROM aggregate_graph_templates WHERE id={template_id}") == marker + "_fresh", "stale editor overwrote current value")
    check(sql(f"SELECT width FROM graph_templates_graph WHERE local_graph_id={child_graph_id}") == "700", "updated aggregate graph setting did not propagate to the dependent graph")

    before_items = sql(f"SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id={child_graph_id}")
    sql(f"CREATE TRIGGER aggregate_e2e_fail_child_item BEFORE INSERT ON graph_templates_item FOR EACH ROW "
        f"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected aggregate propagation failure'")
    status, _, page = scenario.request(f"/aggregate-templates/{template_id}/edit")
    no_change_form = scenario.form(page, lambda item: "aggregate_template[revision]" in item["fields"])
    status, _, page = scenario.submit(no_change_form, {"aggregate_template[name]": marker + "_fresh"})
    check(status == 200, f"unchanged template save unexpectedly propagated dependent graphs (HTTP {status}: {page[:500]})")
    check(sql(f"SELECT width FROM graph_templates_graph WHERE local_graph_id={child_graph_id}") == "700", "unchanged save modified child graph")

    status, _, page = scenario.request(f"/aggregate-templates/{template_id}/edit")
    failure_form = scenario.form(page, lambda item: "aggregate_template[revision]" in item["fields"])
    status, _, _ = scenario.submit(failure_form, {"aggregate_template[graphSettings_width][value]": "800"})
    check(status == 502, f"propagation failure was falsely reported as success (HTTP {status})")
    sql("DROP TRIGGER aggregate_e2e_fail_child_item")
    check(sql(f"SELECT width FROM aggregate_graph_templates_graph WHERE aggregate_template_id={template_id}") == "700", "failed propagation did not roll back template settings")
    check(sql(f"SELECT width FROM graph_templates_graph WHERE local_graph_id={child_graph_id}") == "700", "failed propagation did not roll back child graph settings")
    check(sql(f"SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id={child_graph_id}") == before_items, "failed propagation did not restore child graph items")

    status, _, page = scenario.request(f"/aggregate-templates/{template_id}/edit")
    csrf_form = scenario.form(page, lambda item: "aggregate_template[_token]" in item["fields"])
    status, _, _ = scenario.submit(csrf_form, {"aggregate_template[_token]": "invalid-token"})
    check(status == 422, "invalid CSRF token was accepted")

    status, _, page = scenario.request(f"/aggregate-templates/actions/delete?ids[]={template_id}")
    check(status == 200 and marker + "_fresh" in page, "delete confirmation did not render")
    stale_delete_form = scenario.form(page, lambda item: "aggregate_template_delete[revisions]" in item["fields"])
    status, _, page = scenario.request(f"/aggregate-templates/{template_id}/edit")
    concurrent_form = scenario.form(page, lambda item: "aggregate_template[revision]" in item["fields"])
    status, _, page = scenario.submit(concurrent_form, {"aggregate_template[name]": marker + "_before_delete"})
    check(status == 200, "concurrent edit before delete failed")
    status, _, page = scenario.submit(stale_delete_form, {})
    check(status == 409 and "changed" in page.lower(), "stale delete confirmation was accepted")
    check(sql(f"SELECT name FROM aggregate_graph_templates WHERE id={template_id}") == marker + "_before_delete", "stale delete removed changed row")

    status, _, page = scenario.request(f"/aggregate-templates/actions/delete?ids[]={template_id}")
    check(status == 200, "fresh delete confirmation did not render")
    delete_form = scenario.form(page, lambda item: "aggregate_template_delete[revisions]" in item["fields"])
    status, _, page = scenario.submit(delete_form, {"aggregate_template_delete[_token]": "invalid-token"})
    check(status == 422 and sql(f"SELECT COUNT(*) FROM aggregate_graph_templates WHERE id={template_id}") == "1", "invalid delete CSRF token was accepted")
    status, _, page = scenario.request(f"/aggregate-templates/actions/delete?ids[]={template_id}")
    delete_form = scenario.form(page, lambda item: "aggregate_template_delete[revisions]" in item["fields"])
    status, url, page = scenario.submit(delete_form, {})
    check(status == 200 and "Aggregate graph templates" in page, f"delete confirmation did not return to the list (HTTP {status}): {page[:600]}")
    check(sql(f"SELECT aggregate_template_id FROM aggregate_graphs WHERE local_graph_id={child_graph_id}") == "0", "child aggregate graph was not unlinked")
    check(sql(f"SELECT COUNT(*) FROM aggregate_graph_templates WHERE id={template_id}") == "0", "template was not deleted")

    print(f"PASS aggregate-template HTTP/MariaDB scenario id={template_id}: save and dependent graph propagation, stale edit, CSRF, child unlink delete")


if __name__ == "__main__":
    main()

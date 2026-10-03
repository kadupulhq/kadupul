# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise the installed legacy callers through their actual authenticated forms."""
from html.parser import HTMLParser
import json
from urllib.error import HTTPError
from urllib.parse import urlencode

REQUIRED_CHECKS = (
    'installed CDEF duplication preserves persisted parent and children',
    'installed CDEF referenced deletion refuses and preserves actual records',
    'installed CDEF unused deletion removes actual parent and children',
    'installed CDEF child copy refusal reports exact partial parent and preserves original definition',
    'installed legacy write requires actual CSRF before mutation: /cdef.php',
    'installed legacy write refuses the revoked actor realm before mutation: /cdef.php',
    'installed legacy write requires actual CSRF before mutation: /graphs.php',
    'installed legacy write refuses the revoked actor realm before mutation: /graphs.php',
    'installed aggregate template save persists rendered graph item cache',
    'installed aggregate malformed item request refuses before metadata writes',
    'installed aggregate cache refusal preserves cache and reports independently saved metadata',
    'installed aggregate propagation refusal reports saved metadata and preserves graph participants',
    'installed graphs confirmation leaves all nine participants unchanged: 9',
    'installed graphs confirmation leaves all nine participants unchanged: 10',
    'installed graphs aggregation persists exact selected member and generated items: 9',
    'installed graphs aggregation persists exact selected member and generated items: 10',
    'installed graph malformed color refuses with all nine participants unchanged',
    'installed graph late generation refusal rolls back all nine participants',
    'installed graph editor renders persisted graph device and data source identities without mutation',
    'installed graph autocomplete returns exact persisted owned data source identity without mutation',
    'installed deleted graph editor refuses stale identity without mutation',
    'installed color synchronization reports genuine empty usage',
    'installed color synchronization regenerates actual template and standalone cohorts',
    'installed color second cohort refusal preserves failed graph and reports earlier committed cohort',
    'installed color discovery failure reports refusal instead of empty usage',
    'installed aggregate graph editor saves actual rendered settings and member identity',
    'installed aggregate graph malformed request refuses before all nine participant writes',
    'installed aggregate graph cache refusal preserves cache and reports saved title',
    'installed aggregate graph regeneration refusal preserves generated items and reports saved settings',
    'installed legacy CDEF aggregate and color page scenarios completed cleanup',
)


class RenderedForm(HTMLParser):
    """Retain successful controls from the actual server-rendered legacy form."""

    def __init__(self):
        super().__init__()
        self.fields = {}
        self.forms = []
        self.current = None
        self.select = None
        self.options = []
        self.textarea = None
        self.disabled_options = False
        self.checkboxes = []
        self.links = []

    def control(self, name, value):
        if name in self.current:
            raise RuntimeError('Repeated successful form control requires explicit browser handling: ' + name)
        self.current[name] = value

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        name = attrs.get('name')
        if tag == 'a' and attrs.get('href') is not None:
            self.links.append(attrs['href'])
        if tag == 'input' and attrs.get('type') == 'checkbox' and name:
            self.checkboxes.append((name, 'disabled' in attrs))
        if tag == 'form':
            self.current = {}
            return
        if self.current is None:
            return
        if tag == 'input' and name and 'disabled' not in attrs:
            kind = attrs.get('type', 'text')
            if kind not in ('button', 'submit', 'reset', 'file') and (
                    kind not in ('checkbox', 'radio') or 'checked' in attrs):
                self.control(name, attrs.get('value', 'on' if kind in ('checkbox', 'radio') else ''))
        elif tag == 'select' and name and 'disabled' not in attrs:
            if 'multiple' in attrs:
                raise RuntimeError('Multiple select requires explicit browser handling: ' + name)
            self.select = name
            self.options = []
        elif tag == 'optgroup' and self.select:
            self.disabled_options = 'disabled' in attrs
        elif tag == 'option' and self.select and not self.disabled_options and 'disabled' not in attrs:
            if 'value' not in attrs:
                raise RuntimeError('Implicit option value requires explicit browser handling: ' + self.select)
            self.options.append((attrs.get('value', ''), 'selected' in attrs))
        elif tag == 'textarea' and name and 'disabled' not in attrs:
            self.textarea = name
            self.control(name, '')

    def handle_data(self, data):
        if self.textarea:
            self.current[self.textarea] += data

    def handle_endtag(self, tag):
        if tag == 'select' and self.select:
            if self.options:
                self.control(self.select, next(
                    (value for value, selected in self.options if selected), self.options[0][0])
                )
            self.select = None
        elif tag == 'textarea':
            self.textarea = None
        elif tag == 'optgroup':
            self.disabled_options = False
        elif tag == 'form' and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def request(session, path, fields=None):
    payload = urlencode(fields).encode() if fields is not None else None
    try:
        response = session.opener.open(session.base + path, data=payload, timeout=30)
    except HTTPError as error:
        response = error
    with response:
        body = response.read().decode('utf-8', errors='strict')
        status = response.status
    form = RenderedForm()
    form.feed(body)
    candidates = [fields for fields in form.forms if 'selected_items' in fields
                  or 'save_component_template' in fields or 'save_component_graph' in fields or '__csrf_magic' in fields]
    if candidates:
        form.fields = next((fields for fields in candidates if 'selected_items' in fields
                           or 'save_component_template' in fields or 'save_component_graph' in fields), candidates[0])
    token = form.fields.get('__csrf_magic')
    if token:
        session.token = token
    return status, body, form.fields


def verify_list_selection(session, page, identifier, enabled, check):
    status, body, _ = request(session, page + '?filter=Owned%20HTTP')
    parsed = RenderedForm()
    parsed.feed(body)
    matches = [disabled for name, disabled in parsed.checkboxes if name == 'chk_' + str(identifier)]
    check(status == 200 and matches == [not enabled],
          'installed legacy list selection ' + ('admits' if enabled else 'disables') + ' owned row: ' + page)


def confirmation(session, page, action, identifier, check, updates=None, enabled=True):
    verify_list_selection(session, page, identifier, enabled, check)
    status, body, form = request(session, page, {
        'action': 'actions', 'drp_action': str(action),
        'chk_' + str(identifier): 'on', '__csrf_magic': session.token,
    })
    check(status == 200 and form.get('action') == 'actions'
          and form.get('drp_action') == str(action) and 'selected_items' in form
          and '__csrf_magic' in form, 'legacy confirmation uses rendered selection and CSRF: ' + page)
    if updates:
        for name, value in updates.items():
            check(name in form, 'legacy submitted control is rendered: ' + name)
            form[name] = value
    return form


def bulk(session, page, action, identifier, check, updates=None, enabled=True):
    return request(session, page, confirmation(session, page, action, identifier, check, updates, enabled))


def template_snapshot(harness, identifier):
    return [harness.sql(query) for query in (
        f'SELECT * FROM aggregate_graph_templates WHERE id={identifier}',
        f'SELECT * FROM aggregate_graph_templates_graph WHERE aggregate_template_id={identifier}',
        f'SELECT * FROM aggregate_graph_templates_item WHERE aggregate_template_id={identifier} '
        'ORDER BY graph_templates_item_id',
        f'SELECT * FROM aggregate_graphs WHERE aggregate_template_id={identifier} ORDER BY id',
        "SELECT name,value FROM settings WHERE name='time_last_change_aggregate_graph'",
    )]


def graph_snapshot(harness):
    """Observe all transaction participants independently through the fixture DB."""
    return [harness.sql('SELECT * FROM ' + table + ' ORDER BY ' + order) for table, order in (
        ('graph_local', 'id'), ('graph_templates_graph', 'id'),
        ('graph_templates_item', 'id'), ('aggregate_graphs', 'id'),
        ('aggregate_graphs_items', 'aggregate_graph_id,local_graph_id'),
        ('aggregate_graphs_graph_item', 'aggregate_graph_id,graph_templates_item_id'),
        ('cdef', 'id'), ('cdef_items', 'id'), ('settings', 'name'),
    )]


def graph_confirmation(harness, session, graph, action, check):
    verify_list_selection(session, '/graphs.php', graph, True, check)
    before = graph_snapshot(harness)
    status, _, form = request(session, '/graphs.php', {
        'action': 'actions', 'drp_action': str(action),
        'chk_' + str(graph): 'on', '__csrf_magic': session.token,
    })
    check(status == 200 and form.get('drp_action') == str(action)
          and 'selected_items' in form and 'title_format' in form
          and '__csrf_magic' in form and graph_snapshot(harness) == before,
          'installed graphs confirmation leaves all nine participants unchanged: ' + str(action))
    return form


def verify_write_access(harness, session, page, form, realm, observe, check):
    """Refuse missing CSRF and a revoked real actor realm independently."""
    before = observe()
    missing = dict(form)
    del missing['__csrf_magic']
    status, body, _ = request(session, page, missing)
    check(status == 200 and 'CSRF Timeout, refreshing page.' in body and observe() == before,
          'installed legacy write requires actual CSRF before mutation: ' + page)
    # The actual global CSRF callback rotates the session and redirects to GET.
    form['__csrf_magic'] = session.token
    check(harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id=1 '
                      f'AND realm_id={realm}').strip() == '1'
          and harness.sql('SELECT COUNT(*) FROM user_auth_group_members WHERE user_id=1').strip() == '0',
          'installed denied actor has an exact exclusively direct realm grant: ' + page)
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id=1 AND realm_id={realm}')
    try:
        status, body, _ = request(session, page)
        check(status == 200 and 'You are not permitted to access this section' in body,
              'installed legacy read refuses the revoked actor realm: ' + page)
        status, body, _ = request(session, page, form)
        check(status == 200 and 'You are not permitted to access this section' in body
              and observe() == before,
              'installed legacy write refuses the revoked actor realm before mutation: ' + page)
    finally:
        harness.sql(f'INSERT INTO user_auth_realm(user_id,realm_id) VALUES (1,{realm})')
    status, body, _ = request(session, page)
    check(status == 200 and 'You are not permitted to access this section' not in body,
          'installed legacy restored actor realm admits its read control: ' + page)


def verify_graph_editor(harness, session, graph, host, check):
    """Read the real member editor and its persisted data-source choices."""
    sources = harness.rows("SELECT JSON_OBJECT('id',dtr.id,'local',dtr.local_data_id,"
                           "'name',dtd.name_cache,'source',dtr.data_source_name) "
                           "FROM graph_templates_item AS gti "
                           "JOIN data_template_rrd AS dtr ON dtr.id=gti.task_item_id "
                           "JOIN data_template_data AS dtd ON dtd.local_data_id=dtr.local_data_id "
                           f"WHERE gti.local_graph_id={graph} AND dtd.local_data_id>0 ORDER BY dtr.id")
    check(bool(sources), 'installed graph editor has independently persisted owned data sources')
    before = graph_snapshot(harness)
    status, body, form = request(session, f'/graphs.php?action=graph_edit&id={graph}')
    rendered = RenderedForm()
    rendered.feed(body)
    description = harness.sql(f'SELECT description FROM host WHERE id={host}').strip()
    check(status == 200 and form.get('local_graph_id') == str(graph)
          and form.get('host_id_prev') == str(host) and form.get('host_id') == description
          and form.get('graph_template_id') == '4'
          and form.get('save_component_graph') == '1' and '__csrf_magic' in form
          and f'host.php?action=edit&id={host}' in rendered.links
          and all(f"data_sources.php?action=ds_edit&id={source['local']}" in rendered.links for source in sources)
          and graph_snapshot(harness) == before,
          'installed graph editor renders persisted graph device and data source identities without mutation')
    source = sources[0]
    query = urlencode({'action': 'ajax_graph_items', 'rrd_id': str(source['id']),
                       'host_id': str(host), 'term': source['name']})
    status, body, _ = request(session, '/graphs.php?' + query)
    rows = json.loads(body)
    label = source['name'] + ' (' + source['source'] + ')'
    check(status == 200 and isinstance(rows, list)
          and any(str(row['id']) == str(source['id']) and row['name'] == label
                  and row['label'] == label for row in rows)
          and graph_snapshot(harness) == before,
          'installed graph autocomplete returns exact persisted owned data source identity without mutation')


def verify_graph_creation(harness, session, aggregate, color, check):
    """Submit the genuine standalone and template aggregation confirmations."""
    host = None
    created = []
    check(harness.sql("SELECT COUNT(*) FROM host WHERE description='Owned HTTP member'").strip() == '0',
          'installed graph member device identity is exclusively unused')
    try:
        result = harness.php('cli/add_device.php', '--description=Owned HTTP member', '--ip=snmp',
                             '--template=0', '--version=2', '--community=public', '--avail=none')
        check(result['exit'] == 0, 'installed graph member device uses actual CLI')
        host = int(harness.sql("SELECT id FROM host WHERE description='Owned HTTP member'").strip())
        result = harness.php('cli/add_datasource.php', '--host-id=' + str(host), '--data-template-id=1')
        check(result['exit'] == 0, 'installed graph member data source uses actual CLI')
        result = harness.php('cli/add_graphs.php', '--host-id=' + str(host), '--graph-type=cg',
                             '--graph-template-id=4')
        check(result['exit'] == 0, 'installed graph member uses actual graph CLI')
        graph = int(harness.sql(f'SELECT id FROM graph_local WHERE host_id={host} '
                                'AND graph_template_id=4').strip())
        check(int(harness.sql(f'SELECT COUNT(*) FROM graph_templates_item WHERE '
                              f'local_graph_id={graph} AND task_item_id>0').strip()) > 0,
              'installed graph member has persisted data source items')
        verify_graph_editor(harness, session, graph, host, check)
        for action in (9, 10):
            form = graph_confirmation(harness, session, graph, action, check)
            form['title_format'] = 'Owned HTTP aggregate ' + str(action)
            if action == 10:
                check(form.get('aggregate_template_id') == str(aggregate),
                      'installed template aggregation renders the owned matching template')
            else:
                verify_write_access(harness, session, '/graphs.php', form, 5,
                                    lambda: graph_snapshot(harness), check)
            status, body, _ = request(session, '/graphs.php', form)
            rows = harness.rows("SELECT JSON_OBJECT('id',id,'local',local_graph_id,'template',"
                                "aggregate_template_id) FROM aggregate_graphs WHERE title_format="
                                "'Owned HTTP aggregate " + str(action) + "'")
            check(status == 200 and len(rows) == 1 and 'creation could not be confirmed' not in body,
                  'installed graphs aggregation admits real rendered selection: ' + str(action))
            row = rows[0]
            created.append((int(row['id']), int(row['local'])))
            check(int(row['template']) == (aggregate if action == 10 else 0)
                  and harness.sql(f"SELECT local_graph_id FROM aggregate_graphs_items WHERE "
                                  f"aggregate_graph_id={row['id']} ORDER BY sequence").strip() == str(graph)
                  and int(harness.sql(f"SELECT COUNT(*) FROM graph_templates_item WHERE "
                                      f"local_graph_id={row['local']}").strip()) > 0,
                  'installed graphs aggregation persists exact selected member and generated items: ' + str(action))
        form = graph_confirmation(harness, session, graph, 9, check)
        form['title_format'] = 'Owned HTTP unrelated aggregate'
        status, body, _ = request(session, '/graphs.php', form)
        unrelated = harness.rows("SELECT JSON_OBJECT('id',id,'local',local_graph_id) FROM aggregate_graphs "
                                 "WHERE title_format='Owned HTTP unrelated aggregate'")
        check(status == 200 and len(unrelated) == 1 and 'creation could not be confirmed' not in body,
              'installed color scope control creates an actual unrelated standalone aggregate')
        created.append((int(unrelated[0]['id']), int(unrelated[0]['local'])))
        verify_aggregate_graph_save(harness, session, created[0], color, check)
        status, _, template_form = request(session, f'/aggregate_templates.php?action=edit&id={aggregate}')
        check(status == 200 and template_form.get('id') == str(aggregate),
              'installed propagation refusal uses real aggregate template controls')
        template_form['action'] = 'save'
        template_form['name'] = 'Owned HTTP propagation refusal'
        before = graph_snapshot(harness)
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER owned_http_propagation_refusal BEFORE INSERT ON graph_templates_item "
                    f"FOR EACH ROW BEGIN IF NEW.local_graph_id={created[1][1]} THEN "
                    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP propagation refusal'; END IF; END$$\nDELIMITER ;")
        try:
            status, body, _ = request(session, '/aggregate_templates.php', template_form)
            check(status == 200 and 'regeneration failed. Template settings may already have been saved' in body
                  and harness.sql(f'SELECT name FROM aggregate_graph_templates WHERE id={aggregate}').strip()
                  == 'Owned HTTP propagation refusal' and graph_snapshot(harness) == before,
                  'installed aggregate propagation refusal reports saved metadata and preserves graph participants')
        finally:
            harness.sql('DROP TRIGGER owned_http_propagation_refusal')
        verify_color_cohorts(harness, session, aggregate, color, created, check)
        form = graph_confirmation(harness, session, graph, 9, check)
        item = next(name for name in form if name.startswith('agg_color_'))
        malformed = dict(form)
        del malformed[item]
        malformed[item + '[nested]'] = '2'
        before = graph_snapshot(harness)
        status, body, _ = request(session, '/graphs.php', malformed)
        check(status == 200 and 'creation could not be confirmed' in body
              and graph_snapshot(harness) == before,
              'installed graph malformed color refuses with all nine participants unchanged')
        form = graph_confirmation(harness, session, graph, 9, check)
        form['title_format'] = 'Owned HTTP rejected aggregate'
        before = graph_snapshot(harness)
        harness.sql("CREATE TRIGGER owned_http_graph_refusal BEFORE INSERT ON graph_templates_item "
                    "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP late refusal'")
        try:
            status, body, _ = request(session, '/graphs.php', form)
            check(status == 200 and 'creation could not be confirmed' in body
                  and graph_snapshot(harness) == before,
                  'installed graph late generation refusal rolls back all nine participants')
        finally:
            harness.sql('DROP TRIGGER owned_http_graph_refusal')
    finally:
        for identifier, local in created:
            result = harness.php('-r', '$no_http_headers=true;require "include/global.php";'
                                 'require_once "lib/api_graph.php";$ids=[' + str(local) + '];'
                                 'api_delete_graphs($ids,2);')
            check(result['exit'] == 0 and harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE id={local}').strip() == '0'
                  and harness.sql(f'SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id={local}').strip() == '0',
                  'installed owned aggregate graph cleanup uses actual API')
            if (identifier, local) == created[0]:
                before = graph_snapshot(harness)
                # This branch rejects stale graph identities at the authorization
                # boundary, redirecting to the list before the editor is entered.
                log = harness.command('cat', '/var/www/html/log/cacti.log')['stdout']
                with session.opener.open(session.base + f'/graphs.php?action=graph_edit&id={local}', timeout=30) as response:
                    status = response.status
                    destination = response.geturl()
                    body = response.read().decode('utf-8', errors='strict')
                after_log = harness.command('cat', '/var/www/html/log/cacti.log')['stdout']
                check(status == 200 and destination == session.base + '/graphs.php'
                      and 'Graph not found.  Either it has been deleted or your database needs repair.' not in body
                      and after_log.startswith(log)
                      and after_log[len(log):].count('User attempted to access an unauthorized graph') == 1
                      and graph_snapshot(harness) == before,
                      'installed deleted graph editor refuses stale identity without mutation')
            harness.sql(f'DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id={identifier};'
                        f'DELETE FROM aggregate_graphs_items WHERE aggregate_graph_id={identifier};'
                        f'DELETE FROM aggregate_graphs WHERE id={identifier};')
        if host is not None:
            result = harness.php('cli/remove_device.php', '--id=' + str(host), '--confirm')
            check(result['exit'] == 0 and harness.sql(
                f'SELECT COUNT(*) FROM host WHERE id={host}').strip() == '0',
                'installed owned graph member device cleanup completes')


def verify_aggregate_graph_save(harness, session, graph, color, check):
    identifier, local = graph

    def edit():
        status, _, form = request(session, f'/aggregate_graphs.php?action=edit&tab=details&id={local}')
        check(status == 200 and form.get('save_component_graph') == '1'
              and form.get('local_graph_id') == str(local) and '__csrf_magic' in form,
              'installed aggregate graph edit uses actual persisted graph controls')
        form['action'] = 'save'
        return form

    form = edit()
    form['gprint_prefix'] = 'Owned HTTP saved'
    status, body, _ = request(session, '/aggregate_graphs.php', form)
    check(status == 200 and 'regeneration failed' not in body
          and harness.sql(f'SELECT gprint_prefix FROM aggregate_graphs WHERE id={identifier}').strip()
          == 'Owned HTTP saved'
          and harness.sql(f'SELECT COUNT(*) FROM aggregate_graphs_items WHERE aggregate_graph_id={identifier}').strip() == '1',
          'installed aggregate graph editor saves actual rendered settings and member identity')
    form = edit()
    field = next(name for name in form if name.startswith('agg_color_'))
    malformed = dict(form)
    del malformed[field]
    malformed[field + '[nested]'] = '2'
    before = graph_snapshot(harness)
    status, body, _ = request(session, '/aggregate_graphs.php', malformed)
    check(status == 200 and 'settings could not be confirmed' in body and graph_snapshot(harness) == before,
          'installed aggregate graph malformed request refuses before all nine participant writes')
    form = edit()
    form['title_format'] = 'Owned HTTP saved before cache refusal'
    form[field] = str(color)
    cache_before = harness.sql(f'SELECT * FROM aggregate_graphs_graph_item WHERE '
                               f'aggregate_graph_id={identifier} ORDER BY graph_templates_item_id')
    harness.sql("CREATE TRIGGER owned_http_graph_cache_refusal BEFORE INSERT ON aggregate_graphs_graph_item "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP graph cache refusal'")
    try:
        status, body, _ = request(session, '/aggregate_graphs.php', form)
        check(status == 200 and 'Aggregate items could not be saved. Other graph settings may already have been saved' in body
              and harness.sql(f'SELECT * FROM aggregate_graphs_graph_item WHERE aggregate_graph_id={identifier} '
                              'ORDER BY graph_templates_item_id') == cache_before
              and harness.sql(f'SELECT title_format FROM aggregate_graphs WHERE id={identifier}').strip()
              == 'Owned HTTP saved before cache refusal',
              'installed aggregate graph cache refusal preserves cache and reports saved title')
    finally:
        harness.sql('DROP TRIGGER owned_http_graph_cache_refusal')
    form = edit()
    form['gprint_prefix'] = 'Owned HTTP saved before regeneration refusal'
    items_before = harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={local} ORDER BY id')
    harness.sql(f"DELIMITER $$\nCREATE TRIGGER owned_http_graph_save_refusal BEFORE INSERT ON graph_templates_item "
                f"FOR EACH ROW BEGIN IF NEW.local_graph_id={local} THEN SIGNAL SQLSTATE '45000' "
                "SET MESSAGE_TEXT='Owned HTTP graph save refusal'; END IF; END$$\nDELIMITER ;")
    try:
        status, body, _ = request(session, '/aggregate_graphs.php', form)
        check(status == 200 and 'regeneration failed. Other graph settings may already have been saved' in body
              and harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={local} ORDER BY id') == items_before
              and harness.sql(f'SELECT gprint_prefix FROM aggregate_graphs WHERE id={identifier}').strip()
              == 'Owned HTTP saved before regeneration refusal',
              'installed aggregate graph regeneration refusal preserves generated items and reports saved settings')
    finally:
        harness.sql('DROP TRIGGER owned_http_graph_save_refusal')


def verify_color_cohorts(harness, session, aggregate, color, created, check):
    """Exercise both real synchronization cohorts and the documented partial outcome."""
    standalone = created[0][0]
    other_color = color + 1
    harness.sql(f"INSERT INTO color_templates(color_template_id,name) VALUES ({other_color},'Owned HTTP unrelated color');"
                f'UPDATE aggregate_graphs_graph_item SET color_template={other_color} '
                f'WHERE aggregate_graph_id={created[2][0]};')
    unrelated_before = harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[2][1]} ORDER BY id')
    harness.sql(f'INSERT INTO color_template_items(color_template_id,color_id,sequence) '
                f'VALUES ({color},4,1);'
                f'UPDATE aggregate_graph_templates_item SET color_template={color} '
                f'WHERE aggregate_template_id={aggregate};'
                f'UPDATE aggregate_graphs_graph_item SET color_template={color} '
                f'WHERE aggregate_graph_id={standalone};')
    status, body, _ = bulk(session, '/color_templates.php', 3, color, check, enabled=False)
    check(status == 200 and 'had 1 Aggregate Templates pushed out and 1 Non-Templated Aggregates' in body
          and all(int(harness.sql(f'SELECT COUNT(*) FROM graph_templates_item WHERE '
                                 f'local_graph_id={local} AND color_id=4').strip()) > 0
                  for _, local in created[:2])
          and harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[2][1]} ORDER BY id') == unrelated_before,
          'installed color synchronization regenerates actual template and standalone cohorts')
    before_standalone = harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[0][1]} ORDER BY id')
    before_template = harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[1][1]} ORDER BY id')
    harness.sql(f"DELIMITER $$\nCREATE TRIGGER owned_http_color_refusal BEFORE INSERT ON graph_templates_item "
                f"FOR EACH ROW BEGIN IF NEW.local_graph_id={created[0][1]} THEN "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP second cohort refusal'; END IF; END$$\nDELIMITER ;")
    try:
        status, body, _ = bulk(session, '/color_templates.php', 3, color, check, enabled=False)
        check(status == 200 and 'synchronization failed. Some aggregates may already have been updated' in body
              and harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[0][1]} ORDER BY id') == before_standalone
              and harness.sql(f'SELECT * FROM graph_templates_item WHERE local_graph_id={created[1][1]} ORDER BY id') != before_template,
              'installed color second cohort refusal preserves failed graph and reports earlier committed cohort')
    finally:
        harness.sql('DROP TRIGGER owned_http_color_refusal')
    # A same-name checked discovery must fail, rather than report a legitimately unused template.
    form = confirmation(session, '/color_templates.php', 3, color, check, enabled=False)
    harness.sql('RENAME TABLE aggregate_graph_templates_item TO owned_http_template_cache')
    try:
        status, body, _ = request(session, '/color_templates.php', form)
        check(status == 200 and 'synchronization failed' in body
              and 'had no Aggregate Templates or Graphs' not in body,
              'installed color discovery failure reports refusal instead of empty usage')
    finally:
        harness.sql('RENAME TABLE owned_http_template_cache TO aggregate_graph_templates_item')


def verify_cdef_legacy_pages(harness, session, check):
    """Use only this disposable installed stack; leave no surviving owned records."""
    cdef = 15000901
    dependent = 15000902
    color = 15000901
    aggregate = 15000901
    copies = []
    check(harness.sql(f'SELECT COUNT(*) FROM cdef WHERE id IN ({cdef},{dependent})').strip() == '0'
          and harness.sql(f'SELECT COUNT(*) FROM aggregate_graph_templates WHERE id={aggregate}').strip() == '0'
          and harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id IN ({color},{color+1})').strip() == '0',
          'installed legacy page fixture identities are exclusively unused')
    try:
        for page in ('/cdef.php', '/aggregate_templates.php', '/color_templates.php',
                     '/graphs.php', '/aggregate_graphs.php'):
            status, body, _ = request(session, page)
            check(status == 200 and 'login_username' not in body,
                  'installed legacy page authenticates real cookie: ' + page)
        harness.sql(f"INSERT INTO cdef(id,hash,name) VALUES ({cdef},REPEAT('a',32),'Owned HTTP CDEF'),"
                    f"({dependent},REPEAT('b',32),'Owned HTTP reference');"
                    f"INSERT INTO cdef_items(hash,cdef_id,sequence,type,value) VALUES "
                    f"(REPEAT('c',32),{cdef},1,6,'2'),(REPEAT('d',32),{dependent},1,5,'{cdef}');")
        status, body, _ = request(session, f'/cdef.php?action=edit&id={cdef}')
        check(status == 200 and 'Owned HTTP CDEF' in body and 'Item #1' in body,
              'installed CDEF editor renders actual persisted child')
        status, _, form = request(session, '/cdef.php', {
            'action': 'actions', 'drp_action': '2', 'chk_' + str(cdef): 'on',
            '__csrf_magic': session.token,
        })
        check(status == 200 and 'selected_items' in form,
              'installed CDEF access control uses an actual duplication confirmation')
        verify_write_access(harness, session, '/cdef.php', form, 14,
                            lambda: graph_snapshot(harness), check)
        status, _, _ = bulk(session, '/cdef.php', 2, cdef, check,
                            {'title_format': '<cdef_title> HTTP copy'})
        rows = harness.rows("SELECT JSON_OBJECT('id',id,'name',name) FROM cdef "
                            "WHERE name='Owned HTTP CDEF HTTP copy'")
        copies = [int(row['id']) for row in rows]
        check(status == 200 and len(copies) == 1 and int(harness.sql(
            f'SELECT COUNT(*) FROM cdef_items WHERE cdef_id={copies[0]}').strip()) == 1,
            'installed CDEF duplication preserves persisted parent and children')
        before = harness.sql(f'SELECT id,cdef_id,sequence,type,value FROM cdef_items '
                             f'WHERE cdef_id IN ({cdef},{dependent}) ORDER BY id')
        status, body, _ = bulk(session, '/cdef.php', 1, cdef, check)
        check(status == 200 and 'CDEF deletion could not be confirmed' in body
              and harness.sql(f'SELECT id,cdef_id,sequence,type,value FROM cdef_items '
                              f'WHERE cdef_id IN ({cdef},{dependent}) ORDER BY id') == before
              and int(harness.sql(f'SELECT COUNT(*) FROM cdef WHERE id={cdef}').strip()) == 1,
              'installed CDEF referenced deletion refuses and preserves actual records')
        status, _, _ = bulk(session, '/cdef.php', 1, copies[0], check)
        check(status == 200 and int(harness.sql(
            f'SELECT COUNT(*) FROM cdef WHERE id={copies[0]}').strip()) == 0
            and int(harness.sql(f'SELECT COUNT(*) FROM cdef_items WHERE cdef_id={copies[0]}').strip()) == 0,
            'installed CDEF unused deletion removes actual parent and children')
        original_before = [harness.sql(f'SELECT * FROM cdef WHERE id={cdef}'),
                           harness.sql(f'SELECT * FROM cdef_items WHERE cdef_id={cdef} ORDER BY id')]
        form = confirmation(session, '/cdef.php', 2, cdef, check,
                            {'title_format': '<cdef_title> failed HTTP copy'})
        harness.sql("CREATE TRIGGER owned_http_cdef_copy_refusal BEFORE INSERT ON cdef_items "
                    "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP CDEF child refusal'")
        try:
            status, body, _ = request(session, '/cdef.php', form)
            partial = harness.rows("SELECT JSON_OBJECT('id',id,'name',name) FROM cdef "
                                   "WHERE name='Owned HTTP CDEF failed HTTP copy'")
            copies.extend(int(row['id']) for row in partial)
            check(status == 200 and 'CDEF duplication could not be confirmed. A partial copy may remain' in body
                  and len(partial) == 1 and harness.sql(
                      f"SELECT COUNT(*) FROM cdef_items WHERE cdef_id={partial[0]['id']}").strip() == '0'
                  and [harness.sql(f'SELECT * FROM cdef WHERE id={cdef}'),
                       harness.sql(f'SELECT * FROM cdef_items WHERE cdef_id={cdef} ORDER BY id')] == original_before,
                  'installed CDEF child copy refusal reports exact partial parent and preserves original definition')
        finally:
            harness.sql('DROP TRIGGER owned_http_cdef_copy_refusal')
        graph_template = 4
        check(int(harness.sql('SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id=0 '
                              'AND graph_template_id=4').strip()) > 1,
              'installed graph template fixture uses actual member-compatible items')
        harness.sql(f"INSERT INTO aggregate_graph_templates(id,name,graph_template_id,gprint_prefix,"
                    f"graph_type,total,total_type,total_prefix,order_type,user_id) VALUES "
                    f"({aggregate},'Owned HTTP aggregate',{graph_template},'',1,0,0,'',0,1);"
                    f"INSERT INTO aggregate_graph_templates_graph(aggregate_template_id) VALUES ({aggregate});")
        status, body, form = request(session, f'/aggregate_templates.php?action=edit&id={aggregate}')
        check(status == 200 and form.get('id') == str(aggregate)
              and form.get('save_component_template') == '1' and '__csrf_magic' in form,
              'installed aggregate template renders genuine editable controls')
        form['action'] = 'save'
        # The user includes the rendered items instead of retaining "Skip".
        for name in tuple(form):
            if name.startswith('agg_skip_'):
                del form[name]
        status, body, _ = request(session, '/aggregate_templates.php', form)
        check(status == 200 and int(harness.sql(
            f'SELECT COUNT(*) FROM aggregate_graph_templates_item WHERE aggregate_template_id={aggregate}').strip()) > 0,
            'installed aggregate template save persists rendered graph item cache')
        check(harness.sql(f"SELECT COUNT(*) FROM aggregate_graph_templates_item WHERE "
                          f"aggregate_template_id={aggregate} AND item_skip='on'").strip() == '0',
              'installed aggregate template persists the user inclusion choice')
        harness.sql(f"INSERT INTO color_templates(color_template_id,name) VALUES ({color},'Owned HTTP color');")
        status, body, _ = bulk(session, '/color_templates.php', 3, color, check)
        check(status == 200 and 'had no Aggregate Templates or Graphs' in body,
              'installed color synchronization reports genuine empty usage')
        status, _, failed_cache = request(session, f'/aggregate_templates.php?action=edit&id={aggregate}')
        failed_cache['action'] = 'save'
        failed_cache['name'] = 'Owned HTTP cache refusal'
        field = next(name for name in failed_cache if name.startswith('agg_color_'))
        failed_cache[field] = str(color)
        cache_before = harness.sql(f'SELECT * FROM aggregate_graph_templates_item WHERE '
                                   f'aggregate_template_id={aggregate} ORDER BY graph_templates_item_id')
        harness.sql("CREATE TRIGGER owned_http_cache_refusal BEFORE INSERT ON aggregate_graph_templates_item "
                    "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned HTTP cache refusal'")
        try:
            status, body, _ = request(session, '/aggregate_templates.php', failed_cache)
            check(status == 200 and 'Aggregate items could not be saved. Other graph settings may already have been saved' in body
                  and harness.sql(f'SELECT * FROM aggregate_graph_templates_item WHERE aggregate_template_id={aggregate} '
                                  'ORDER BY graph_templates_item_id') == cache_before
                  and harness.sql(f'SELECT name FROM aggregate_graph_templates WHERE id={aggregate}').strip()
                  == 'Owned HTTP cache refusal',
                  'installed aggregate cache refusal preserves cache and reports independently saved metadata')
        finally:
            harness.sql('DROP TRIGGER owned_http_cache_refusal')
        verify_graph_creation(harness, session, aggregate, color, check)
        status, _, form = request(session, f'/aggregate_templates.php?action=edit&id={aggregate}')
        malformed = dict(form)
        malformed['action'] = 'save'
        item_field = next(name for name in form if name.startswith('agg_color_'))
        del malformed[item_field]
        malformed[item_field + '[nested]'] = '2'
        before = template_snapshot(harness, aggregate)
        status, body, _ = request(session, '/aggregate_templates.php', malformed)
        check(status == 200 and 'settings could not be confirmed' in body
              and template_snapshot(harness, aggregate) == before,
              'installed aggregate malformed item request refuses before metadata writes')
        check(int(harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id={color}').strip()) == 1,
              'installed color synchronization preserves actual color identity')
    finally:
        harness.sql(f'DELETE FROM aggregate_graph_templates_item WHERE aggregate_template_id={aggregate};'
                    f'DELETE FROM aggregate_graph_templates_graph WHERE aggregate_template_id={aggregate};'
                    f'DELETE FROM aggregate_graph_templates WHERE id={aggregate};'
                    f'DELETE FROM color_template_items WHERE color_template_id IN ({color},{color+1});'
                    f'DELETE FROM color_templates WHERE color_template_id IN ({color},{color+1});'
                    f'DELETE FROM cdef_items WHERE cdef_id IN ({cdef},{dependent});'
                    f'DELETE FROM cdef WHERE id IN ({dependent},{cdef});')
        for identifier in copies:
            harness.sql(f'DELETE FROM cdef_items WHERE cdef_id={identifier};'
                        f'DELETE FROM cdef WHERE id={identifier};')
    check(harness.sql(f'SELECT COUNT(*) FROM cdef WHERE id IN ({cdef},{dependent},'
                      + ','.join(str(identifier) for identifier in copies) + ')').strip() == '0'
          and harness.sql(f'SELECT COUNT(*) FROM aggregate_graph_templates WHERE id={aggregate}').strip() == '0'
          and harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id IN ({color},{color+1})').strip() == '0',
          'installed legacy CDEF aggregate and color page scenarios completed cleanup')

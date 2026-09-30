"""Color template list, forms, item order, actions and graph-color handoff over HTTP."""
import json
import re
from html.parser import HTMLParser
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request

from device_edit_scenarios import Inputs


class FormIds(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.forms = []

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if 'id' in values:
            self.ids.append(values['id'])
        if tag == 'form':
            self.forms.append((values.get('id', ''), values.get('action', '')))


def verify_color_templates(harness, session, user_id, check):
    marker = 'symfony-color-' + str(int(harness.sql('SELECT COALESCE(MAX(color_template_id),0)+100 FROM color_templates').strip()))
    template_ids = []
    item_ids = []
    aggregate_fixture_id = None
    aggregate_graph_id = None
    aggregate_local_graph_id = None
    aggregate_trigger = None
    member_graph_ids = []
    source_template_item_id = None
    realm_rows = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5').strip()
    realm_added = False
    pref_rows = harness.sql(f"SELECT COUNT(*) FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'").strip()
    pref = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'").rstrip('\n')
    locale_rows = harness.sql(f"SELECT COUNT(*) FROM settings_user WHERE user_id={user_id} AND name='user_language'").strip()
    locale_pref = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='user_language'").rstrip('\n')
    group_grants = [int(value) for value in harness.sql(
        f"SELECT m.group_id FROM user_auth_group_members m JOIN user_auth_group_realm r ON r.group_id=m.group_id "
        f"JOIN user_auth_group g ON g.id=m.group_id WHERE m.user_id={user_id} AND r.realm_id=5 AND g.enabled='on'"
    ).splitlines() if value]

    def fetch(path, fields=None, origin=True):
        headers = {'Origin': harness.base} if origin else {}
        data = None if fields is None else urlencode(fields, doseq=True).encode()
        request = Request(harness.base + path, data=data, headers=headers)
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8', errors='replace'), response.url, response.headers

    def parse_form(path):
        status, body, _, headers = fetch(path)
        check(status == 200, 'Color template form GET succeeds: ' + path)
        check('no-store' in headers.get('Cache-Control', ''), 'Color template form is not cached')
        parser = Inputs()
        parser.feed(body)
        return parser, body

    def save_action(action, ids):
        query = [('action', action)] + [('ids[]', str(value)) for value in ids]
        path = '/app.php/graphing/color-templates/actions?' + urlencode(query)
        parser, body = parse_form(path)
        return path, parser, body

    try:
        if realm_rows == '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            realm_added = True
        status, body, _, _ = fetch('/app.php/graphing/color-templates')
        check(status == 200 and 'Color Templates' in body, 'realm holder sees Symfony color template list')
        harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','fr')")
        locale_status, localized_body, _, _ = fetch('/app.php/graphing/color-templates')
        check(locale_status == 200 and 'Modèles de couleurs' in localized_body,
              'color-template route selects the authenticated user’s French locale')
        harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='user_language'")
        if locale_rows != '0':
            escaped_locale = locale_pref.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','{escaped_locale}')")

        create_path = '/app.php/graphing/color-templates/new'
        parser, _ = parse_form(create_path)
        check('color_template[revision]' in parser.fields, 'template editor carries opaque revision')
        create_fields = parser.fields | {'color_template[name]': marker + ' <template>'}
        check(fetch(create_path, {key: value for key, value in create_fields.items() if key != 'color_template[_token]'})[0] == 422,
              'template creation requires a CSRF token')
        check(fetch(create_path, create_fields, origin=False)[0] == 422,
              'template creation rejects a missing same-origin signal')
        status, body, location, _ = fetch(create_path, create_fields)
        check(status == 200 and 'Color template saved.' in body,
              'new color template saves and redirects to its editor')
        match = re.search(r'/graphing/color-templates/([1-9][0-9]*)/edit', location)
        check(match is not None, 'new template returns a fixed edit URL')
        template_id = int(match.group(1))
        template_ids.append(template_id)
        check('&lt;template&gt;' in body and '<template>' not in body,
              'template name is escaped by Twig')

        edit_path = f'/app.php/graphing/color-templates/{template_id}/edit'
        parser, _ = parse_form(edit_path)
        stale = parser.fields | {'color_template[name]': 'stale overwrite'}
        harness.sql(f"UPDATE color_templates SET name='{marker} changed concurrently' WHERE color_template_id={template_id}")
        status, _, _, _ = fetch(edit_path, stale)
        check(status == 409 and harness.sql(f'SELECT name FROM color_templates WHERE color_template_id={template_id}').strip() == marker + ' changed concurrently',
              'stale template revision cannot overwrite a concurrent edit')
        parser, _ = parse_form(edit_path)
        status, body, _, _ = fetch(edit_path, parser.fields | {'color_template[name]': marker + ' <template>'})
        check(status == 200 and 'Color template saved.' in body, 'fresh template edit succeeds')

        item_path = f'/app.php/graphing/color-templates/{template_id}/items/new'
        parser, item_body = parse_form(item_path)
        check('color_template_item[color_template_id]' not in parser.fields and 'color_template_item[color_id]' in item_body,
              'item editor exposes only a color choice and the fixed parent route')
        options = re.findall(r'<option value="([1-9][0-9]*)"', item_body)
        check(len(options) >= 2, 'item editor offers database-backed palette colors')
        color_one, color_two = int(options[0]), int(options[1])
        item_fields = parser.fields | {'color_template_item[color_id]': '999999'}
        status, body, _, _ = fetch(item_path, item_fields)
        check(status == 422, 'item creation rejects a color outside the rendered palette choices')
        item_fields['color_template_item[color_id]'] = str(color_one)
        status, _, location, _ = fetch(item_path, item_fields)
        check(status == 200 and f'/graphing/color-templates/{template_id}/edit' in location,
              'color item saves and returns to its parent editor')
        first_item = int(harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={template_id}').strip())
        item_ids.append(first_item)

        parser, fresh_item_body = parse_form(item_path)
        fresh_color_ids = [int(value) for value in re.findall(r'<option value="([1-9][0-9]*)"', fresh_item_body)]
        color_two = next(value for value in fresh_color_ids if value != color_one)
        second_fields = parser.fields | {'color_template_item[color_id]': str(color_two)}
        status, second_body, second_url, _ = fetch(item_path, second_fields)
        if status >= 500:
            runtime_logs = harness.compose('logs', '--no-color', '--tail=120', 'web', timeout=15)['stdout']
            safe_trace = []
            for line in runtime_logs.splitlines():
                fatal = re.search(r'Uncaught ([A-Za-z0-9_\\]+).*? in /var/www/html/([^:]+):([0-9]+)', line)
                frame = re.search(r'#([0-9]+) /var/www/html/([^:]+):([0-9]+)', line)
                if fatal:
                    safe_trace.append(f'{fatal.group(1)} in {fatal.group(2)}:{fatal.group(3)}')
                elif frame:
                    safe_trace.append(f'#{frame.group(1)} {frame.group(2)}:{frame.group(3)}')
            second_body = repr(safe_trace[-20:]) + ' ' + second_body
        check(status == 200, f'second palette color saves as the next sequence item (HTTP {status}, final={second_url}, submitted={second_fields.get("color_template_item[color_id]")}, available={fresh_color_ids[:5]}, {second_body[:3000]})')
        second_item = int(harness.sql(f'SELECT MAX(color_template_item_id) FROM color_template_items WHERE color_template_id={template_id}').strip())
        item_ids.append(second_item)
        sequence = [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(sequence == [color_one, color_two], 'database sequence is the ordered color handoff consumed by aggregate graph generation')
        check(int(harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence LIMIT 1,1').strip()) == color_two,
              'aggregate graph selection at the second item index receives the second stored palette color')

        editor_status, editor, _, _ = fetch(edit_path)
        check(editor_status == 200 and 'Move up' in editor and 'Move down' in editor,
              'item editor provides authenticated sequence controls')
        form_ids = FormIds()
        form_ids.feed(editor)
        check(len(form_ids.ids) == len(set(form_ids.ids)), 'item-order form and field IDs are unique on the editor route: ' + repr([value for value in set(form_ids.ids) if form_ids.ids.count(value) > 1]))
        order_form_ids = [form_id for form_id, _ in form_ids.forms if form_id.startswith(f'color_template_order_{template_id}_')]
        check(order_form_ids != [] and all(action.endswith(f'/graphing/color-templates/{template_id}/items/order') for form_id, action in form_ids.forms if form_id in order_form_ids),
              'item-order forms carry IDs and actions for their selected template route')
        order_parser = Inputs()
        order_parser.feed(editor)
        order_name = next(name.split('[', 1)[0] for name in order_parser.fields if name.endswith('[order]'))
        order_fields = {
            f'{order_name}[_token]': order_parser.fields[f'{order_name}[_token]'],
            f'{order_name}[order]': json.dumps([second_item, first_item]),
            f'{order_name}[revision]': order_parser.fields[f'{order_name}[revision]'],
        }
        order_path = f'/app.php/graphing/color-templates/{template_id}/items/order'
        status, _, _, _ = fetch(order_path, order_fields)
        check(status == 200, 'same-origin protected item ordering request succeeds')
        ordered = [int(value) for value in harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(ordered == [second_item, first_item], 'reorder updates all item sequences atomically')
        refreshed_editor_status, refreshed_editor, _, _ = fetch(edit_path)
        refreshed_order = Inputs()
        refreshed_order.feed(refreshed_editor)
        refreshed_order_name = next(name.split('[', 1)[0] for name in refreshed_order.fields if name.endswith('[revision]'))
        stale_revision = refreshed_order.fields[f'{refreshed_order_name}[revision]']
        harness.sql(f'UPDATE color_template_items SET sequence=3 WHERE color_template_item_id={first_item}; UPDATE color_template_items SET sequence=1 WHERE color_template_item_id={second_item}; UPDATE color_template_items SET sequence=2 WHERE color_template_item_id={first_item}')
        stale_fields = order_fields | {f'{order_name}[order]': json.dumps([second_item, first_item]), f'{order_name}[revision]': stale_revision}
        stale_status, _, _, _ = fetch(order_path, stale_fields)
        concurrently_ordered = [int(value) for value in harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(refreshed_editor_status == 200 and stale_status == 409 and concurrently_ordered == [second_item, first_item],
              'stale reorder with identical item IDs cannot overwrite a concurrent sequence change')

        duplicate_path, duplicate_form, _ = save_action('duplicate', [template_id])
        duplicate_fields = duplicate_form.fields | {'color_template_action[title_format]': '<template_title> Copy'}
        status, _, location, _ = fetch(duplicate_path, duplicate_fields)
        check(status == 200 and 'duplicated=1' in location, 'duplicate action preserves the legacy title-format substitution')
        duplicate_id = int(harness.sql(f"SELECT color_template_id FROM color_templates WHERE name='{marker} &lt;template&gt; Copy' ORDER BY color_template_id DESC LIMIT 1").strip()) if False else int(harness.sql(f"SELECT MAX(color_template_id) FROM color_templates WHERE name LIKE '{marker}%' AND color_template_id<>{template_id}").strip())
        template_ids.append(duplicate_id)
        copied_colors = [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={duplicate_id} ORDER BY sequence').splitlines()]
        check(copied_colors == [color_two, color_one], 'duplicate copies palette data and exact item order')

        copied_item = int(harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={duplicate_id} ORDER BY sequence LIMIT 1').strip())
        copied_path = f'/app.php/graphing/color-templates/{duplicate_id}/items/{copied_item}/delete'
        delete_parser, _ = parse_form(copied_path)
        check(fetch(copied_path, delete_parser.fields, origin=False)[0] == 422,
              'color item deletion requires same-origin CSRF evidence')
        missing_token = {key: value for key, value in delete_parser.fields.items() if not key.endswith('[_token]')}
        check(fetch(copied_path, missing_token)[0] == 422, 'color item deletion requires a CSRF token')
        current_color = int(harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_item_id={copied_item}').strip())
        alternate_color = color_one if current_color != color_one else color_two
        harness.sql(f'UPDATE color_template_items SET color_id={alternate_color} WHERE color_template_item_id={copied_item}')
        check(fetch(copied_path, delete_parser.fields)[0] == 409,
              'color item deletion rejects a stale confirmation revision')
        fresh_delete, _ = parse_form(copied_path)
        check(fetch(copied_path, fresh_delete.fields)[0] == 200,
              'color item deletion succeeds through the protected Symfony form')
        check(harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_item_id={copied_item}').strip() == '0',
              'color item deletion reaches persisted palette data')
        check(harness.sql(f'SELECT GROUP_CONCAT(sequence ORDER BY sequence) FROM color_template_items WHERE color_template_id={duplicate_id}').strip() == '2',
              'color item deletion preserves the surviving legacy sequence value')
        check(fetch(copied_path)[0] == 404, 'deleted color item editor returns not found')

        for payload, expected in [
            ({'actor': user_id, 'template_id': template_id, 'unexpected': 'field'}, 'invalid'),
            ({'actor': 99999999, 'template_id': template_id}, 'denied'),
            ({'actor': user_id, 'template_id': 99999999}, 'invalid'),
        ]:
            worker = harness.compose('exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/legacy-color-template-sync.php',
                                     data=json.dumps(payload), check=False)
            result = re.search(r'KADUPUL_COLOR_SYNC_RESULT=(\{[^\r\n]+\})', worker['stdout'])
            check(worker['exit'] != 0 and result is not None and json.loads(result.group(1))['status'] == expected,
                  'color sync worker rejects ' + expected + ' command before any graph handoff')

        graph_template_id = int(harness.sql('SELECT graph_template_id FROM graph_templates_graph WHERE local_graph_id=0 AND graph_template_id>0 AND graph_template_id IN (SELECT graph_template_id FROM graph_templates_item WHERE local_graph_id=0) ORDER BY graph_template_id LIMIT 1').strip())
        source_template_item_id = int(harness.sql(f'SELECT id FROM graph_templates_item WHERE local_graph_id=0 AND graph_template_id={graph_template_id} ORDER BY sequence LIMIT 1').strip())
        aggregate_fixture_id = int(harness.sql(
            f"INSERT INTO aggregate_graph_templates (name,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,user_id) "
            f"VALUES ('{marker} aggregate', {graph_template_id}, '', '', 0, 0, 0, '', 1, {user_id}); SELECT LAST_INSERT_ID()"
        ).strip())
        harness.sql(f'INSERT INTO aggregate_graph_templates_graph (aggregate_template_id) VALUES ({aggregate_fixture_id})')
        harness.sql(
            f"INSERT INTO aggregate_graph_templates_item (aggregate_template_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) "
            f"VALUES ({aggregate_fixture_id},{source_template_item_id},1,{template_id},'',0,'',NULL,'','')"
        )
        for _ in range(2):
            member = int(harness.sql(
                f'INSERT INTO graph_local (graph_template_id,host_id) VALUES ({graph_template_id},0); SELECT LAST_INSERT_ID()'
            ).strip())
            member_graph_ids.append(member)
            harness.sql(
                f'INSERT INTO graph_templates_item (local_graph_template_item_id,local_graph_id,graph_template_id,color_id,graph_type_id,sequence) '
                f'SELECT id,{member},{graph_template_id},color_id,graph_type_id,sequence FROM graph_templates_item '
                f'WHERE id={source_template_item_id} AND local_graph_id=0'
            )
        aggregate_local_graph_id = int(harness.sql('INSERT INTO graph_local (graph_template_id,host_id) VALUES (0,0); SELECT LAST_INSERT_ID()').strip())
        aggregate_graph_id = int(harness.sql(
            f"INSERT INTO aggregate_graphs (aggregate_template_id,template_propogation,local_graph_id,title_format,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,user_id) "
            f"VALUES ({aggregate_fixture_id},'',{aggregate_local_graph_id},'{marker} aggregate graph',{graph_template_id},'', '',0,0,0,'',1,{user_id}); SELECT LAST_INSERT_ID()"
        ).strip())
        for index, member in enumerate(member_graph_ids, 1):
            harness.sql(f'INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id,sequence) VALUES ({aggregate_graph_id},{member},{index})')
        harness.sql(
            f"INSERT INTO aggregate_graphs_graph_item (aggregate_graph_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) "
            f"VALUES ({aggregate_graph_id},{source_template_item_id},1,{template_id},'',0,'',NULL,'','')"
        )
        sync_path, sync_form, _ = save_action('sync', [template_id])
        before_nontransactional_check = [int(value) for value in harness.sql(f'SELECT color_id FROM graph_templates_item WHERE local_graph_id={aggregate_local_graph_id} ORDER BY sequence').splitlines()]
        harness.sql('ALTER TABLE aggregate_graphs_items ENGINE=MyISAM')
        myisam_status, _, _, _ = fetch(sync_path, sync_form.fields)
        after_nontransactional_check = [int(value) for value in harness.sql(f'SELECT color_id FROM graph_templates_item WHERE local_graph_id={aggregate_local_graph_id} ORDER BY sequence').splitlines()]
        check(myisam_status == 502 and after_nontransactional_check == before_nontransactional_check,
              'sync rejects a MyISAM table in its write set before changing aggregate graph data')
        harness.sql('ALTER TABLE aggregate_graphs_items ENGINE=InnoDB')
        status, body, location, _ = fetch(sync_path, sync_form.fields)
        worker_logs = harness.compose('logs', '--no-color', '--tail=40', 'web')['stdout'] if status != 200 else ''
        sql_failure = harness.command('sh', '-c', "grep -F 'Failed!, Error: 1064' /var/www/html/log/cacti.log | tail -3")['stdout'] if status != 200 else ''
        check(status == 200 and 'synced=1' in location,
              f'sync action invokes the isolated legacy aggregate graph handoff worker (HTTP {status}, {location}, {body[:300]}, logs={worker_logs[-1200:]}, sql={sql_failure[-2000:]})')
        propagated = [int(value) for value in harness.sql(f'SELECT color_id FROM graph_templates_item WHERE local_graph_id={aggregate_local_graph_id} ORDER BY sequence').splitlines()]
        persisted_sequence = [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(propagated == persisted_sequence,
              f'sync propagates each member graph color using its zero-based position in the persisted color-template sequence (expected {persisted_sequence}, got {propagated})')

        before_failure = propagated
        harness.sql(f'UPDATE color_template_items SET color_id={color_one} WHERE color_template_id={template_id} AND sequence=1; UPDATE color_template_items SET color_id={color_two} WHERE color_template_id={template_id} AND sequence=2')
        aggregate_trigger = 'symfony_color_sync_fail_' + str(aggregate_fixture_id)
        harness.sql(f"CREATE TRIGGER {aggregate_trigger} BEFORE INSERT ON graph_templates_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional test propagation failure'")
        _, failure_form, _ = save_action('sync', [template_id])
        failure_status, _, _, _ = fetch(sync_path, failure_form.fields)
        after_failure = [int(value) for value in harness.sql(f'SELECT color_id FROM graph_templates_item WHERE local_graph_id={aggregate_local_graph_id} ORDER BY sequence').splitlines()]
        check(failure_status == 502 and after_failure == before_failure,
              f'failed legacy graph-color propagation returns an uncertain outcome and rolls back the prior aggregate graph rows (HTTP {failure_status}, before {before_failure}, after {after_failure})')
        harness.sql(f'DROP TRIGGER {aggregate_trigger}')
        aggregate_trigger = None

        harness.sql(f'UPDATE aggregate_graphs SET aggregate_template_id=0 WHERE id={aggregate_graph_id}; UPDATE aggregate_graphs_graph_item SET color_template={duplicate_id} WHERE aggregate_graph_id={aggregate_graph_id} AND graph_templates_item_id={source_template_item_id}')
        direct_graph_delete_path, direct_graph_delete_form, direct_graph_delete_body = save_action('delete', [duplicate_id])
        check('cannot be deleted' in direct_graph_delete_body, 'standalone aggregate graph reference blocks deletion in confirmation')
        direct_graph_delete_status, _, _, _ = fetch(direct_graph_delete_path, direct_graph_delete_form.fields)
        check(direct_graph_delete_status == 422 and harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id={duplicate_id}').strip() == '1',
              'delete rechecks standalone aggregate graph references at write time')
        harness.sql(f'UPDATE aggregate_graphs_graph_item SET color_template={template_id} WHERE aggregate_graph_id={aggregate_graph_id} AND graph_templates_item_id={source_template_item_id}; UPDATE aggregate_graphs SET aggregate_template_id={aggregate_fixture_id} WHERE id={aggregate_graph_id}')
        delete_path, delete_form, _ = save_action('delete', [duplicate_id])
        status, _, location, _ = fetch(delete_path, delete_form.fields)
        check(status == 200 and 'deleted=1' in location, 'unused color template and its items are deleted together')
        template_ids.remove(duplicate_id)

        used_path, used_form, used_body = save_action('delete', [template_id])
        check('cannot be deleted' in used_body, 'referenced template action is explicitly denied in its confirmation')
        status, _, _, _ = fetch(used_path, used_form.fields)
        check(status == 422 and harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id={template_id}').strip() == '1',
              'delete handler rechecks aggregate template references at write time')

        check(fetch('/color_templates.php?action=template_edit&color_template_id=' + str(template_id))[0] == 200,
              'legacy template editor URL forwards to Symfony')
        check(fetch('/color_templates_items.php?action=item_edit&color_template_id=' + str(template_id))[0] == 200,
              'legacy item editor URL forwards to Symfony')
        legacy_before = harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_id={template_id}').strip()
        check(fetch('/color_templates_items.php?color_template_id=' + str(template_id), {'action':'item_remove', 'color_id': str(first_item)})[0] == 409, 'legacy item POST expires without dispatching deletion')
        check(harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_id={template_id}').strip() == legacy_before, 'legacy item POST changes no palette rows')
        check(fetch('/color_templates.php', {'action': 'actions'})[0] == 409,
              'legacy POST mutation is expired without replay')

        harness.sql(f"UPDATE user_auth SET must_change_password='on' WHERE id={user_id}")
        check(fetch('/app.php/graphing/color-templates')[0] == 403,
              'database forced-password flag alone denies color-template access despite the existing session actor')
        harness.sql(f"UPDATE user_auth SET must_change_password='' WHERE id={user_id}")
        check(fetch('/app.php/graphing/color-templates')[0] == 200,
              'clearing forced-password policy restores authorized color-template access')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5')
        try:
            if group_grants:
                for group_id in group_grants:
                    harness.sql(f'DELETE FROM user_auth_group_members WHERE group_id={group_id} AND user_id={user_id}')
            check(fetch('/app.php/graphing/color-templates')[0] == 403,
                  'session without color template realm is denied')
        finally:
            harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            for group_id in group_grants:
                harness.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id})')
    finally:
        if aggregate_trigger is not None:
            harness.sql(f'DROP TRIGGER IF EXISTS {aggregate_trigger}')
        if aggregate_graph_id is not None:
            harness.sql(f'DELETE FROM aggregate_graphs_items WHERE aggregate_graph_id={aggregate_graph_id}; DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id={aggregate_graph_id}; DELETE FROM aggregate_graphs WHERE id={aggregate_graph_id}')
        if aggregate_local_graph_id is not None:
            harness.sql(f'DELETE FROM graph_templates_item WHERE local_graph_id={aggregate_local_graph_id}; DELETE FROM graph_templates_graph WHERE local_graph_id={aggregate_local_graph_id}; DELETE FROM graph_local WHERE id={aggregate_local_graph_id}')
        for member in member_graph_ids:
            harness.sql(f'DELETE FROM graph_templates_item WHERE local_graph_id={member}; DELETE FROM graph_templates_graph WHERE local_graph_id={member}; DELETE FROM graph_local WHERE id={member}')
        if aggregate_fixture_id is not None:
            harness.sql(f'DELETE FROM aggregate_graph_templates_item WHERE aggregate_template_id={aggregate_fixture_id}; DELETE FROM aggregate_graph_templates_graph WHERE aggregate_template_id={aggregate_fixture_id}; DELETE FROM aggregate_graph_templates WHERE id={aggregate_fixture_id}')
        for item in item_ids:
            harness.sql(f'DELETE FROM color_template_items WHERE color_template_item_id={item}')
        for template_id in template_ids:
            harness.sql(f'DELETE FROM color_template_items WHERE color_template_id={template_id}; DELETE FROM color_templates WHERE color_template_id={template_id}')
        if pref_rows == '0':
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'")
        else:
            escaped = pref.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'color_templates_filters','{escaped}')")
        if locale_rows == '0':
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='user_language'")
        else:
            escaped_locale = locale_pref.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','{escaped_locale}')")
    print('Color template HTTP checks passed.', flush=True)

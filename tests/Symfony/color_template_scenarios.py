# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Color template forms, items, actions, aggregate sync and legacy routes over HTTP."""
import re
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request

from device_edit_scenarios import Inputs


def verify_color_templates(harness, session, user_id, check):
    marker = 'symfony-color-' + str(int(harness.sql('SELECT COALESCE(MAX(color_template_id),0)+100 FROM color_templates').strip()))
    template_ids = []
    members = []
    aggregate_template = None
    aggregate_graph = None
    aggregate_local = None
    trigger = None
    realm_row = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5').strip()
    grant_added = False
    prior_preference_rows = harness.sql(f"SELECT COUNT(*) FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'").strip()
    prior_preference = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'").rstrip('\n')
    group_grants = [int(group_id) for group_id in harness.sql(
        f"SELECT m.group_id FROM user_auth_group_members m JOIN user_auth_group_realm r ON r.group_id=m.group_id "
        f"JOIN user_auth_group g ON g.id=m.group_id WHERE m.user_id={user_id} AND r.realm_id=5 AND g.enabled='on'"
    ).splitlines() if group_id]
    colors = [int(value) for value in harness.sql('SELECT id FROM colors ORDER BY id LIMIT 3').splitlines()]

    def fetch(path, fields=None, origin=True):
        headers = {'Origin': harness.base} if origin else {}
        request = Request(harness.base + path, data=None if fields is None else urlencode(fields).encode(), headers=headers)
        try:
            response = session.opener.open(request, timeout=180)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8'), response.url, response.headers

    def form(path, token_field):
        status, body, _, headers = fetch(path)
        check(status == 200 and 'no-store' in headers.get('Cache-Control', ''), 'color template form GET succeeds uncached: ' + path)
        parser = Inputs()
        parser.feed(body)
        check(token_field in parser.fields, 'color template form includes CSRF token: ' + token_field)
        return parser.fields, body

    def action(name, ids):
        path = '/app.php/graphing/color-templates/actions?' + urlencode([('action', name)] + [('ids[]', str(value)) for value in ids])
        fields, body = form(path, 'color_template_action[_token]')
        return path, fields, body

    def query(location):
        # path() encodes spaces as %20, so compare the decoded query.
        return {key: values[0] for key, values in parse_qs(urlsplit(location).query).items()}

    def graph_colors(local_graph):
        return [int(value) for value in harness.sql(f'SELECT color_id FROM graph_templates_item WHERE local_graph_id={local_graph} ORDER BY sequence').splitlines()]

    def copy_graph_parameters(local_id, graph_template_id):
        # Copy the installed template's own parameters rather than schema defaults.
        names = [row.split('\t')[0] for row in harness.sql('SHOW COLUMNS FROM graph_templates_graph').splitlines() if row.split('\t')[0] != 'id']
        expressions = [str(local_id) if name == 'local_graph_id' else 'id' if name == 'local_graph_template_graph_id'
                       else "'" + marker + " graph'" if name in ('title', 'title_cache') else '`' + name + '`' for name in names]
        harness.sql('INSERT INTO graph_templates_graph (' + ','.join('`' + name + '`' for name in names) + ') SELECT '
                    + ','.join(expressions) + f' FROM graph_templates_graph WHERE local_graph_id=0 AND graph_template_id={graph_template_id} LIMIT 1')

    try:
        if realm_row == '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            grant_added = True
        status, body, _, _ = fetch('/app.php/graphing/color-templates')
        check(status == 200 and 'Color Templates' in body, f'color template realm holder sees the Symfony list (HTTP {status})')
        # Characters, not bytes, bound the search (PR 659 review).
        check(fetch('/app.php/graphing/color-templates?' + urlencode({'filter': '界' * 200}))[0] == 200
              and fetch('/app.php/graphing/color-templates?' + urlencode({'filter': '界' * 201}))[0] == 400,
              'color template search accepts 200 characters and rejects 201')
        fetch('/app.php/graphing/color-templates?reset=1')

        create_path = '/app.php/graphing/color-templates/new'
        fields, _ = form(create_path, 'color_template[_token]')
        fields = fields | {'color_template[name]': marker + ' <name>'}
        check(fetch(create_path, fields, origin=False)[0] == 422, 'color template creation requires same-origin CSRF evidence')
        status, body, location, _ = fetch(create_path, fields)
        match = re.search(r'/graphing/color-templates/([1-9][0-9]*)/edit', location)
        check(status == 200 and match is not None and query(location).get('saved') == '1'
              and '&lt;name&gt;' in body and marker + ' <name>' not in body,
              'color template creation redirects to its escaped editor')
        template_id = int(match.group(1))
        template_ids.append(template_id)
        check(harness.sql(f'SELECT name FROM color_templates WHERE color_template_id={template_id}').strip() == marker + ' <name>',
              'color template names are stored as entered')

        edit_path = f'/app.php/graphing/color-templates/{template_id}/edit'
        fields, _ = form(edit_path, 'color_template[_token]')
        harness.sql(f"UPDATE color_templates SET name='{marker} concurrent' WHERE color_template_id={template_id}")
        check(fetch(edit_path, fields | {'color_template[name]': 'stale'})[0] == 409
              and harness.sql(f'SELECT name FROM color_templates WHERE color_template_id={template_id}').strip() == marker + ' concurrent',
              'a stale color template edit is refused without overwriting')
        fields, _ = form(edit_path, 'color_template[_token]')
        check(fetch(edit_path, fields | {'color_template[name]': marker + ' ' + 'é' * 200})[0] == 200
              and harness.sql(f'SELECT CHAR_LENGTH(name) FROM color_templates WHERE color_template_id={template_id}').strip() == str(len(marker) + 201),
              'multibyte color template names are measured in characters')

        item_path = f'/app.php/graphing/color-templates/{template_id}/items/new'
        for color in colors[:2]:
            fields, _ = form(item_path, 'color_template_item[_token]')
            check(fetch(item_path, fields | {'color_template_item[color_id]': str(color)})[0] == 200,
                  'a color item is added through its form')
        items = [int(value) for value in harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(len(items) == 2 and harness.sql(f'SELECT GROUP_CONCAT(sequence ORDER BY sequence) FROM color_template_items WHERE color_template_id={template_id}').strip() == '1,2',
              'new color items take the next sequence')
        fields, _ = form(edit_path, 'color_template_order[_token]')
        order = {key: value for key, value in fields.items() if key.startswith('color_template_order[')}
        harness.sql(f'UPDATE color_template_items SET sequence=sequence+10 WHERE color_template_item_id={items[0]}')
        check(fetch(f'/app.php/graphing/color-templates/{template_id}/items/order', order | {'color_template_order[moveDown]': str(items[0])})[0] == 409,
              'a stale item order is refused')
        harness.sql(f'UPDATE color_template_items SET sequence=sequence-10 WHERE color_template_item_id={items[0]}')
        fields, _ = form(edit_path, 'color_template_order[_token]')
        order = {key: value for key, value in fields.items() if key.startswith('color_template_order[')}
        status, _, _, _ = fetch(f'/app.php/graphing/color-templates/{template_id}/items/order', order | {'color_template_order[moveDown]': str(items[0])})
        check(status == 200 and [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()] == [colors[1], colors[0]],
              'moving an item down swaps the stored color order')
        fields, _ = form(f'/app.php/graphing/color-templates/{template_id}/items/new', 'color_template_item[_token]')
        fetch(item_path, fields | {'color_template_item[color_id]': str(colors[2])})
        third = int(harness.sql(f'SELECT MAX(color_template_item_id) FROM color_template_items WHERE color_template_id={template_id}').strip())
        delete_path = f'/app.php/graphing/color-templates/{template_id}/items/{third}/delete'
        fields, _ = form(delete_path, 'color_template_item_delete[_token]')
        check(fetch(delete_path, fields)[0] == 200
              and harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_item_id={third}').strip() == '0',
              'a confirmed color item is removed')

        # An aggregate template and a templated aggregate graph without propagation both use the template.
        graph_template_id = int(harness.sql('SELECT graph_template_id FROM graph_templates_graph WHERE local_graph_id=0 AND graph_template_id>0 AND graph_template_id IN (SELECT graph_template_id FROM graph_templates_item WHERE local_graph_id=0) ORDER BY graph_template_id LIMIT 1').strip())
        source_item = int(harness.sql(f'SELECT id FROM graph_templates_item WHERE local_graph_id=0 AND graph_template_id={graph_template_id} ORDER BY sequence LIMIT 1').strip())
        aggregate_template = int(harness.sql(
            "INSERT INTO aggregate_graph_templates (name,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,user_id) "
            f"VALUES ('{marker} aggregate',{graph_template_id},'','',0,0,0,'',1,{user_id}); SELECT LAST_INSERT_ID()").strip())
        harness.sql(f'INSERT INTO aggregate_graph_templates_graph (aggregate_template_id) VALUES ({aggregate_template})')
        harness.sql('INSERT INTO aggregate_graph_templates_item (aggregate_template_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) '
                    f"VALUES ({aggregate_template},{source_item},1,{template_id},'',0,'',NULL,'','')")
        for _ in range(2):
            member = int(harness.sql(f'INSERT INTO graph_local (graph_template_id,host_id) VALUES ({graph_template_id},0); SELECT LAST_INSERT_ID()').strip())
            members.append(member)
            copy_graph_parameters(member, graph_template_id)
            harness.sql('INSERT INTO graph_templates_item (local_graph_template_item_id,local_graph_id,graph_template_id,color_id,graph_type_id,sequence) '
                        f'SELECT id,{member},{graph_template_id},color_id,graph_type_id,sequence FROM graph_templates_item WHERE id={source_item}')
        aggregate_local = int(harness.sql('INSERT INTO graph_local (graph_template_id,host_id) VALUES (0,0); SELECT LAST_INSERT_ID()').strip())
        copy_graph_parameters(aggregate_local, graph_template_id)
        aggregate_graph = int(harness.sql(
            'INSERT INTO aggregate_graphs (aggregate_template_id,template_propogation,local_graph_id,title_format,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,user_id) '
            f"VALUES ({aggregate_template},'',{aggregate_local},'{marker} aggregate graph',{graph_template_id},'','',0,0,0,'',1,{user_id}); SELECT LAST_INSERT_ID()").strip())
        for index, member in enumerate(members, 1):
            harness.sql(f'INSERT INTO aggregate_graphs_items (aggregate_graph_id,local_graph_id,sequence) VALUES ({aggregate_graph},{member},{index})')
        harness.sql('INSERT INTO aggregate_graphs_graph_item (aggregate_graph_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) '
                    f"VALUES ({aggregate_graph},{source_item},1,{template_id},'',0,'',NULL,'','')")

        status, body, _, _ = fetch('/app.php/graphing/color-templates?' + urlencode({'filter': marker}))
        selectable = re.search(r'<input\b[^>]*name="ids\[\]"[^>]*value="' + str(template_id) + r'"[^>]*>', body)
        check(status == 200 and selectable is not None and 'disabled' not in selectable.group(0),
              'referenced color templates stay selectable for duplicate and sync')

        sync_path, sync_form, _ = action('sync', [template_id])
        status, body, _, _ = fetch(sync_path, sync_form)
        stored = [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        # Pushing the aggregate template turns propagation on for its graphs, so
        # the graph is not counted again as non-templated, as in the legacy action.
        check(status == 200 and 'had 1 Aggregate Templates pushed out and 0 Non-Templated Aggregates pushed out' in body
              and graph_colors(aggregate_local) == stored,
              f'sync pushes the stored color order through the aggregate template (HTTP {status}, {graph_colors(aggregate_local)} vs {stored})')

        before = graph_colors(aggregate_local)
        harness.sql(f'UPDATE color_template_items SET color_id={colors[2]} WHERE color_template_id={template_id}')
        trigger = 'symfony_color_sync_fail_' + str(aggregate_template)
        harness.sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON graph_templates_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional color sync failure'")
        sync_path, sync_form, _ = action('sync', [template_id])
        status, body, _, _ = fetch(sync_path, sync_form)
        check(status == 502 and 'Some aggregates may already have been updated' in body and graph_colors(aggregate_local) == before,
              'a failed sync rolls back the whole template and reports an uncertain outcome')
        harness.sql(f'DROP TRIGGER {trigger}')
        trigger = None

        sync_path, sync_form, _ = action('sync', [template_id])
        harness.sql(f'UPDATE color_template_items SET color_id={colors[0]} WHERE color_template_id={template_id}')
        check(fetch(sync_path, sync_form)[0] == 409 and graph_colors(aggregate_local) == before,
              'a sync confirmed before the colors changed is refused')
        # Detach the graph from its template, whose push replaced the graph's own
        # item rows. push_out_aggregates() fails for a template with no graphs,
        # as the legacy action did, so the template stops using this palette.
        harness.sql(f"UPDATE aggregate_graph_templates_item SET color_template=0 WHERE aggregate_template_id={aggregate_template}; "
                    f"UPDATE aggregate_graphs SET aggregate_template_id=0, template_propogation='' WHERE id={aggregate_graph}; "
                    f'DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id={aggregate_graph}; '
                    'INSERT INTO aggregate_graphs_graph_item (aggregate_graph_id,graph_templates_item_id,sequence,color_template,t_graph_type_id,graph_type_id,t_cdef_id,cdef_id,item_skip,item_total) '
                    f"VALUES ({aggregate_graph},{source_item},1,{template_id},'',0,'',NULL,'','')")
        sync_path, sync_form, _ = action('sync', [template_id])
        status, body, _, _ = fetch(sync_path, sync_form)
        stored = [int(value) for value in harness.sql(f'SELECT color_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        check(status == 200 and 'had 0 Aggregate Templates pushed out and 1 Non-Templated Aggregates pushed out' in body
              and graph_colors(aggregate_local) == stored and stored != before,
              f'sync also pushes the stored colors into a non-templated aggregate graph (HTTP {status}, {graph_colors(aggregate_local)} vs {stored})')
        harness.sql(f'UPDATE aggregate_graph_templates_item SET color_template={template_id} WHERE aggregate_template_id={aggregate_template}')

        delete_path, delete_form, body = action('delete', [template_id])
        check('cannot be deleted' in body and 'used by 1 aggregate graph items and 1 aggregate template items' in body,
              'delete confirmation names both aggregate reference kinds')
        check(fetch(delete_path, delete_form)[0] == 422
              and harness.sql(f'SELECT COUNT(*) FROM color_templates WHERE color_template_id={template_id}').strip() == '1',
              'the server refuses to delete a referenced color template')
        remaining = [int(value) for value in harness.sql(f'SELECT color_template_item_id FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence').splitlines()]
        for item in remaining[:-1]:
            item_delete = f'/app.php/graphing/color-templates/{template_id}/items/{item}/delete'
            fields, _ = form(item_delete, 'color_template_item_delete[_token]')
            fetch(item_delete, fields)
        item_delete = f'/app.php/graphing/color-templates/{template_id}/items/{remaining[-1]}/delete'
        fields, _ = form(item_delete, 'color_template_item_delete[_token]')
        status, body, _, _ = fetch(item_delete, fields)
        check(status == 409 and 'must keep at least one color' in body
              and harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_id={template_id}').strip() == '1',
              'the last color of a referenced color template cannot be removed')

        duplicate_path, duplicate_form, _ = action('duplicate', [template_id])
        harness.sql(f"UPDATE color_templates SET name='{marker} renamed' WHERE color_template_id={template_id}")
        check(fetch(duplicate_path, duplicate_form)[0] == 409
              and harness.sql(f"SELECT COUNT(*) FROM color_templates WHERE name LIKE '{marker} renamed%'").strip() == '1',
              'a duplicate confirmed before a rename is refused (PR 659 review)')
        duplicate_path, duplicate_form, _ = action('duplicate', [template_id])
        status, _, location, _ = fetch(duplicate_path, duplicate_form | {'color_template_action[title_format]': 'Copy of <template_title>'})
        copy = harness.sql(f"SELECT color_template_id FROM color_templates WHERE name='Copy of {marker} renamed'").strip()
        check(status == 200 and query(location).get('duplicated') == '1' and copy != ''
              and harness.sql(f'SELECT GROUP_CONCAT(color_id ORDER BY sequence) FROM color_template_items WHERE color_template_id={copy}')
              == harness.sql(f'SELECT GROUP_CONCAT(color_id ORDER BY sequence) FROM color_template_items WHERE color_template_id={template_id}'),
              'duplication copies the items under the submitted title format')
        template_ids.append(int(copy))
        delete_path, delete_form, _ = action('delete', [int(copy)])
        check(fetch(delete_path, delete_form)[0] == 200
              and harness.sql(f'SELECT COUNT(*) FROM color_template_items WHERE color_template_id={copy}').strip() == '0',
              'an unused color template is deleted with its items')

        status, _, location, _ = fetch(f'/color_templates.php?action=template_edit&color_template_id={template_id}')
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/color-templates/{template_id}/edit'),
              'the legacy editor URL forwards to the Symfony editor')
        status, _, location, _ = fetch(f'/color_templates_items.php?action=item_edit&color_template_id={template_id}&color_template_item_id={items[0]}')
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/color-templates/{template_id}/items/{items[0]}/edit'),
              'the legacy item editor URL forwards to the Symfony item editor')
        before_items = harness.sql(f'SELECT * FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence')
        check(fetch('/color_templates_items.php?action=item_remove', {'id': str(template_id), 'color_id': str(items[0])})[0] == 409
              and fetch('/color_templates.php', {'action': 'actions', 'drp_action': '1'})[0] == 409
              and harness.sql(f'SELECT * FROM color_template_items WHERE color_template_id={template_id} ORDER BY sequence') == before_items,
              'posted legacy color template forms expire without changes')
        check(fetch('/bin/legacy-color-template-sync.php')[0] in (403, 404), 'the sync worker is not reachable over HTTP')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5')
        try:
            if group_grants:
                harness.sql(f"DELETE FROM user_auth_group_members WHERE user_id={user_id} AND group_id IN ({','.join(map(str, group_grants))})")
            check(fetch('/app.php/graphing/color-templates')[0] == 403 and fetch('/color_templates.php')[0] == 403,
                  'a console session without realm 5 cannot open color templates')
        finally:
            if realm_row != '0' or grant_added:
                harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            for group_id in group_grants:
                harness.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id})')
    finally:
        if trigger is not None:
            harness.sql(f'DROP TRIGGER IF EXISTS {trigger}')
        if aggregate_graph is not None:
            harness.sql(f'DELETE FROM aggregate_graphs_items WHERE aggregate_graph_id={aggregate_graph}; DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id={aggregate_graph}; DELETE FROM aggregate_graphs WHERE id={aggregate_graph}')
        for local in [aggregate_local] + members:
            if local is not None:
                harness.sql(f'DELETE FROM graph_templates_item WHERE local_graph_id={local}; DELETE FROM graph_templates_graph WHERE local_graph_id={local}; DELETE FROM graph_local WHERE id={local}')
        if aggregate_template is not None:
            harness.sql(f'DELETE FROM aggregate_graph_templates_item WHERE aggregate_template_id={aggregate_template}; DELETE FROM aggregate_graph_templates_graph WHERE aggregate_template_id={aggregate_template}; DELETE FROM aggregate_graph_templates WHERE id={aggregate_template}')
        for ident in template_ids:
            harness.sql(f'DELETE FROM color_template_items WHERE color_template_id={ident}; DELETE FROM color_templates WHERE color_template_id={ident}')
        if grant_added:
            harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5')
        if prior_preference_rows == '0':
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='color_templates_filters'")
        else:
            escaped_preference = prior_preference.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'color_templates_filters','{escaped_preference}')")
    print('Color template HTTP checks passed.', flush=True)

"""GPRINT preset form, authorization, reference and legacy route checks over HTTP."""
import re
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request

from device_edit_scenarios import Inputs


def verify_gprint_presets(harness, session, user_id, check):
    marker = 'symfony-gprint-' + str(int(harness.sql('SELECT COALESCE(MAX(id),0)+100 FROM graph_templates_gprint').strip()))
    ids = []
    refs = []
    realm_row = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5').strip()
    grant_added = False
    prior_preference_rows = harness.sql(f"SELECT COUNT(*) FROM settings_user WHERE user_id={user_id} AND name='gprint_presets_filters'").strip()
    prior_preference = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='gprint_presets_filters'").rstrip('\n')
    group_grants = [int(group_id) for group_id in harness.sql(
        f"SELECT m.group_id FROM user_auth_group_members m JOIN user_auth_group_realm r ON r.group_id=m.group_id "
        f"JOIN user_auth_group g ON g.id=m.group_id WHERE m.user_id={user_id} AND r.realm_id=5 AND g.enabled='on'"
    ).splitlines() if group_id]

    def fetch(path, fields=None, origin=True):
        headers = {'Origin': harness.base} if origin else {}
        request = Request(harness.base + path, data=None if fields is None else urlencode(fields).encode(), headers=headers)
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8'), response.url, response.headers

    def form(path, token_field='gprint_preset[_token]'):
        status, body, _, headers = fetch(path)
        check(status == 200, 'GPRINT form GET succeeds: ' + path)
        check('no-store' in headers.get('Cache-Control', ''), 'GPRINT form response is not cached')
        parser = Inputs()
        parser.feed(body)
        check(token_field in parser.fields, 'GPRINT form includes CSRF token')
        return parser, body

    try:
        if realm_row == '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            grant_added = True
        status, body, _, _ = fetch('/app.php/graphing/gprint-presets')
        check(status == 200 and 'GPRINT Presets' in body, f'GPRINT realm holder can view Symfony preset list (HTTP {status}: {body[:300]!r})')

        create_path = '/app.php/graphing/gprint-presets/new'
        parser, create_body = form(create_path)
        check('gprint_preset[revision]' in parser.fields, 'new GPRINT preset form has revision field')
        name = marker + ' <name>'
        text = '%5.2lf<format>'
        fields = parser.fields | {'gprint_preset[name]': name, 'gprint_preset[gprint_text]': text}
        check(fetch(create_path, fields, origin=False)[0] == 422,
              'GPRINT creation requires same-origin CSRF evidence')
        check(fetch(create_path, {key: value for key, value in fields.items() if key != 'gprint_preset[_token]'})[0] == 422,
              'GPRINT creation requires a CSRF token')
        status, body, location, _ = fetch(create_path, fields)
        check(status == 200 and 'saved=1' in location,
              'GPRINT creation redirects to the saved preset')
        match = re.search(r'/graphing/gprint-presets/([1-9][0-9]*)/edit', location)
        check(match is not None, 'GPRINT creation returns a fixed edit URL')
        created_id = int(match.group(1))
        ids.append(created_id)
        check('&lt;name&gt;' in body and '<name>' not in body,
              'saved preset names are escaped in Twig')
        check(harness.sql(f'SELECT gprint_text FROM graph_templates_gprint WHERE id={created_id}').strip() == text,
              'preset format handoff preserves literal RRD format text')

        edit_path = f'/app.php/graphing/gprint-presets/{created_id}/edit'
        parser, _ = form(edit_path)
        check(fetch(edit_path + '?saved%5B%5D=1')[0] == 200,
              'nested GPRINT saved flag cannot trigger an uncontrolled error')
        old_revision = parser.fields['gprint_preset[revision]']
        harness.sql(f"UPDATE graph_templates_gprint SET name='concurrent edit' WHERE id={created_id}")
        stale = parser.fields | {'gprint_preset[name]': 'stale overwrite'}
        status, _, _, _ = fetch(edit_path, stale)
        check(status == 409 and harness.sql(f'SELECT name FROM graph_templates_gprint WHERE id={created_id}').strip() == 'concurrent edit',
              'concurrent preset change rejects stale form without overwriting it')
        check(old_revision != '', 'revision is an opaque nonempty value')
        parser, _ = form(edit_path)
        updated = parser.fields | {'gprint_preset[name]': 'fresh <name>', 'gprint_preset[gprint_text]': '%9.3lf'}
        status, body, _, _ = fetch(edit_path, updated)
        check(status == 200 and 'GPRINT Preset saved.' in body, 'fresh preset edit saves and confirms')

        status, body, _, _ = fetch('/app.php/graphing/gprint-presets?' + urlencode({'filter': 'fresh <name>'}))
        check(status == 200 and 'fresh &lt;name&gt;' in body,
              f'preset search reaches Twig without interpreting user text as markup (HTTP {status}: {body[:500]!r})')
        status, remembered, _, _ = fetch('/app.php/graphing/gprint-presets')
        check(status == 200 and 'fresh &lt;name&gt;' in remembered,
              'validated GPRINT filters persist in the authenticated user preferences')
        status, reset, _, _ = fetch('/app.php/graphing/gprint-presets?reset=1')
        status, explicit_rows, _, _ = fetch('/app.php/graphing/gprint-presets?rows=10')
        check(status == 200 and re.search(r'<option value="10" selected>', explicit_rows) is not None,
              'GPRINT explicit page size stays selected in Twig')
        reset_filter = re.search(r'id="gprint-filter" name="filter" type="search" maxlength="200" value="([^"]*)"', reset)
        check(status == 200 and reset_filter is not None and reset_filter.group(1) == '',
              'clearing GPRINT filters resets the remembered session state')

        used_hash = format(int(marker.rsplit('-', 1)[1]), '032x')
        used_id = int(harness.sql(f"INSERT INTO graph_templates_gprint (name,gprint_text,hash) VALUES ('{marker}-used','%8.2lf','{used_hash}'); SELECT LAST_INSERT_ID()").strip())
        ids.append(used_id)
        ref_id = int(harness.sql(f'INSERT INTO graph_templates_item (graph_template_id,local_graph_id,gprint_id) VALUES (0,900001,{used_id}); SELECT LAST_INSERT_ID()').strip())
        refs.append(ref_id)
        delete_path = '/app.php/graphing/gprint-presets/actions/delete?' + urlencode([('ids[]', str(used_id))])
        parser, body = form(delete_path, 'gprint_preset_delete[_token]')
        check('cannot be deleted' in body and 'gprint_preset_delete[selection]' in parser.fields,
              'delete confirmation shows graph references and selection token')
        status, _, _, _ = fetch(delete_path, parser.fields)
        check(status == 422 and harness.sql(f'SELECT COUNT(*) FROM graph_templates_gprint WHERE id={used_id}').strip() == '1',
              'server rechecks references and refuses a forged deletion')

        unused_path = '/app.php/graphing/gprint-presets/actions/delete?' + urlencode([('ids[]', str(created_id))])
        parser, _ = form(unused_path, 'gprint_preset_delete[_token]')
        status, _, location, _ = fetch(unused_path, parser.fields)
        check(status == 200 and harness.sql(f'SELECT COUNT(*) FROM graph_templates_gprint WHERE id={created_id}').strip() == '0',
              'confirmed unreferenced preset is deleted')

        status, _, legacy_url, _ = fetch('/gprint_presets.php?action=edit&id=' + str(used_id))
        check(status == 200 and f'/graphing/gprint-presets/{used_id}/edit' in legacy_url,
              'legacy edit URL forwards to its Symfony edit route')
        check(fetch('/gprint_presets.php?action=edit&id=0')[0] == 200,
              'legacy create URL forwards to Symfony create form')
        check(fetch('/gprint_presets.php', {'action': 'actions'})[0] == 409,
              'legacy POST form expires without replaying mutation')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5')
        try:
            if group_grants:
                harness.sql(f"DELETE FROM user_auth_group_members WHERE user_id={user_id} AND group_id IN ({','.join(map(str, group_grants))})")
            check(fetch('/app.php/graphing/gprint-presets')[0] == 403,
                  'console session without realm 5 cannot view GPRINT presets')
        finally:
            if realm_row != '0':
                harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
                grant_added = False
            elif grant_added:
                harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},5)')
            for group_id in group_grants:
                harness.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id})')
    finally:
        for ident in refs:
            harness.sql(f'DELETE FROM graph_templates_item WHERE id={ident}')
        for ident in ids:
            harness.sql(f'DELETE FROM graph_templates_gprint WHERE id={ident}')
        if grant_added:
            harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=5')
        if prior_preference_rows == '0':
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='gprint_presets_filters'")
        else:
            escaped_preference = prior_preference.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'gprint_presets_filters','{escaped_preference}')")
    print('GPRINT preset HTTP checks passed.', flush=True)

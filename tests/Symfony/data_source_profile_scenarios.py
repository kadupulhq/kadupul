# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Data source profile forms, actions and legacy routes over the installed HTTP path."""
import html
import json
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request

from device_edit_scenarios import Inputs

BASE = '/app.php/graphing/data-source-profiles'


class ProfileClient:
    """Fetch Symfony profile pages and submit their forms as a browser would."""

    def __init__(self, harness, session, check):
        self.harness = harness
        self.session = session
        self.check = check

    def fetch(self, path, fields=None, origin=True):
        headers = {'Origin': self.harness.base} if origin else {}
        data = None if fields is None else urlencode(fields, doseq=True).encode()
        request = Request(self.harness.base + path, data=data, headers=headers)
        try:
            response = self.session.opener.open(request, timeout=60)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8', errors='replace'), response.url, response.headers

    def form(self, path, token_field):
        status, body, _, headers = self.fetch(path)
        self.check(status == 200 and 'no-store' in headers.get('Cache-Control', ''), 'profile form GET succeeds uncached: ' + path)
        parser = Inputs()
        parser.feed(body)
        self.check(token_field in parser.fields, 'profile form includes CSRF token: ' + token_field)
        return parser.fields, html.unescape(parser.action or path), body

    def action(self, operation, ids):
        path = f'{BASE}/actions/{operation}?' + urlencode([('ids[]', str(value)) for value in ids])
        fields, target, body = self.form(path, 'data_source_profile_action[_token]')
        return target, {key: value for key, value in fields.items() if key.startswith('data_source_profile_action[')}, body

    def profile_fields(self, profile_id, **changes):
        """The editor's token, revision and name, plus only the fields named, as a browser omits disabled ones."""
        fields, target, _ = self.form(f'{BASE}/{profile_id}/edit', 'data_source_profile[_token]')
        submitted = {key: fields[key] for key in ('data_source_profile[_token]', 'data_source_profile[revision]', 'data_source_profile[name]')}
        for name, value in changes.items():
            key = 'data_source_profile[consolidation_function_id][]' if name == 'functions' else f'data_source_profile[{name}]'
            submitted[key] = value
        return target, submitted


def create_profile(harness, label, step=300, heartbeat=600, functions=(1,), rows=10):
    name_hex = label.encode().hex().upper()
    profile_id = int(harness.sql(
        "INSERT INTO data_source_profiles (hash,name,step,heartbeat,x_files_factor) "
        f"VALUES (MD5(RAND()),CONVERT(UNHEX('{name_hex}') USING utf8mb4),{step},{heartbeat},0.5); SELECT LAST_INSERT_ID()"
    ).strip())
    rra_id = int(harness.sql(
        "INSERT INTO data_source_profiles_rra (data_source_profile_id,name,steps,`rows`,timespan) "
        f"VALUES ({profile_id},'fixture',1,{rows},86400); SELECT LAST_INSERT_ID()"
    ).strip())
    for function in functions:
        harness.sql(f'INSERT INTO data_source_profiles_cf VALUES ({profile_id},{function})')
    return profile_id, rra_id


def drop_profiles(harness, profile_ids):
    for profile_id in profile_ids:
        harness.sql(f'DELETE FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}; '
                    f'DELETE FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id}; '
                    f'DELETE FROM data_source_profiles WHERE id={profile_id}')


def verify_data_source_profile_deletion(harness, session, check):
    client = ProfileClient(harness, session, check)
    profiles = {}
    data_local_ids = []
    template_ids = []

    def create(label):
        profiles[label] = create_profile(harness, 'profile-delete-' + label)
        return profiles[label][0]

    def reference(profile_id, local_source):
        local_data_id = 0
        if local_source:
            local_data_id = int(harness.sql('INSERT INTO data_local (host_id) VALUES (0); SELECT LAST_INSERT_ID()').strip())
            data_local_ids.append(local_data_id)
        template_hex = 'profile reference'.encode().hex().upper()
        template_ids.append(int(harness.sql(
            "INSERT INTO data_template_data (local_data_id,data_template_id,name,data_source_profile_id) "
            f"VALUES ({local_data_id},0,CONVERT(UNHEX('{template_hex}') USING utf8mb4),{profile_id}); SELECT LAST_INSERT_ID()"
        ).strip()))

    def present(label):
        profile_id, rra_id = profiles[label]
        return (harness.sql(f'SELECT COUNT(*) FROM data_source_profiles WHERE id={profile_id}').strip(),
                harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_rra WHERE id={rra_id}').strip(),
                harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id}').strip())

    try:
        template_profile = create('template')
        local_profile = create('local')
        late_profile = create('late')
        unused_profile = create('unused')
        reference(template_profile, False)
        reference(local_profile, True)
        status, _, location, _ = client.fetch(f'/data_source_profiles.php?action=edit&id={unused_profile}')
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/data-source-profiles/{unused_profile}/edit'),
              'the legacy profile URL forwards to the Symfony editor')

        target, fields, body = client.action('delete', [template_profile, local_profile, late_profile, unused_profile])
        check('can not be deleted' in body and 'used by 0 data sources and 1 templates' in body and 'used by 1 data sources and 0 templates' in body,
              'profile deletion confirmation page renders')
        check(client.fetch(target, fields)[0] == 422 and all(present(label) == ('1', '1', '1') for label in profiles),
              'a selection with a referenced profile deletes nothing')

        # The reference arrives after the confirmation, so the final write
        # must recheck live usage under the profile locks.
        target, fields, _ = client.action('delete', [late_profile, unused_profile])
        reference(late_profile, True)
        status, body, _, _ = client.fetch(target, fields)
        check(status == 422 and 'can not be deleted' in body and present('late') == ('1', '1', '1') and present('unused') == ('1', '1', '1'),
              'a reference added after confirmation refuses the whole deletion')

        target, fields, _ = client.action('delete', [unused_profile])
        status, _, location, _ = client.fetch(target, fields)
        check(status == 200 and urlsplit(location).path.endswith('/graphing/data-source-profiles') and present('unused') == ('0', '0', '0'),
              'unused profile is normally removable')

        default_profile = int(harness.sql("SELECT id FROM data_source_profiles WHERE `default`='on' LIMIT 1").strip())
        target, fields, body = client.action('delete', [default_profile])
        check('(default)' in body and client.fetch(target, fields)[0] == 422
              and harness.sql(f"SELECT `default` FROM data_source_profiles WHERE id={default_profile}").strip() == 'on',
              'the default profile cannot be deleted')
    finally:
        if template_ids:
            harness.sql('DELETE FROM data_template_data WHERE id IN (' + ','.join(map(str, template_ids)) + ')')
        for local_data_id in data_local_ids:
            harness.sql(f'DELETE FROM data_local WHERE id={local_data_id}')
        drop_profiles(harness, [profile_id for profile_id, _ in profiles.values()])


def verify_data_source_profiles(harness, session, user_id, check):
    """Editor, archives, duplication, legacy URLs, lookups and realm denial."""
    client = ProfileClient(harness, session, check)
    created = []
    realm_rows = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=9').strip()
    group_grants = [int(group_id) for group_id in harness.sql(
        f"SELECT m.group_id FROM user_auth_group_members m JOIN user_auth_group_realm r ON r.group_id=m.group_id "
        f"JOIN user_auth_group g ON g.id=m.group_id WHERE m.user_id={user_id} AND r.realm_id=9 AND g.enabled='on'"
    ).splitlines() if group_id]
    prior_preference_rows = harness.sql(f"SELECT COUNT(*) FROM settings_user WHERE user_id={user_id} AND name='data_source_profiles_filters'").strip()
    prior_preference = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='data_source_profiles_filters'").rstrip('\n')
    prior_default = harness.sql("SELECT id FROM data_source_profiles WHERE `default`='on'").strip()
    try:
        status, body, _, headers = client.fetch(BASE)
        check(status == 200 and 'Data Source Profiles' in body and 'no-store' in headers.get('Cache-Control', ''),
              'the profile list renders through Symfony')
        check(client.fetch(BASE + '?' + urlencode({'filter': 'é' * 200}))[0] == 200
              and client.fetch(BASE + '?' + urlencode({'filter': 'é' * 201}))[0] == 400
              and client.fetch(BASE + '?rows[]=1')[0] == 400,
              'profile filters are bounded and must be scalar')
        client.fetch(BASE + '?clear=1')

        create_path = BASE + '/new'
        fields, target, _ = client.form(create_path, 'data_source_profile[_token]')
        submitted = {
            'data_source_profile[_token]': fields['data_source_profile[_token]'],
            'data_source_profile[name]': 'symfony-profile <name>',
            'data_source_profile[step]': '60',
            'data_source_profile[heartbeat]': '120',
            'data_source_profile[x_files_factor]': '0.5',
            'data_source_profile[consolidation_function_id][]': ['1', '3'],
        }
        check(client.fetch(target, submitted, origin=False)[0] == 422
              and harness.sql("SELECT COUNT(*) FROM data_source_profiles WHERE name='symfony-profile <name>'").strip() == '0',
              'profile creation requires same-origin CSRF evidence')
        status, body, location, _ = client.fetch(target, submitted)
        profile_id = int(harness.sql("SELECT id FROM data_source_profiles WHERE name='symfony-profile <name>'").strip() or 0)
        created.append(profile_id)
        hash_value = harness.sql(f'SELECT hash FROM data_source_profiles WHERE id={profile_id}').strip()
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/data-source-profiles/{profile_id}/edit')
              and '&lt;name&gt;' in body and len(hash_value) == 32 and all(c in '0123456789abcdef' for c in hash_value),
              'a created profile gets a 32-digit hash and an escaped editor')

        for invalid, message in [({'x_files_factor': '1'}, 'X-Files Factor from 0'), ({'step': '45'}, 'choice'), ({'name': ''}, 'profile name')]:
            target, submitted = client.profile_fields(profile_id, **invalid)
            status, body, _, _ = client.fetch(target, submitted)
            check(status == 422 and message in body and harness.sql(f'SELECT step,x_files_factor,name FROM data_source_profiles WHERE id={profile_id}').strip() == '60\t0.5\tsymfony-profile <name>',
                  'an invalid profile field is refused without a write: ' + json.dumps(invalid))

        target, stale = client.profile_fields(profile_id, heartbeat='300')
        harness.sql(f"UPDATE data_source_profiles SET name='symfony-profile concurrent' WHERE id={profile_id}")
        check(client.fetch(target, stale)[0] == 409
              and harness.sql(f'SELECT heartbeat FROM data_source_profiles WHERE id={profile_id}').strip() == '120',
              'a stale profile form is refused')

        # Archives: the first takes one row per sample, later ones a coarser level.
        new_rra = f'{BASE}/{profile_id}/rras/new'
        fields, target, body = client.form(new_rra, 'data_source_profile_rra[_token]')
        check('value="1"' in body and 'value="300"' not in body, 'the first archive offers one row per sample only')
        archive = {key: fields[key] for key in ('data_source_profile_rra[_token]',)} | {
            'data_source_profile_rra[name]': 'raw', 'data_source_profile_rra[steps]': '1',
            'data_source_profile_rra[rows]': '1440', 'data_source_profile_rra[timespan]': '86400'}
        for rows in ('0', 'many', '4294967296'):
            check(client.fetch(target, archive | {'data_source_profile_rra[rows]': rows})[0] == 422,
                  'an invalid archive row count is refused: ' + rows)
        check(client.fetch(target, archive)[0] == 200
              and harness.sql(f'SELECT steps,`rows` FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}').strip() == '1\t1440',
              'the first archive stores one step per row')
        fields, target, body = client.form(new_rra, 'data_source_profile_rra[_token]')
        check('value="1"' not in body and 'value="300"' in body, 'a later archive offers coarser levels only')
        client.fetch(target, {'data_source_profile_rra[_token]': fields['data_source_profile_rra[_token]'], 'data_source_profile_rra[name]': 'hourly',
                              'data_source_profile_rra[steps]': '3600', 'data_source_profile_rra[rows]': '775', 'data_source_profile_rra[timespan]': '604800'})
        hourly = int(harness.sql(f"SELECT id FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id} AND name='hourly'").strip() or 0)
        check(harness.sql(f'SELECT steps FROM data_source_profiles_rra WHERE id={hourly}').strip() == '60',
              'an aggregation level is stored as steps of the polling interval')
        status, body, _, _ = client.fetch(f'{BASE}/{profile_id}/edit')
        check('24 Hours' in body and '1 Month, 2 Days, 7 Hours' in body and '36 KBytes per Data Sources and 284 Bytes for the Header' in body,
              'the editor shows each archive retention and the RRDfile size')

        # The size and span lookups the legacy form called still answer.
        lookups = {
            f'action=ajax_span&profile_id={profile_id}&span=1&rows=1440': '24 Hours',
            f'action=ajax_span&profile_id={profile_id}&span=3600&rows=775': '1 Month, 2 Days, 7 Hours',
            f'action=ajax_span&profile_id={profile_id}&span=1&rows=0': 'N/A',
            f'action=ajax_size&type=profile&id={profile_id}&cfs=': '36 KBytes per Data Sources and 284 Bytes for the Header',
            f'action=ajax_size&type=rra&id={profile_id}&rows=600': '10 KBytes per Data Source',
        }
        for query, expected in lookups.items():
            status, body, _, headers = client.fetch('/data_source_profiles.php?' + query)
            check(status == 200 and body == expected and headers.get('Content-Type', '').startswith('text/plain'),
                  f'legacy lookup keeps its text: {query} -> {body!r}')
        check(client.fetch(f'/data_source_profiles.php?action=ajax_span&profile_id={profile_id}&span=1&rows=1e3')[0] == 400,
              'a malformed lookup number is refused before arithmetic')

        delete_rra = f'{BASE}/{profile_id}/rras/{hourly}/delete'
        fields, target, _ = client.form(delete_rra, 'data_source_profile_rra_delete[_token]')
        check(client.fetch(target, fields)[0] == 200
              and harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_rra WHERE id={hourly}').strip() == '0',
              'a confirmed archive is removed')

        # Marking a profile the default moves the flag; it never clears it.
        target, submitted = client.profile_fields(profile_id, default='1')
        check(client.fetch(target, submitted)[0] == 200
              and harness.sql("SELECT GROUP_CONCAT(id) FROM data_source_profiles WHERE `default`='on'").strip() == str(profile_id),
              'marking a profile the default moves the flag')

        target, fields, _ = client.action('duplicate', [profile_id])
        harness.sql(f"UPDATE data_source_profiles SET name='symfony-profile renamed' WHERE id={profile_id}")
        check(client.fetch(target, fields)[0] == 409, 'a duplicate confirmed before a rename is refused')
        target, fields, _ = client.action('duplicate', [profile_id])
        status, _, location, _ = client.fetch(target, fields | {'data_source_profile_action[title_format]': 'Copy of <profile_title>'})
        copy = int(harness.sql("SELECT id FROM data_source_profiles WHERE name='Copy of symfony-profile renamed'").strip() or 0)
        created.append(copy)
        check(status == 200 and copy > 0
              and harness.sql(f"SELECT CONCAT_WS(',',step,heartbeat,`default`) FROM data_source_profiles WHERE id={copy}").strip() == '60,120,'
              and harness.sql(f'SELECT hash FROM data_source_profiles WHERE id={copy}') != harness.sql(f'SELECT hash FROM data_source_profiles WHERE id={profile_id}')
              and harness.sql(f'SELECT GROUP_CONCAT(consolidation_function_id ORDER BY 1) FROM data_source_profiles_cf WHERE data_source_profile_id={copy}').strip() == '1,3'
              and harness.sql(f'SELECT GROUP_CONCAT(CONCAT(name,steps,`rows`) ORDER BY id) FROM data_source_profiles_rra WHERE data_source_profile_id={copy}').strip() == 'raw11440',
              'duplication copies functions and archives under a new hash')

        status, _, location, _ = client.fetch(f'/data_source_profiles.php?action=edit&id={profile_id}&header=false')
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/data-source-profiles/{profile_id}/edit'),
              'the legacy editor URL forwards to the Symfony editor')
        status, _, location, _ = client.fetch(f'/data_source_profiles.php?action=item_edit&profile_id={profile_id}&id=0')
        check(status == 200 and urlsplit(location).path.endswith(f'/graphing/data-source-profiles/{profile_id}/rras/new'),
              'the legacy archive URL forwards to the Symfony archive editor')
        before = harness.sql(f'SELECT * FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}')
        check(client.fetch('/data_source_profiles.php?action=item_remove', {'id': '1'})[0] == 409
              and client.fetch('/data_source_profiles.php', {'action': 'actions', 'drp_action': '1', 'selected_items': 'a:0:{}'})[0] == 409
              and client.fetch('/data_source_profiles.php?action=save')[0] == 405
              and harness.sql(f'SELECT * FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}') == before,
              'posted legacy profile forms expire without changes')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=9')
        try:
            if group_grants:
                harness.sql(f"DELETE FROM user_auth_group_members WHERE user_id={user_id} AND group_id IN ({','.join(map(str, group_grants))})")
            check(client.fetch(BASE)[0] == 403 and client.fetch('/data_source_profiles.php')[0] == 403
                  and client.fetch(f'/data_source_profiles.php?action=ajax_size&type=profile&id={profile_id}')[0] == 403,
                  'a console session without realm 9 cannot open profiles')
        finally:
            if realm_rows != '0':
                harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},9)')
            for group_id in group_grants:
                harness.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id})')
    finally:
        if prior_default:
            harness.sql(f"UPDATE data_source_profiles SET `default`=IF(id={prior_default},'on','')")
        drop_profiles(harness, [profile_id for profile_id in created if profile_id])
        if prior_preference_rows == '0':
            harness.sql(f"DELETE FROM settings_user WHERE user_id={user_id} AND name='data_source_profiles_filters'")
        else:
            escaped = prior_preference.replace("'", "''")
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'data_source_profiles_filters','{escaped}')")

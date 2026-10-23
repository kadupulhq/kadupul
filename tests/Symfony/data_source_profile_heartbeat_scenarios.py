# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Exercise profile metadata and structural edits through the installed HTTP path."""
from data_source_profile_scenarios import BASE, ProfileClient


def verify_data_source_profile_heartbeat(harness, session, check):
    client = ProfileClient(harness, session, check)
    profile_ids = []
    template_ids = []
    local_data_ids = []
    template_data_ids = []
    rrd_ids = []

    def create_profile(name, heartbeat):
        name_hex = name.encode().hex().upper()
        profile_id = int(harness.sql(
            "INSERT INTO data_source_profiles (hash,name,step,heartbeat,x_files_factor) "
            f"VALUES (MD5(RAND()),CONVERT(UNHEX('{name_hex}') USING utf8mb4),300,{heartbeat},0.5); "
            "SELECT LAST_INSERT_ID()"
        ).strip())
        profile_ids.append(profile_id)
        return profile_id

    def create_template(name):
        name_hex = name.encode().hex().upper()
        template_id = int(harness.sql(
            "INSERT INTO data_template (name) "
            f"VALUES (CONVERT(UNHEX('{name_hex}') USING utf8mb4)); SELECT LAST_INSERT_ID()"
        ).strip())
        template_ids.append(template_id)
        return template_id

    def create_local_data():
        local_data_id = int(harness.sql(
            "INSERT INTO data_local (host_id) VALUES (0); SELECT LAST_INSERT_ID()"
        ).strip())
        local_data_ids.append(local_data_id)
        return local_data_id

    def create_template_data(local_data_id, template_id, profile_id, name):
        name_hex = name.encode().hex().upper()
        data_id = int(harness.sql(
            "INSERT INTO data_template_data "
            "(local_data_id,data_template_id,name,data_source_profile_id) "
            f"VALUES ({local_data_id},{template_id},CONVERT(UNHEX('{name_hex}') USING utf8mb4),{profile_id}); "
            "SELECT LAST_INSERT_ID()"
        ).strip())
        template_data_ids.append(data_id)
        return data_id

    def create_rrd(local_data_id, template_id, name, heartbeat):
        rrd_name = name.encode().hex().upper()
        rrd_id = int(harness.sql(
            "INSERT INTO data_template_rrd "
            "(local_data_id,data_template_id,data_source_name,rrd_heartbeat) "
            f"VALUES ({local_data_id},{template_id},CONVERT(UNHEX('{rrd_name}') USING utf8mb4),{heartbeat}); "
            "SELECT LAST_INSERT_ID()"
        ).strip())
        rrd_ids.append(rrd_id)
        return rrd_id

    def save(profile_id, **fields):
        """Submit only the named fields, as a browser omits the disabled ones of a read-only profile."""
        target, submitted = client.profile_fields(profile_id, **fields)
        status, body, _, _ = client.fetch(target, submitted)
        check(status in (200, 409, 422), 'profile submission returns a controlled page')
        return status, body

    def definition(profile_id):
        return harness.sql(f'SELECT step,heartbeat,x_files_factor FROM data_source_profiles WHERE id={profile_id}').strip()

    def functions(profile_id):
        return harness.sql(f'SELECT consolidation_function_id FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id} ORDER BY consolidation_function_id').strip()

    def audit_failures():
        result = harness.command('php', '-r', '$f = @file("log/kadupul-audit.jsonl"); $n = 0; foreach ($f ?: [] as $l) { $e = json_decode($l, true); if (($e["action"] ?? "") === "graphing.data_source_profile.edit" && ($e["outcome"] ?? "") === "failed") { $n++; } } echo $n;')
        return int(result['stdout'] or 0)

    try:
        selected_profile = create_profile('heartbeat-selected-profile', 600)
        other_profile = create_profile('heartbeat-other-profile', 1200)
        selected_template = create_template('heartbeat-selected-template')
        other_template = create_template('heartbeat-other-template')
        selected_local = create_local_data()
        other_local = create_local_data()
        selected_untemplated = create_local_data()
        other_untemplated = create_local_data()

        create_template_data(0, selected_template, selected_profile, 'selected template')
        create_template_data(selected_local, selected_template, selected_profile, 'selected local')
        create_template_data(0, other_template, other_profile, 'other template')
        create_template_data(other_local, other_template, other_profile, 'other local')
        create_template_data(selected_untemplated, 0, selected_profile, 'selected untemplated')
        create_template_data(other_untemplated, 0, other_profile, 'other untemplated')

        selected_template_rrd = create_rrd(0, selected_template, 'selected-template', 600)
        selected_local_rrd = create_rrd(selected_local, other_template, 'selected-local', 700)
        other_template_rrd = create_rrd(0, other_template, 'other-template', 1200)
        other_local_rrd = create_rrd(other_local, other_template, 'other-local', 1400)
        selected_untemplated_rrd = create_rrd(selected_untemplated, 0, 'selected-plain', 700)
        other_untemplated_rrd = create_rrd(other_untemplated, 0, 'other-plain', 1500)

        harness.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        status, warning_page = save(selected_profile, heartbeat='900')
        check(status == 200, 'profile heartbeat save completes')
        check(harness.sql(f'SELECT heartbeat FROM data_source_profiles WHERE id={selected_profile}').strip() == '900',
              'in-use profile heartbeat saves when the disabled step field is absent')
        check(harness.sql(f'SELECT step FROM data_source_profiles WHERE id={selected_profile}').strip() == '300',
              'heartbeat-only save leaves the read-only polling interval unchanged')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_template_rrd}').strip() == '900',
              'selected template RRD heartbeat follows its profile')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_local_rrd}').strip() == '900',
              'selected local-source RRD heartbeat follows its profile')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={other_template_rrd}').strip() == '1200',
              'unrelated template RRD heartbeat is unchanged')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={other_local_rrd}').strip() == '1400',
              'unrelated local-source RRD heartbeat is unchanged')

        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_untemplated_rrd}').strip() == '900'
              and harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={other_untemplated_rrd}').strip() == '1500',
              'non-templated local heartbeats update only for the selected profile')
        check('Changing the Heartbeat from this page' in warning_page and 'tune' in warning_page,
              'heartbeat save warns that existing RRD files still need tuning')
        check(not harness.rrd_calls(), 'heartbeat metadata save does not claim to tune existing RRD files')

        harness.sql(f'UPDATE data_template_rrd SET rrd_heartbeat=777 WHERE id={selected_local_rrd}')
        _, unchanged_page = save(selected_profile, heartbeat='900')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_local_rrd}').strip() == '777'
              and 'Changing the Heartbeat from this page' not in unchanged_page,
              'unchanged heartbeat retains existing metadata and emits no tuning warning')
        harness.sql(f'UPDATE data_template_rrd SET rrd_heartbeat=900 WHERE id={selected_local_rrd}')

        target, submitted = client.profile_fields(selected_profile, heartbeat='1200')
        del submitted['data_source_profile[_token]']
        status, _, _, _ = client.fetch(target, submitted)
        check(status == 422 and definition(selected_profile) == '300\t900\t0.5',
              'profile save rejects missing CSRF before persistence')

        status, refusal = save(selected_profile, step='60', heartbeat='1200', x_files_factor='0.25')
        check(status == 409, 'forged structural profile update is safely refused')
        check(definition(selected_profile) == '300\t900\t0.5',
              'server keeps read-only structural fields unchanged')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_local_rrd}').strip() == '900',
              'refused structural update leaves local-source heartbeat unchanged')
        harness.sql(f'INSERT INTO data_source_profiles_cf VALUES ({selected_profile},1),({selected_profile},4)')
        save(selected_profile, step='300', x_files_factor='0.5', heartbeat='1800', functions=['1', '4'])
        check(definition(selected_profile) == '300\t1800\t0.5',
              'unchanged structural fields permit an in-use heartbeat save')
        check(functions(selected_profile) == '1\n4', 'unchanged in-use consolidation functions are retained')
        for field, value in [('x_files_factor', '0.25'), ('functions', ['2'])]:
            label = 'consolidation_function_id[]' if field == 'functions' else field
            failures = audit_failures()
            status, refusal = save(selected_profile, heartbeat='2400', **{field: value})
            check(status == 409 and 'Profiles that are in use by Data Sources become read only' in refusal
                  and audit_failures() == failures + 1,
                  'read-only refusal displays its error and records a failed audit event: ' + label)
            check(definition(selected_profile) == '300\t1800\t0.5' and functions(selected_profile) == '1\n4',
                  'single forged structural field refuses the complete in-use save: ' + label)
            check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_local_rrd}').strip() == '1800',
                  'single forged structural field leaves propagated heartbeat unchanged: ' + label)

        template_profile = create_profile('heartbeat-template-only', 600)
        template = create_template('heartbeat-only-template')
        create_template_data(0, template, template_profile, 'only template')
        template_rrd = create_rrd(0, template, 'only-template', 600)
        _, page = save(template_profile, heartbeat='900')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={template_rrd}').strip() == '900',
              'template-only profile propagates heartbeat without local data sources')
        check('Changing the Heartbeat from this page' not in page,
              'template-only heartbeat save emits no existing-file tuning warning')

        save(template_profile, step='60', heartbeat='120', x_files_factor='0.25', functions=['2', '4'])
        check(definition(template_profile) == '60\t120\t0.25' and functions(template_profile) == '2\n4',
              'template-only profile accepts every structural field')

        unused = create_profile('heartbeat-unused', 600)
        save(unused, functions=['2', '4'])
        check(functions(unused) == '2\n4' and definition(unused) == '300\t600\t0.5',
              'unused profile consolidation-only save is independent of step submission')
        save(unused, x_files_factor='0.25')
        check(definition(unused) == '300\t600\t0.25' and functions(unused) == '2\n4',
              'unused profile factor-only save retains interval and consolidation functions')
        for malformed in ['2', ['99'], ['1e0'], ['1', '2', '3', '4', '1']]:
            target, submitted = client.profile_fields(unused, heartbeat='1200')
            submitted['data_source_profile[name]'] = 'malformed-should-not-save'
            key = 'data_source_profile[consolidation_function_id][]' if isinstance(malformed, list) else 'data_source_profile[consolidation_function_id]'
            status, _, _, _ = client.fetch(target, submitted | {key: malformed})
            check(status == 422 and definition(unused) == '300\t600\t0.25' and functions(unused) == '2\n4'
                  and harness.sql(f'SELECT name FROM data_source_profiles WHERE id={unused}').strip() == 'heartbeat-unused',
                  'malformed consolidation selection causes no partial writes: ' + repr(malformed))
        save(unused, step='60', heartbeat='120', x_files_factor='0.1', functions=['1', '3'])
        check(definition(unused) == '60\t120\t0.1' and functions(unused) == '1\n3',
              'unused profile structural edits save normally')

        fields, target, _ = client.form(BASE + '/new', 'data_source_profile[_token]')
        client.fetch(target, {
            'data_source_profile[_token]': fields['data_source_profile[_token]'],
            'data_source_profile[name]': 'heartbeat-http-created',
            'data_source_profile[step]': '300',
            'data_source_profile[heartbeat]': '600',
            'data_source_profile[x_files_factor]': '0.5',
            'data_source_profile[consolidation_function_id][]': ['1', '4'],
        })
        created = int(harness.sql("SELECT id FROM data_source_profiles WHERE name='heartbeat-http-created'").strip())
        profile_ids.append(created)
        check(definition(created) == '300\t600\t0.5' and functions(created) == '1\n4',
              'new profile creation persists submitted structural fields')
    finally:
        if rrd_ids:
            harness.sql('DELETE FROM data_template_rrd WHERE id IN (' + ','.join(map(str, rrd_ids)) + ')')
        if template_data_ids:
            harness.sql('DELETE FROM data_template_data WHERE id IN (' + ','.join(map(str, template_data_ids)) + ')')
        for local_data_id in local_data_ids:
            harness.sql(f'DELETE FROM data_local WHERE id={local_data_id}')
        for template_id in template_ids:
            harness.sql(f'DELETE FROM data_template WHERE id={template_id}')
        for profile_id in profile_ids:
            harness.sql(f'DELETE FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}')
            harness.sql(f'DELETE FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id}')
            harness.sql(f'DELETE FROM data_source_profiles WHERE id={profile_id}')

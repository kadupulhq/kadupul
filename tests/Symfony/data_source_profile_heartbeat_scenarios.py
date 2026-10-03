"""Keep profile heartbeat propagation scoped to the referenced data template."""
from urllib.parse import urlencode
from urllib.request import Request


def verify_data_source_profile_heartbeat(harness, session, check):
    profile_ids = []
    template_ids = []
    local_data_ids = []
    template_data_ids = []
    rrd_ids = []

    def create_profile(name, heartbeat):
        name_hex = name.encode().hex().upper()
        profile_id = int(harness.sql(
            "INSERT INTO data_source_profiles (name,step,heartbeat,x_files_factor) "
            f"VALUES (CONVERT(UNHEX('{name_hex}') USING utf8mb4),300,{heartbeat},0.5); "
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

    try:
        selected_profile = create_profile('heartbeat-selected-profile', 600)
        other_profile = create_profile('heartbeat-other-profile', 1200)
        selected_template = create_template('heartbeat-selected-template')
        other_template = create_template('heartbeat-other-template')
        selected_local = create_local_data()
        other_local = create_local_data()

        create_template_data(0, selected_template, selected_profile, 'selected template')
        create_template_data(selected_local, selected_template, selected_profile, 'selected local')
        create_template_data(0, other_template, other_profile, 'other template')
        create_template_data(other_local, other_template, other_profile, 'other local')

        selected_template_rrd = create_rrd(0, selected_template, 'selected-template', 600)
        selected_local_rrd = create_rrd(selected_local, selected_template, 'selected-local', 700)
        other_template_rrd = create_rrd(0, other_template, 'other-template', 1200)
        other_local_rrd = create_rrd(other_local, other_template, 'other-local', 1400)

        harness.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log')
        heartbeat_fields = {
            'save_component_profile': '1',
            'id': str(selected_profile),
            'name': 'heartbeat-selected-profile',
            'heartbeat': '900',
            '__csrf_magic': session.token,
        }
        request = Request(
            harness.base + '/data_source_profiles.php?action=save',
            data=urlencode(heartbeat_fields).encode(),
            headers={'Origin': harness.base},
        )
        with session.opener.open(request, timeout=30) as response:
            warning_page = response.read().decode('utf-8', errors='replace')
            save_status = response.status
        check(save_status == 200, 'profile heartbeat save completes')
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

        check('Changing the Heartbeat from this page' in warning_page and 'tune' in warning_page,
              'heartbeat save warns that existing RRD files still need tuning')
        check(not harness.rrd_calls(), 'heartbeat metadata save does not claim to tune existing RRD files')

        forged = session.request('/data_source_profiles.php?action=save', {
            'save_component_profile': '1',
            'id': str(selected_profile),
            'name': 'heartbeat-selected-profile',
            'step': '60',
            'heartbeat': '1200',
            'x_files_factor': '0.25',
            '__csrf_magic': session.token,
        })
        check(forged['status'] == 200, 'forged structural profile update is safely refused')
        check(harness.sql(f'SELECT step,heartbeat,x_files_factor FROM data_source_profiles WHERE id={selected_profile}').strip() == '300\t900\t0.5',
              'server keeps read-only structural fields unchanged')
        check(harness.sql(f'SELECT rrd_heartbeat FROM data_template_rrd WHERE id={selected_local_rrd}').strip() == '900',
              'refused structural update leaves local-source heartbeat unchanged')
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
            harness.sql(f'DELETE FROM data_source_profiles WHERE id={profile_id}')

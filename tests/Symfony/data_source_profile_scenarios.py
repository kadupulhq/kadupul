"""Protect data-source profile definitions referenced by templates or sources."""


def verify_data_source_profile_deletion(harness, session, check):
    profiles = {}
    data_local_ids = []
    template_ids = []

    def create_profile(label):
        name_hex = ('profile-delete-' + label).encode().hex().upper()
        profile_id = int(harness.sql(
            "INSERT INTO data_source_profiles (name,step,heartbeat,x_files_factor) "
            f"VALUES (CONVERT(UNHEX('{name_hex}') USING utf8mb4),300,600,0.5); "
            "SELECT LAST_INSERT_ID()"
        ).strip())
        rra_id = int(harness.sql(
            "INSERT INTO data_source_profiles_rra "
            "(data_source_profile_id,name,steps,`rows`,timespan) "
            f"VALUES ({profile_id},'fixture',1,10,86400); SELECT LAST_INSERT_ID()"
        ).strip())
        harness.sql(f'INSERT INTO data_source_profiles_cf VALUES ({profile_id},1)')
        profiles[label] = (profile_id, rra_id)
        return profile_id

    def create_reference(profile_id, local_source):
        local_data_id = 0
        if local_source:
            local_data_id = int(harness.sql(
                'INSERT INTO data_local (host_id) VALUES (0); SELECT LAST_INSERT_ID()'
            ).strip())
            data_local_ids.append(local_data_id)
        template_hex = ('profile reference').encode().hex().upper()
        template_id = int(harness.sql(
            "INSERT INTO data_template_data "
            "(local_data_id,data_template_id,name,data_source_profile_id) "
            f"VALUES ({local_data_id},0,CONVERT(UNHEX('{template_hex}') USING utf8mb4),{profile_id}); "
            "SELECT LAST_INSERT_ID()"
        ).strip())
        template_ids.append(template_id)

    def selected_items(profile_ids):
        return 'a:' + str(len(profile_ids)) + ':{' + ''.join(
            f'i:{index};i:{profile_id};' for index, profile_id in enumerate(profile_ids)
        ) + '}'

    def post_confirmation(profile_ids):
        fields = {'action': 'actions', 'drp_action': '1', '__csrf_magic': session.token}
        fields.update({f'chk_{profile_id}': 'on' for profile_id in profile_ids})
        return session.request('/data_source_profiles.php', fields)

    def delete_confirmed(profile_ids):
        return session.request('/data_source_profiles.php', {
            'action': 'actions', 'drp_action': '1',
            'selected_items': selected_items(profile_ids), '__csrf_magic': session.token,
        })

    try:
        template_profile = create_profile('template')
        local_profile = create_profile('local')
        late_profile = create_profile('late')
        unused_profile = create_profile('unused')
        create_reference(template_profile, False)
        create_reference(local_profile, True)

        selected = [template_profile, local_profile, late_profile, unused_profile]
        confirmation = post_confirmation(selected)
        check(confirmation['status'] == 200, 'profile deletion confirmation page renders')

        # This reference is created after the confirmation screen, so the final
        # mutation must recheck live database state.
        create_reference(late_profile, True)
        result = delete_confirmed(selected)
        check(result['status'] in (200, 302), 'profile deletion submission completes')

        for label in ('template', 'local', 'late'):
            profile_id, rra_id = profiles[label]
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles WHERE id={profile_id}').strip() == '1',
                  label + ' referenced profile remains')
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_rra WHERE id={rra_id}').strip() == '1',
                  label + ' referenced archive definition remains')
            check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id}').strip() == '1',
                  label + ' referenced consolidation definition remains')

        unused_id, unused_rra = profiles['unused']
        check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles WHERE id={unused_id}').strip() == '0',
              'unused profile in a mixed selection is deleted')
        check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_rra WHERE id={unused_rra}').strip() == '0',
              'unused profile archive definition is deleted')
        check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_cf WHERE data_source_profile_id={unused_id}').strip() == '0',
              'unused profile consolidation definition is deleted')

        standalone = create_profile('standalone')
        standalone_rra = profiles['standalone'][1]
        check(post_confirmation([standalone])['status'] == 200, 'unused profile confirmation renders')
        check(delete_confirmed([standalone])['status'] in (200, 302), 'unused profile deletion succeeds')
        check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles WHERE id={standalone}').strip() == '0',
              'unused profile is normally removable')
        check(harness.sql(f'SELECT COUNT(*) FROM data_source_profiles_rra WHERE id={standalone_rra}').strip() == '0',
              'unused profile RRA definitions are removed')
    finally:
        if template_ids:
            harness.sql('DELETE FROM data_template_data WHERE id IN (' + ','.join(map(str, template_ids)) + ')')
        for local_data_id in data_local_ids:
            harness.sql(f'DELETE FROM data_local WHERE id={local_data_id}')
        for profile_id, _ in profiles.values():
            harness.sql(f'DELETE FROM data_source_profiles_rra WHERE data_source_profile_id={profile_id}')
            harness.sql(f'DELETE FROM data_source_profiles_cf WHERE data_source_profile_id={profile_id}')
            harness.sql(f'DELETE FROM data_source_profiles WHERE id={profile_id}')

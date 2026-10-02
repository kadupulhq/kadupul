"""External Links workflow through real Symfony forms and transactional MariaDB."""
from urllib.parse import urlencode
from urllib.request import Request
from urllib.error import HTTPError
import json
import re
from device_edit_scenarios import Inputs
from harness import Session


def verify_links(harness, session, user_id, check):
    base = '/app.php/links'
    def get_form(path):
        with session.opener.open(harness.base + path) as response:
            check(response.status == 200, 'links form loads: ' + path)
            body = response.read().decode()
        parser = Inputs()
        parser.feed(body)
        new_section = re.search(r'<option value="([^"]+)">New Name Below</option>', body)
        parser.new_section_value = new_section.group(1) if new_section else None
        return parser
    def post(path, fields, origin=None, client=None):
        request = Request(harness.base + path, data=urlencode(fields).encode(), headers={'Origin': origin or harness.base})
        try:
            result = (client or session).opener.open(request)
        except HTTPError as error:
            result = error
        with result:
            return result.status, result.read().decode(), result.url
    created = []
    try:
        check(Session(harness.base).request(base + '?filter[]=x')['status'] == 401, 'links anonymous refusal precedes malformed filters')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},15)')
        check(session.request('/links.php')['status'] == 200, 'links legacy listing forwards')
        check(post('/links.php', {'action': 'save'})[0] == 409, 'links expired legacy POST never mutates')
        form = get_form(base + '/new')
        check(form.new_section_value is not None, 'links new section uses the rendered creation choice')
        fields = form.fields | {'link[title]': 'Link <tag> 東京', 'link[style]': 'CONSOLE', 'link[filename]': '0', 'link[fileurl]': 'https://example.org/?x=1&y=2', 'link[consolesection]': form.new_section_value, 'link[consolenewsection]': '__NEW__', 'link[enabled]': '1', 'link[refresh]': '60'}
        check(post(base + '/new', {k:v for k,v in fields.items() if k != 'link[_token]'})[0] == 422, 'links missing CSRF rejected')
        check(post(base + '/new', fields, origin='https://attacker.invalid')[0] == 422, 'links cross-origin rejected')
        for changes in [{'link[fileurl]': 'javascript:alert(1)'}, {'link[filename]': '../index.php'}, {'link[refresh]':'61'}, {'link[title]':'x'*21}, {'link[unknown]':'1'}]:
            check(post(base + '/new', fields | changes)[0] == 422, 'links invalid submission rejected')
        status, body, _ = post(base + '/new', fields)
        check(status == 200 and '&lt;tag&gt;' in body and '<tag>' not in body, 'links escaped Twig list after save')
        link_id = int(harness.sql("SELECT id FROM external_links WHERE title='Link <tag> 東京' ORDER BY id DESC LIMIT 1").strip())
        created.append(link_id)
        with session.opener.open(harness.base + '/public/index.php/links') as response:
            public_body = response.read().decode()
        viewer = f'/link.php?id={link_id}'
        check(f'href="{viewer}"' in public_body and '/public/link.php' not in public_body, 'public Navigation entry links to the installation viewer')
        with session.opener.open(harness.base + viewer) as response:
            viewer_body = response.read().decode()
        check(response.status == 200 and 'id="content"' in viewer_body, 'public Navigation viewer URL opens the authorized legacy page')
        def preferences():
            return json.loads(harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='external_links_filters'").strip())
        check(session.request(base + '?filter=Link&rows=10&page=7')['status'] == 200, 'link filters remember an explicit later page')
        remembered = preferences()
        check(session.request('/links.php?header=false')['status'] == 200 and preferences() == remembered, 'plain legacy link bookmark preserves remembered filters and page')
        check(session.request(base + '?filter=Different')['status'] == 200 and preferences()['page'] == '1', 'changing link search resets the remembered page')
        check(session.request(base + '?page=7')['status'] == 200, 'unchanged filter allows explicit link pagination')
        check(session.request('/links.php?rows=15')['status'] == 200 and preferences()['filter'] == 'Different' and preferences()['page'] == '1', 'partial legacy row filter preserves search and resets pagination')
        check(session.request('/links.php?clear=1&header=false')['status'] == 200 and preferences()['filter'] == '' and preferences()['page'] == '1', 'legacy clear explicitly resets remembered link filters')
        check(harness.sql(f'SELECT contentfile FROM external_links WHERE id={link_id}').strip() == 'https://example.org/?x=1&y=2', 'links URL bytes persist unchanged')
        check(harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id={link_id + 10000}').strip() == '1', 'links save grants actor its viewing realm')
        check(harness.sql(f'SELECT extendedstyle FROM external_links WHERE id={link_id}').strip() == '__NEW__', 'new console section may be named literally __NEW__')
        editor = get_form(base + f'/{link_id}/edit')
        check(editor.fields['link[consolesection]'] == '__NEW__' and editor.new_section_value != '__NEW__', 'existing __NEW__ section has its own selected form choice')
        check(post(base + f'/{link_id}/edit', editor.fields)[0] == 200 and harness.sql(f'SELECT extendedstyle FROM external_links WHERE id={link_id}').strip() == '__NEW__', 'unchanged edit preserves the stored __NEW__ section')
        editor = get_form(base + f'/{link_id}/edit')
        edit_fields = editor.fields | {'link[title]': 'Edited link', 'link[style]': 'TAB', 'link[filename]': '0', 'link[fileurl]':'ftp://example.org/a', 'link[consolesection]':'External Links','link[consolenewsection]':'','link[enabled]':'1','link[refresh]':'0'}
        stale = dict(edit_fields)
        check(post(base + f'/{link_id}/edit', edit_fields)[0] == 200, 'links edit supports legacy FTP URL')
        check(post(base + f'/{link_id}/edit', stale)[0] == 409, 'links stale edit rejected')
        def action(operation, ids):
            return base + '/action/' + operation + '?' + urlencode({'ids[]': ids}, doseq=True)
        path = action('disable', [link_id]); form = get_form(path)
        check(post(path, form.fields)[0] == 200 and harness.sql(f'SELECT enabled FROM external_links WHERE id={link_id}').strip() == '', 'links disable handoff')
        path = action('enable', [link_id]); form = get_form(path)
        check(post(path, form.fields)[0] == 200 and harness.sql(f'SELECT enabled FROM external_links WHERE id={link_id}').strip() == 'on', 'links enable handoff')
        # Fresh installations have no links. Create a real neighbor so moving up
        # changes order; replaying a no-op at the first position is not stale.
        neighbor_form = get_form(base + '/new')
        check(post(base + '/new', neighbor_form.fields | fields | {'link[title]': 'Order neighbor', 'link[revision]': neighbor_form.fields['link[revision]']})[0] == 200, 'links reorder neighbor created')
        neighbor_id = int(harness.sql("SELECT id FROM external_links WHERE title='Order neighbor' ORDER BY id DESC LIMIT 1").strip())
        created.append(neighbor_id)
        path = action('up', [neighbor_id]); form = get_form(path); stale_order = dict(form.fields)
        before_order = harness.sql('SELECT id FROM external_links ORDER BY sortorder,id').strip()
        check(post(path, form.fields)[0] == 200, 'links adjacent reorder commits')
        check(harness.sql('SELECT id FROM external_links ORDER BY sortorder,id').strip() != before_order, 'links reorder changes the stored sequence')
        check(post(path, stale_order)[0] == 409, 'links stale reorder rejected')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=15')
        check(session.request(base)['status'] == 403 and post(base + '/new', fields)[0] == 403, 'links realm revocation refuses reads and writes')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},15)')
        harness.sql('INSERT INTO user_auth_group (name,description,enabled) VALUES (\'links-test\',\'links-test\',\'on\')')
        group_id = int(harness.sql("SELECT id FROM user_auth_group WHERE name='links-test'").strip())
        harness.sql(f'INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group_id},{link_id + 10000})')
        path = action('delete',[link_id]); form = get_form(path)
        harness.sql("CREATE TRIGGER links_test_fail BEFORE DELETE ON user_auth_group_realm FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
        check(post(path, form.fields)[0] == 502 and harness.sql(f'SELECT COUNT(*) FROM external_links WHERE id={link_id}').strip() == '1', 'links grant deletion failure rolls back link deletion')
        harness.sql('DROP TRIGGER links_test_fail')
        check(post(path, form.fields)[0] == 200, 'links delete commits atomically')
        check(harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE realm_id={link_id + 10000}').strip() == '0' and harness.sql(f'SELECT COUNT(*) FROM user_auth_group_realm WHERE realm_id={link_id + 10000}').strip() == '0', 'links deletion cleans direct and group realms')
        harness.sql(f'DELETE FROM user_auth_group WHERE id={group_id}')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_auto_detection','0'),('i18n_default_language','en-US')")
        harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','fr-FR')")
        french = Session(harness.base)
        french.login('behavior-admin')
        with french.opener.open(harness.base + base + '/new?language=en&_locale=en') as response:
            french_body = response.read().decode()
        check('Ajouter un lien' in french_body and 'Nom de l’onglet ou du menu' in french_body and 'value="60"' in french_body, 'links French session translates labels and preserves refresh values: ' + repr([value in french_body for value in ('Ajouter un lien', 'Nom de l’onglet ou du menu', 'value="60"')]))
        harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language','en-US')")
        harness.sql('ALTER TABLE external_links ENGINE=MyISAM')
        form = get_form(base + '/new')
        check(post(base + '/new', form.fields | fields | {'link[revision]': form.fields['link[revision]']})[0] == 502, 'links nontransactional write refused')
        harness.sql('ALTER TABLE external_links ENGINE=InnoDB')
    finally:
        harness.sql('DROP TRIGGER IF EXISTS links_test_fail')
        for link_id in created:
            harness.sql(f'DELETE FROM external_links WHERE id={link_id}')
            harness.sql(f'DELETE FROM user_auth_realm WHERE realm_id={link_id + 10000}')
            harness.sql(f'DELETE FROM user_auth_group_realm WHERE realm_id={link_id + 10000}')

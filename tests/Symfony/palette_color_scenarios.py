"""Palette CRUD, CSV, authorization and transaction handoff on isolated MariaDB."""
from pathlib import Path
import csv
import io
import sys
from urllib.parse import urlencode
from urllib.request import Request
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Session
from device_edit_scenarios import Inputs



def verify_palette_colors(h, s, uid, check):
    original_groups = h.sql(f'SELECT group_id FROM user_auth_group_members WHERE user_id={uid}').split()
    original_direct = h.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={uid} AND realm_id=5').strip() != '0'
    h.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({uid},5)')
    def fetch(path, fields=None, origin=True, upload=None):
        headers = {'Origin': h.base} if origin else {}
        data = None if fields is None else urlencode(fields).encode()
        if upload is not None:
            boundary = 'palette-test-boundary-984213'
            body = bytearray()
            for key, value in fields.items():
                if key.endswith('[file]'):
                    continue
                body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="palette_color_import[file]"; filename="colors.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode())
            body.extend(upload.encode())
            body.extend(f'\r\n--{boundary}--\r\n'.encode())
            data = bytes(body)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        try:
            response = s.opener.open(Request(h.base + path, data=data, headers=headers))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url
    def form(path):
        status, body, _ = fetch(path)
        check(status == 200, 'form GET ' + path + f' ({status})')
        parser = Inputs(); parser.feed(body)
        return parser.fields
    create = '/app.php/graphing/colors/new'
    fields = form(create) | {'palette_color[name]': '<tag>, "quoted"', 'palette_color[hex]': 'abcdef'}
    check(fetch(create, fields, False)[0] == 422, 'origin required')
    check(fetch(create, {k:v for k,v in fields.items() if not k.endswith('[_token]')})[0] == 422, 'CSRF token required')
    status, body, url = fetch(create, fields)
    check(status == 200 and 'saved=1' in url, 'create succeeds')
    ident = int(url.split('/colors/')[1].split('/')[0])
    check('&lt;tag&gt;' in body and '<tag>' not in body, 'Twig escapes exact names')
    check(h.sql(f'SELECT hex FROM colors WHERE id={ident}').strip() == 'abcdef', 'exact hex handoff')
    edit = f'/app.php/graphing/colors/{ident}/edit'
    stale = form(edit)
    h.sql(f"UPDATE colors SET name='concurrent' WHERE id={ident}")
    check(fetch(edit, stale | {'palette_color[name]': 'stale'})[0] == 409, 'stale edit rejected')
    h.sql(f"UPDATE colors SET name='Custom exact' WHERE id={ident}")
    status, body, _ = fetch('/app.php/graphing/colors?' + urlencode({'named':'false', 'filter': 'Custom exact', 'rows':'1'}))
    check(status == 200 and 'Custom exact' in body, 'search and named filter')
    check('Custom exact' in fetch('/app.php/graphing/colors')[1], 'per-user filter preference')
    attack = 'javascript:alert(1)" onclick="evil()'
    status, body, _ = fetch('/app.php/graphing/colors?' + urlencode({'named':'false', 'filter':attack}))
    parser = Inputs(); parser.feed(body)
    check(status == 200 and all(not link.lower().startswith('javascript:') for link in parser.links) and ' onclick="evil()"' not in body, 'fixed route links encode malicious filter')
    export = '/app.php/graphing/colors/export?named=false&filter=Custom%20exact&rows=1'
    status, text, _ = fetch(export)
    check(status == 200 and list(csv.reader(io.StringIO(text))) == [['name','hex'],['Custom exact','abcdef']], 'filtered export exact CSV')
    legacy_status, legacy_text, _ = fetch('/color.php?action=export&named=false&filter=Custom%20exact')
    check(legacy_status == 200 and list(csv.reader(io.StringIO(legacy_text))) == [['name','hex'],['Custom exact','abcdef']], 'legacy export preserves explicit filters')
    importer = '/app.php/graphing/colors/import'
    raw_name = 'comma, "quote"\nsecond line'
    output = io.StringIO(); writer = csv.writer(output); writer.writerow(['name','hex']); writer.writerow([raw_name,'abc']); writer.writerow(['Builtin forged','000000'])
    f = form(importer); f.pop('palette_color_import[allow_update]', None)
    check(fetch(importer, f, upload=output.getvalue())[0] == 200, 'quoted newline CSV upload succeeds')
    csv_id = int(h.sql("SELECT id FROM colors WHERE hex='abc'").strip())
    check(h.sql(f'SELECT HEX(name) FROM colors WHERE id={csv_id}').strip().lower() == raw_name.encode().hex(), 'CSV exact name data handoff')
    check(h.sql("SELECT name FROM colors WHERE hex='000000'").strip() == 'Black', 'built-in import skipped')
    f = form(importer) | {'palette_color_import[allow_update]':'1'}
    check(fetch(importer, f, upload='name,hex\nupdated,abc\nforged,000000\n')[0] == 200, 'allow-update import succeeds')
    check(h.sql(f'SELECT name FROM colors WHERE id={csv_id}').strip() == 'updated', 'allow-update changes existing custom color')
    check(h.sql("SELECT name FROM colors WHERE hex='000000'").strip() == 'Black', 'allow-update preserves built-in')
    f = form(importer)
    before = h.sql('SELECT COUNT(*) FROM colors').strip()
    check(fetch(importer, f, upload='name,hex\na,123\nb,xyz\n')[0] == 422, 'invalid CSV whole import rejected')
    check(h.sql('SELECT COUNT(*) FROM colors').strip() == before, 'invalid CSV changes no rows')
    f = form(importer)
    h.sql(f"UPDATE colors SET name='changed' WHERE id={csv_id}")
    check(fetch(importer, f, upload='name,hex\na,123\n')[0] == 409, 'stale import snapshot rejected')
    h.sql("CREATE TRIGGER palette_import_failure BEFORE INSERT ON colors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected palette failure'")
    try:
        f = form(importer)
        check(fetch(importer, f, upload='name,hex\nrollback,abc\nnew,123\n')[0] == 502, 'injected DB failure returned honestly')
        check(h.sql(f'SELECT name FROM colors WHERE id={csv_id}').strip() == 'changed', 'transaction rollback restores prior import updates')
    finally:
        h.sql('DROP TRIGGER palette_import_failure')
    f = form(importer)
    h.sql('ALTER TABLE colors ENGINE=MyISAM')
    try:
        check(fetch(importer, f, upload='name,hex\na,123\n')[0] == 502, 'nontransactional palette rejected before write')
        check(h.sql("SELECT COUNT(*) FROM colors WHERE hex='123'").strip() == '0', 'MyISAM rejection leaves database unchanged')
    finally:
        h.sql('ALTER TABLE colors ENGINE=InnoDB')
    h.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_default_language','fr')")
    status, body, _ = fetch('/app.php/graphing/colors/import')
    check(status == 200 and 'Importer des couleurs' in body, 'French locale reaches CSV route')
    h.sql("REPLACE INTO settings (name,value) VALUES ('i18n_default_language','en')")
    delete = f'/app.php/graphing/colors/actions/delete?ids[]={ident}'
    f = form(delete)
    ref = int(h.sql(f'INSERT INTO graph_templates_item (color_id, graph_template_id, local_graph_id) VALUES ({ident},0,900001); SELECT LAST_INSERT_ID()').strip())
    check(fetch(delete, f)[0] == 422, 'graph reference added after confirmation blocks deletion')
    h.sql(f'DELETE FROM graph_templates_item WHERE id={ref}')
    h.sql(f'INSERT INTO color_template_items (color_template_id,color_id,sequence) VALUES (999999,{ident},1)')
    check(fetch(delete, f)[0] == 422, 'color template direct reference blocks deletion')
    h.sql(f'DELETE FROM color_template_items WHERE color_template_id=999999')
    f = form(delete)
    h.sql(f"UPDATE colors SET name='delete changed' WHERE id={ident}")
    check(fetch(delete, f)[0] == 409, 'stale deletion rejected')
    check(fetch(delete, form(delete))[0] == 200, 'fresh deletion succeeds')
    builtin = '/app.php/graphing/colors/1/edit'
    f = form(builtin) | {'palette_color[name]':'forged','palette_color[hex]':'999'}
    check(fetch(builtin, f)[0] == 422, 'forged built-in edit rejected')
    check(fetch('/color.php?action=actions', {'action':'actions','selected_items':'a:1:{i:0;i:1;}'})[0] == 409, 'expired legacy POST never runs')
    check(fetch('/color.php?action=import')[0] == 200, 'legacy import links redirect')
    h.sql(f"UPDATE user_auth SET must_change_password='on' WHERE id={uid}")
    try:
        check(fetch('/app.php/graphing/colors')[0] == 403, 'forced password policy rejects read')
    finally:
        h.sql(f"UPDATE user_auth SET must_change_password='' WHERE id={uid}")
    h.sql(f'DELETE FROM user_auth_realm WHERE user_id={uid} AND realm_id=5; DELETE FROM user_auth_group_members WHERE user_id={uid}')
    check(fetch('/app.php/graphing/colors')[0] == 403, 'missing palette realm rejected')
    for group in original_groups:
        h.sql(f'INSERT IGNORE INTO user_auth_group_members (group_id,user_id) VALUES ({int(group)},{uid})')
    if original_direct:
        h.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({uid},5)')

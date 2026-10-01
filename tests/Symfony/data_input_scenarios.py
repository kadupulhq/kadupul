"""Isolated real HTTP, MariaDB, worker and collector handoff checks."""
import re
from pathlib import Path
from types import SimpleNamespace
from html.parser import HTMLParser
from urllib.request import Request
from urllib.error import HTTPError
import sys
import json
import urllib.parse
import uuid
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session

class Fields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and 'name' in attrs:
            self.values[attrs['name']] = attrs.get('value', '')

def page(session, url):
    response = session.opener.open(session.base + url)
    body = response.read().decode()
    fields = Fields()
    fields.feed(body)
    return fields.values, body

def post(session, url, data, origin=None):
    request = Request(session.base + url, data=urllib.parse.urlencode(data).encode(), headers={'Origin': origin or session.base})
    try:
        response = session.opener.open(request)
    except HTTPError as error:
        response = error
    return response.status, response.read().decode('utf-8', 'replace'), response.url

def verify_data_inputs(harness, session, check):
    anonymous = Session(harness.base)
    check(anonymous.request('/app.php/data-inputs?filter[]=bad')['status'] == 401, 'anonymous rejected before parsing')
    user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
    harness.sql(f'INSERT IGNORE INTO user_auth_realm(user_id,realm_id) VALUES ({user},2),({user},8)')
    fields, body = page(session, '/app.php/data-inputs')
    check('Data Input Methods' in body and '/data-inputs/' in body and 'Get SNMP Data (Indexed)' not in body, 'hook-aware list excludes protected system methods')
    count = harness.sql('SELECT COUNT(*) FROM data_input').strip()
    status, _, _ = post(session, '/data_input.php?action=actions', {'action':'actions','selected_items':'bad'})
    check(status == 409 and harness.sql('SELECT COUNT(*) FROM data_input').strip() == count, 'expired legacy bulk POST cannot mutate')
    name = 'Data Input HTTP ' + uuid.uuid4().hex[:10]
    command = '  perl <path_cacti>/scripts/example.pl <second> <first>  '
    fields, _ = page(session, '/app.php/data-inputs/new')
    payload = {'data_input_method[name]':name,'data_input_method[input_string]':command,'data_input_method[type_id]':'1','data_input_method[revision]':'','data_input_method[_token]':fields['data_input_method[_token]']}
    bad = dict(payload); bad.pop('data_input_method[_token]')
    check(post(session, '/app.php/data-inputs/new', bad)[0] == 422, 'missing CSRF rejects create')
    check(post(session, '/app.php/data-inputs/new', payload, 'https://invalid.example')[0] == 422, 'cross-origin rejects create')
    extra=dict(payload); extra['data_input_method[actor]']='999'
    check(post(session, '/app.php/data-inputs/new', extra)[0] == 422, 'unexpected mutation fields are rejected')
    status, body, _ = post(session, '/app.php/data-inputs/new', payload)
    if status != 200:
        raise AssertionError(f'create failed {status}: {body[-1000:]}')
    target = int(harness.sql("SELECT id FROM data_input WHERE name='" + name + "'").strip())
    check(harness.sql(f'SELECT HEX(input_string) FROM data_input WHERE id={target}').strip().lower() == command.encode().hex(), 'raw command reaches database without trimming or escaping')
    field_ids = []
    for data_name in ('first', 'second'):
        path = f'/app.php/data-inputs/{target}/fields/0?direction=in'
        fields, _ = page(session, path)
        payload = {'data_input_field[name]':data_name.title(), 'data_input_field[data_name]':data_name, 'data_input_field[input_output]':'out' if data_name == 'first' else 'in', 'data_input_field[type_code]':'', 'data_input_field[regexp_match]':'', 'data_input_field[revision]':fields['data_input_field[revision]'], 'data_input_field[_token]':fields['data_input_field[_token]']}
        status, body, _ = post(session, path, payload)
        check(status == 200, 'input field saves through Symfony form and worker')
        field_ids.append(int(harness.sql(f"SELECT id FROM data_input_fields WHERE data_input_id={target} AND data_name='{data_name}'").strip()))
        if data_name == 'first':
            check(harness.sql(f'SELECT input_output FROM data_input_fields WHERE id={field_ids[-1]}').strip() == 'in', 'forged input-form hidden direction remains server-bound to input')
    check(harness.sql(f"SELECT GROUP_CONCAT(data_name ORDER BY sequence) FROM data_input_fields WHERE data_input_id={target}").strip() == 'second,first', 'placeholder occurrence controls input field sequence')
    edit = f'/app.php/data-inputs/{target}/edit'
    stale, _ = page(session, edit)
    harness.sql(f'UPDATE data_input_fields SET sequence=99 WHERE id={field_ids[0]}')
    payload = {'data_input_method[name]':name,'data_input_method[input_string]':command,'data_input_method[type_id]':'1','data_input_method[revision]':stale['data_input_method[revision]'],'data_input_method[_token]':stale['data_input_method[_token]']}
    check(post(session, edit, payload)[0] == 409, 'stale parent revision includes child sequence')
    fields, _ = page(session, edit)
    payload['data_input_method[revision]'] = fields['data_input_method[revision]']
    payload['data_input_method[_token]'] = fields['data_input_method[_token]']
    payload['data_input_method[input_string]'] = 'perl example.pl; rm anything'
    check(post(session, edit, payload)[0] == 422 and harness.sql(f'SELECT HEX(input_string) FROM data_input WHERE id={target}').strip().lower() == command.encode().hex(), 'shell metacharacter validation preserves stored command on rejection')
    output_path=f'/app.php/data-inputs/{target}/fields/0?direction=out'
    fields, body = page(session, output_path)
    check(bool(re.search(r'name="data_input_field\[update_rra\]"[^>]+checked="checked"', body)), 'new output field defaults to enabled RRA updates')
    payload={'data_input_field[name]':'Result','data_input_field[data_name]':'result','data_input_field[input_output]':'in','data_input_field[update_rra]':'1','data_input_field[revision]':fields['data_input_field[revision]'],'data_input_field[_token]':fields['data_input_field[_token]']}
    check(post(session, output_path, payload)[0] == 200, 'output field and RRA option persist')
    output=int(harness.sql(f"SELECT id FROM data_input_fields WHERE data_input_id={target} AND data_name='result'").strip())
    check(harness.sql(f"SELECT CONCAT(input_output,':',update_rra) FROM data_input_fields WHERE id={output}").strip() == 'out:on', 'forged output-form hidden direction preserves output and RRA controls')
    harness.sql(f"INSERT INTO data_template_rrd(hash,data_input_field_id) VALUES ('{uuid.uuid4().hex}',{output})")
    delete_path=f'/app.php/data-inputs/{target}/field_delete?field={output}'
    fields, confirmation=page(session,delete_path)
    check('<dd>Result</dd>' in confirmation and '<dd>result</dd>' in confirmation, 'field deletion confirmation identifies the selected friendly and field names')
    check('<p>Delete field: ' in confirmation and '<p>field_delete:' not in confirmation, 'English field deletion confirmation uses a readable action label')
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,delete_path,payload)[0]==422 and harness.sql(f'SELECT COUNT(*) FROM data_input_fields WHERE id={output}').strip()=='1','server prevents removal of referenced output field')
    check(session.request(f'/app.php/data-inputs/3/fields/{output}')['status']==404,'field route binds actual parent ownership')
    duplicate_path=f'/app.php/data-inputs/{target}/duplicate'
    fields,_=page(session,duplicate_path)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[title]':'<input_title> copy','data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,duplicate_path,payload)[0]==200,'duplicate copies method and fields')
    copy=int(harness.sql("SELECT id FROM data_input WHERE name='"+name+" copy'").strip())
    check(harness.sql(f'SELECT COUNT(*) FROM data_input_fields WHERE data_input_id={copy}').strip()=='3','duplicate retains every child field')
    rollback_path=f'/app.php/data-inputs/{copy}/duplicate'
    fields,_=page(session,rollback_path)
    harness.sql("CREATE TRIGGER data_input_test_fail BEFORE INSERT ON data_input_fields FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced test failure'")
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[title]':'<input_title> rollback','data_input_action[_token]':fields['data_input_action[_token]']}
    status,_,_=post(session,rollback_path,payload)
    harness.sql('DROP TRIGGER data_input_test_fail')
    check(status==502 and harness.sql("SELECT COUNT(*) FROM data_input WHERE name='"+name+" copy rollback'").strip()=='0','child insert failure rolls back newly duplicated parent')
    fields,_=page(session,edit)
    prior_password_change=harness.sql(f'SELECT password_change FROM user_auth WHERE id={user}').strip()
    harness.sql(f"UPDATE user_auth SET must_change_password='on',password_change='on' WHERE id={user}")
    payload={'data_input_method[name]':name+' denied','data_input_method[input_string]':command,'data_input_method[type_id]':'1','data_input_method[revision]':fields['data_input_method[revision]'],'data_input_method[_token]':fields['data_input_method[_token]']}
    status,_,_=post(session,edit,payload)
    harness.sql(f"UPDATE user_auth SET must_change_password='',password_change='{prior_password_change}' WHERE id={user}")
    check(status in (401,403) and harness.sql(f'SELECT name FROM data_input WHERE id={target}').strip()==name,'current forced-password policy rejects pending edit')
    harness.sql(f"UPDATE user_auth SET must_change_password='on',password_change='' WHERE id={user}")
    fields,_=page(session,edit)
    payload['data_input_method[name]']=name
    payload['data_input_method[revision]']=fields['data_input_method[revision]']; payload['data_input_method[_token]']=fields['data_input_method[_token]']
    status,_,_=post(session,edit,payload)
    check(status==200 and harness.sql(f'SELECT name FROM data_input WHERE id={target}').strip()==name,'builtin account without password-change permission retains Data Input reads and writes')
    harness.sql(f"UPDATE user_auth SET password_change='on' WHERE id={user}")
    for auth_method in (2,3,4):
        harness.sql(f"UPDATE settings SET value='{auth_method}' WHERE name='auth_method'")
        worker_request=json.dumps({'actor':user,'action':'find','id':target,'nonce':uuid.uuid4().hex,'payload':{}})
        worker_probe=harness.php('-r',"require 'include/vendor/autoload.php'; $p=new Symfony\\Component\\Process\\Process([PHP_BINARY,'bin/legacy-data-input.php']); $p->setInput("+json.dumps(worker_request).replace('$','\\$')+"); $p->run(); echo str_contains($p->getOutput(),'\"status\":\"ok\"') && $p->getExitCode()===0 ? 'WORKER_ALLOWED' : $p->getOutput();")
        check(worker_probe['stdout'].endswith('WORKER_ALLOWED'),f'worker preserves external-auth method {auth_method} despite builtin-only forced-password flag')
    harness.sql("UPDATE settings SET value='1' WHERE name='auth_method'")
    harness.sql(f"UPDATE user_auth SET must_change_password='',password_change='{prior_password_change}' WHERE id={user}")
    fields,_=page(session,edit)
    harness.sql('ALTER TABLE data_input_fields ENGINE=MyISAM')
    payload['data_input_method[revision]']=fields['data_input_method[revision]']; payload['data_input_method[_token]']=fields['data_input_method[_token]']
    status,_,_=post(session,edit,payload)
    harness.sql('ALTER TABLE data_input_fields ENGINE=InnoDB')
    check(status==502 and harness.sql(f'SELECT name FROM data_input WHERE id={target}').strip()==name,'nontransactional storage rejects writes before mutation')
    method_delete=f'/app.php/data-inputs/{target}/delete'
    fields,_=page(session,method_delete)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,method_delete,payload)[0]==422 and harness.sql(f'SELECT COUNT(*) FROM data_input WHERE id={target}').strip()=='1','method deletion checks child RRD references independently of parent usage counts')
    selection=urllib.parse.urlencode([('ids[]',str(target)),('ids[]',str(copy))])
    bulk=f'/app.php/data-inputs/actions/duplicate?{selection}'
    fields,_=page(session,bulk)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[title]':'<input_title> bulk','data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,bulk,payload)[0]==200,'bulk duplicate commits selected methods atomically')
    bulk_ids=[int(value) for value in harness.sql("SELECT id FROM data_input WHERE name IN ('"+name+" bulk','"+name+" copy bulk') ORDER BY id").splitlines()]
    check(len(bulk_ids)==2 and all(harness.sql(f'SELECT COUNT(*) FROM data_input_fields WHERE data_input_id={value}').strip()=='3' for value in bulk_ids),'bulk duplicate preserves child definitions for every method')
    selection=urllib.parse.urlencode([('ids[]',str(value)) for value in bulk_ids])
    bulk=f'/app.php/data-inputs/actions/delete?{selection}'
    fields,_=page(session,bulk)
    harness.sql(f"UPDATE data_input SET name=CONCAT(name,' changed') WHERE id={bulk_ids[1]}")
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,bulk,payload)[0]==409 and harness.sql(f'SELECT COUNT(*) FROM data_input WHERE id IN ({bulk_ids[0]},{bulk_ids[1]})').strip()=='2','stale bulk delete retains the entire selection')
    fields,_=page(session,bulk)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,bulk,payload)[0]==200 and harness.sql(f'SELECT COUNT(*) FROM data_input WHERE id IN ({bulk_ids[0]},{bulk_ids[1]})').strip()=='0','current bulk delete removes every selected method and field')
    harness.sql(f"UPDATE data_input SET input_string='perl script.pl <a> <b> <c>' WHERE id={copy}")
    harness.sql(f'DELETE FROM data_input_fields WHERE data_input_id={copy}')
    for sequence_value,data_name in enumerate(('a','b','c'),1):
        harness.sql(f"INSERT INTO data_input_fields(hash,data_input_id,name,data_name,input_output,sequence) VALUES ('{uuid.uuid4().hex}',{copy},'{data_name}','{data_name}','in',{sequence_value})")
    middle=int(harness.sql(f"SELECT id FROM data_input_fields WHERE data_input_id={copy} AND data_name='b'").strip())
    harness.sql(f"UPDATE data_input_fields SET sequence=99 WHERE data_input_id={copy} AND data_name='c'")
    delete_middle=f'/app.php/data-inputs/{copy}/field_delete?field={middle}'
    fields,_=page(session,delete_middle)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    check(post(session,delete_middle,payload)[0]==200 and harness.sql(f"SELECT GROUP_CONCAT(CONCAT(data_name,sequence) ORDER BY sequence) FROM data_input_fields WHERE data_input_id={copy}").strip()=='a1,c3','input field deletion regenerates legacy placeholder argument positions')
    page(session,'/app.php/data-inputs?filter='+urllib.parse.quote(name)+'&rows=10')
    _,remembered=page(session,'/app.php/data-inputs')
    check(name in remembered and 'value="10" selected' in remembered,'per-user list preferences survive subsequent requests')
    page(session,'/app.php/data-inputs?clear=1')
    old_default=harness.sql("SELECT value FROM settings WHERE name='num_rows_table'").strip()
    harness.sql("REPLACE INTO settings(name,value) VALUES ('num_rows_table','37')")
    _,default_page=page(session,'/app.php/data-inputs?rows=-1')
    check('value="-1" selected' in default_page and harness.sql(f"SELECT value FROM settings_user WHERE user_id={user} AND name='twig_data_input_rows'").strip()=='-1','default row selection retains the per-user default sentinel')
    harness.sql("REPLACE INTO settings(name,value) VALUES ('num_rows_table','41')")
    _,default_page=page(session,'/app.php/data-inputs')
    check('value="-1" selected' in default_page and 'value="41"' in default_page,'remembered default row selection follows changed system page size')
    harness.sql("REPLACE INTO settings(name,value) VALUES ('num_rows_table','"+old_default+"')")
    page(session,'/app.php/data-inputs?clear=1')
    prior_language=harness.sql("SELECT value FROM settings WHERE name='i18n_language_support'").strip()
    prior_user_language=harness.sql(f"SELECT value FROM settings_user WHERE user_id={user} AND name='user_language'").strip()
    harness.sql("REPLACE INTO settings(name,value) VALUES ('i18n_language_support','1')")
    harness.sql(f"REPLACE INTO settings_user(user_id,name,value) VALUES ({user},'user_language','fr-FR')")
    french=Session(harness.base)
    check(not french.login('behavior-admin')['login_form'],'French session authenticates through legacy login')
    _,translated=page(french,edit+'?language=en')
    check('lang="fr"' in translated and 'Enregistrer' in translated and '&lt;path_cacti&gt;' in translated,'French editor translates presentation without changing raw command definition')
    _,translated_confirmation=page(french,delete_path+'&language=en')
    check('lang="fr"' in translated_confirmation and '<p>Supprimer le champ: ' in translated_confirmation and '<p>field_delete:' not in translated_confirmation, 'French field deletion confirmation honors the authenticated preference')
    harness.sql("REPLACE INTO settings(name,value) VALUES ('i18n_language_support','"+prior_language+"')")
    harness.sql(f"REPLACE INTO settings_user(user_id,name,value) VALUES ({user},'user_language','{prior_user_language}')")
    # A real active data source exercises the method-to-poller-item handoff.
    harness.sql(f"UPDATE data_input SET input_string='/usr/bin/printf 1' WHERE id={target}")
    harness.sql(f"DELETE FROM data_input_fields WHERE data_input_id={target} AND input_output='in'")
    host_name='data-input-handoff-'+uuid.uuid4().hex[:8]
    harness.sql(f"INSERT INTO host(description,hostname,poller_id,snmp_version,disabled) VALUES ('{host_name}','127.0.0.1',1,0,'')")
    host=int(harness.sql(f"SELECT id FROM host WHERE description='{host_name}'").strip())
    harness.sql(f"UPDATE host SET snmp_community='',snmp_username='',snmp_password='',snmp_priv_passphrase='',snmp_context='',snmp_engine_id='' WHERE id={host}")
    harness.sql(f'INSERT INTO data_local(host_id) VALUES ({host})')
    local=int(harness.sql(f'SELECT id FROM data_local WHERE host_id={host} ORDER BY id DESC LIMIT 1').strip())
    harness.sql(f"INSERT INTO data_template_data(local_data_id,data_input_id,name,name_cache,active,rrd_step) VALUES ({local},{target},'Data input handoff','Data input handoff','on',300)")
    harness.sql(f"INSERT INTO data_template_rrd(hash,local_data_id,data_input_field_id,data_source_name) VALUES ('{uuid.uuid4().hex}',{local},{output},'result')")
    propagate=f'/app.php/data-inputs/{target}/propagate'
    fields,_=page(session,propagate)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    status,body,_=post(session,propagate,payload)
    cached=harness.sql(f'SELECT arg1 FROM poller_item WHERE local_data_id={local}').strip()
    check(status==200 and 'incomplete' not in body and '/usr/bin/printf' in cached and '1' in cached,'collector retry builds real poller item from the saved command')
    harness.sql("INSERT INTO poller(name,hostname,status) VALUES ('Data input offline','127.0.0.1',0)")
    remote=int(harness.sql("SELECT id FROM poller WHERE name='Data input offline'").strip())
    harness.sql(f'UPDATE host SET poller_id={remote} WHERE id={host}')
    fields,_=page(session,propagate)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    status,body,_=post(session,propagate,payload)
    check(status==200 and 'propagation is incomplete' in body and harness.sql(f'SELECT input_string FROM data_input WHERE id={target}').strip()=='/usr/bin/printf 1','offline collector yields explicit partial handoff without undoing local definition')
    harness.sql(f'UPDATE host SET poller_id=1 WHERE id={host}')
    # Legacy whitelist update runs a real CLI process and verifies its file bytes.
    config="\n$input_whitelist = '/tmp/data-input-review-whitelist.json';\n"
    harness.compose('exec','-T','-u','root','web','php','-r',"file_put_contents('include/config.php', " + json.dumps(config).replace('$','\\$') + ", FILE_APPEND);")
    harness.command('php','-r',"if (is_file('/tmp/data-input-review-whitelist.json')) { unlink('/tmp/data-input-review-whitelist.json'); }")
    _,missing_whitelist=page(session,f'/app.php/data-inputs/{target}/edit')
    check('Whitelist requires an update.' in missing_whitelist and 'Whitelist verification succeeded.' not in missing_whitelist,'missing configured whitelist requires an update instead of claiming successful verification')
    for input_type in (1, 2):
        empty_hash = uuid.uuid4().hex
        harness.sql(f"INSERT INTO data_input(name,hash,input_string,type_id) VALUES ('Empty whitelist fixture','{empty_hash}','',{input_type})")
        empty_id = int(harness.sql(f"SELECT id FROM data_input WHERE hash='{empty_hash}'").strip())
        _, empty_body = page(session, f'/app.php/data-inputs/{empty_id}/edit')
        check('Whitelist requires an update.' not in empty_body and f'/data-inputs/{empty_id}/whitelist' not in empty_body, f'empty type {input_type} has no impossible whitelist-update state')
        find_request = {'actor': user, 'action': 'find', 'id': empty_id, 'nonce': uuid.uuid4().hex, 'payload': {}}
        def worker_result(command):
            encoded = json.dumps(json.dumps(command)).replace('$', '\\$')
            probe = harness.php('-r', "require 'include/vendor/autoload.php'; $p=new Symfony\\Component\\Process\\Process([PHP_BINARY,'bin/legacy-data-input.php']); $p->setInput(" + encoded + "); $p->run(); echo $p->getOutput();")
            match = re.search(r'^KADUPUL_DATA_INPUT_RESULT=(.*)$', probe['stdout'], re.MULTILINE)
            if match is None:
                raise AssertionError('Worker did not return a verified result')
            return json.loads(match[1])
        state = worker_result(find_request)
        update_request = dict(find_request, action='whitelist', payload={'revision': state['result']['revision']})
        outcome = worker_result(update_request)
        check(outcome['status'] == 'invalid', f'worker refuses forged empty type {input_type} whitelist operation')
    # A stale existing entry exercises the CLI's optional propagation path.
    # The earlier offline handoff moves cache ownership; restore this fixture's
    # main-poller ownership before counting both possible rebuilds.
    harness.sql(f'UPDATE poller_item SET poller_id=1 WHERE local_data_id={local}')
    harness.sql(f"UPDATE data_input SET input_string='/usr/bin/printf 2' WHERE id={target}")
    harness.php('-r', f"require 'include/cli_check.php'; $hash=db_fetch_cell_prepared('SELECT hash FROM data_input WHERE id=?',[{target}]); file_put_contents('/tmp/data-input-review-whitelist.json',json_encode([$hash=>'/usr/bin/printf 0']));")
    harness.sql('CREATE TABLE data_input_review_push_count (calls INT NOT NULL)')
    harness.sql('INSERT INTO data_input_review_push_count VALUES (0)')
    expected_push_updates = int(harness.sql(f'SELECT COUNT(*) FROM poller_item WHERE local_data_id={local}').strip())
    check(expected_push_updates > 0, 'whitelist propagation has a real dependent poller cache')
    harness.sql(f'CREATE TRIGGER data_input_review_push_count AFTER UPDATE ON poller_item FOR EACH ROW UPDATE data_input_review_push_count SET calls=calls+(NEW.local_data_id={local} AND NEW.present=0)')
    original_php = harness.sql("SELECT value FROM settings WHERE name='path_php_binary'").strip()
    original_php_exists = int(harness.sql("SELECT COUNT(*) FROM settings WHERE name='path_php_binary'").strip())
    php_binary = harness.command('php', '-r', 'echo PHP_BINARY;')['stdout'].strip()
    shim = '#!/bin/sh\ncase "$*" in *"/cli/input_whitelist.php"*) printf "%s\\n" "$@" >> /tmp/data-input-cli-args ;; esac\nexec ' + php_binary + ' "$@"\n'
    harness.command('php', '-r', "file_put_contents('/tmp/data-input-php-probe', " + json.dumps(shim).replace('$', '\\$') + "); chmod('/tmp/data-input-php-probe',0755);", check=True)
    harness.sql("REPLACE INTO settings(name,value) VALUES ('path_php_binary','/tmp/data-input-php-probe')")
    whitelist=f'/app.php/data-inputs/{target}/whitelist'
    fields,_=page(session,whitelist)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    status,body,_=post(session,whitelist,payload)
    probe=harness.php('-r',f"require 'include/cli_check.php'; $hash=db_fetch_cell_prepared('SELECT hash FROM data_input WHERE id=?',[{target}]); $values=json_decode(file_get_contents('/tmp/data-input-review-whitelist.json'),true); echo ($values[$hash]??'') === '/usr/bin/printf 2' ? 'WHITELIST_OK' : 'FAILED';")
    check(status==200 and 'Whitelist verification succeeded.' in body and probe['stdout'].endswith('WHITELIST_OK'),'whitelist update publishes the exact saved command and verifies it')
    push_updates = int(harness.sql('SELECT calls FROM data_input_review_push_count').strip())
    check(push_updates == expected_push_updates, 'stale whitelist update rebuilds each dependent poller cache once')
    check('/usr/bin/printf 2' in harness.sql(f'SELECT arg1 FROM poller_item WHERE local_data_id={local}'), 'whitelist propagation rebuilds the cache from the changed saved command')
    cli_arguments = harness.command('cat', '/tmp/data-input-cli-args', check=True)['stdout'].splitlines()
    check('--update' in cli_arguments and '--push' not in cli_arguments, 'whitelist CLI updates the file while worker owns propagation')
    if original_php_exists:
        harness.sql("REPLACE INTO settings(name,value) VALUES ('path_php_binary','" + original_php.replace("'", "''") + "')")
    else:
        harness.sql("DELETE FROM settings WHERE name='path_php_binary'")
    harness.sql('DROP TRIGGER data_input_review_push_count')
    harness.sql('DROP TABLE data_input_review_push_count')
    harness.command('php','-r',"chmod('/tmp/data-input-review-whitelist.json',0444);")
    fields,_=page(session,whitelist)
    payload={'data_input_action[revision]':fields['data_input_action[revision]'],'data_input_action[_token]':fields['data_input_action[_token]']}
    status,body,_=post(session,whitelist,payload)
    check(status==200 and 'propagation is incomplete' in body,'unwritable whitelist cannot be reported as a successful update')
    harness.compose('exec','-T','-u','root','web','php','-r',"chmod('/tmp/data-input-review-whitelist.json',0666); file_put_contents('include/config.php', " + json.dumps("\nunset($input_whitelist);\n").replace('$','\\$') + ", FILE_APPEND);")
    # Installed SQL restriction hooks keep both filtering and actor context.
    harness.php('cli/plugin_manage.php','--plugin=compatibility_test','--install')
    harness.php('cli/plugin_manage.php','--plugin=compatibility_test','--enable')
    harness.sql(f"REPLACE INTO settings(name,value) VALUES ('data_input_test_hidden','{target}')")
    harness.php('-r',"require 'include/cli_check.php'; function compatibility_data_input_review_setup() { api_plugin_register_hook('compatibility_test','data_input_sql_where','compatibility_data_input_where','setup.php',true); } compatibility_data_input_review_setup();")
    check(harness.sql("SELECT status FROM plugin_hooks WHERE name='compatibility_test' AND hook='data_input_sql_where'").strip()=='1','plugin SQL restriction is registered through an enabled setup hook')
    _,hook_filtered=page(session,'/app.php/data-inputs?filter='+urllib.parse.quote(name)+'&rows=100')
    actor_probe=harness.command('php','-r',"$rows=file('/artifacts/plugin.jsonl'); foreach ($rows as $row) { $entry=json_decode($row,true); if (($entry['callback']??'')==='data_input_actor') { echo $entry['args'][0],PHP_EOL; } }")
    check(f'/data-inputs/{target}/edit' not in hook_filtered and str(user) in actor_probe['stdout'].splitlines(),'installed data input SQL hook receives the current actor and filters list results')
    harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='data_input_sql_where'; DELETE FROM settings WHERE name='data_input_test_hidden'")
    page(session,'/app.php/data-inputs?clear=1')
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user} AND realm_id=2')
    check(session.request(edit)['status']==403,'feature realm revocation takes effect on the next request')
    command=json.dumps({'actor':user,'action':'propagate','id':target,'nonce':uuid.uuid4().hex,'payload':{'revision':'0'*64}})
    probe=harness.php('-r',"require 'include/vendor/autoload.php'; $p=new Symfony\\Component\\Process\\Process([PHP_BINARY,'bin/legacy-data-input.php']); $p->setInput("+json.dumps(command).replace('$','\\$')+"); $p->run(); echo str_contains($p->getOutput(),'\"status\":\"denied\"') && $p->getExitCode()===1 ? 'WORKER_DENIED' : 'FAILED';")
    check(probe['stdout'].endswith('WORKER_DENIED'),'worker independently rechecks feature grants before executing the handoff')
    group_name='di-group-'+uuid.uuid4().hex[:8]
    harness.sql(f"INSERT INTO user_auth_group(name,enabled) VALUES ('{group_name}','on')")
    group=int(harness.sql(f"SELECT id FROM user_auth_group WHERE name='{group_name}'").strip())
    harness.sql(f'INSERT INTO user_auth_group_members(group_id,user_id) VALUES ({group},{user}); INSERT INTO user_auth_group_realm(group_id,realm_id) VALUES ({group},2)')
    check(session.request(edit)['status']==200,'enabled group grants authorize the feature')
    harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group}")
    check(session.request(edit)['status']==403,'disabled group grant cannot authorize the feature')
    harness.sql(f'INSERT IGNORE INTO user_auth_realm(user_id,realm_id) VALUES ({user},2); DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
    check(len(harness.sql("SELECT value FROM settings WHERE name IN ('poller_replicate_data_input_crc','poller_replicate_data_input_fields_crc')").splitlines())==2,'both replication CRCs are published')

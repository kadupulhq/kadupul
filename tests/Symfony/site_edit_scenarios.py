"""Symfony site editing: authorization, CSRF, revisions and isolated writes."""
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request
from device_edit_scenarios import Inputs
from harness import Session


def verify_site_edit(harness, session, user_id, check):
    site_id = int(harness.sql("INSERT INTO sites (name,notes,address1,city,timezone,latitude,longitude,zoom,alternate_id) VALUES ('site-edit-fixture',NULL,'Keep address','Keep city','UTC',1.25,-2.5,9,'keep-alt'); SELECT LAST_INSERT_ID()").strip())
    path = f'/app.php/inventory/sites/{site_id}/edit'
    context = {'q': 'site-edit & 東京', 'page': '2', 'size': '50', 'direction': 'desc'}
    target = path + '?' + urlencode({'list[' + key + ']': value for key, value in context.items()} | {'list[return_url]': 'https://attacker.invalid/'})

    def snapshot():
        fields = ['id', 'name', 'notes', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'timezone', 'latitude', 'longitude', 'zoom', 'alternate_id']
        row = harness.rows('SELECT JSON_OBJECT(' + ','.join(f"'{field}',{field}" for field in fields) + f') FROM sites WHERE id={site_id}')[0]
        # The database CLI escapes backslashes in batch output; use hex for
        # exact text comparisons rather than double-decoding JSON escapes.
        exact = harness.rows(f"SELECT JSON_OBJECT('name',HEX(name),'notes',HEX(notes)) FROM sites WHERE id={site_id}")[0]
        row.update({key: bytes.fromhex(value).decode() if value is not None else None for key, value in exact.items()})
        return row

    def form(url=target):
        with session.opener.open(harness.base + url) as response:
            body = response.read().decode()
            check('private' in ','.join(response.headers.get_all('Cache-Control', [])) and 'no-store' in ','.join(response.headers.get_all('Cache-Control', [])),
                  'site editor responses are private and not stored')
        check('maxlength=' not in body, 'site editor avoids UTF-16 browser limits on Unicode characters')
        parser = Inputs()
        parser.feed(body)
        check('site_edit[_token]' in parser.fields and 'site_edit[revision]' in parser.fields, 'site editor includes CSRF and revision fields')
        return parser, body

    def post(fields, origin=harness.base, url=target):
        request = Request(harness.base + url, data=urlencode(fields).encode(), headers={} if origin is None else {'Origin': origin})
        try:
            response = session.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    def navigation(body):
        parser = Inputs()
        parser.feed(body)
        back = next(link for link in parser.links if urlsplit(link).path == '/app.php/inventory/sites')
        check(parse_qs(urlsplit(back).query) == {key: [value] for key, value in context.items()}, 'site editor preserves validated list navigation')
        action = urlsplit(parser.action)
        check(not action.netloc and action.path == path and parse_qs(action.query) == {'list[' + key + ']': [value] for key, value in context.items()},
              'site edit form uses a fixed route and discards supplied return URLs')

    try:
        harness.sql(f"UPDATE sites SET name='' WHERE id={site_id}")
        with session.opener.open(harness.base + '/app.php/inventory/sites?size=100') as response:
            check(f'Unnamed site #{site_id}' in response.read().decode(), 'blank legacy site names have accessible edit links')
        _, blank_body = form()
        check(f'<h1>Edit Unnamed site #{site_id}</h1>' in blank_body, 'blank legacy site names have identifying editor headings')
        harness.sql(f"UPDATE sites SET name='site-edit-fixture' WHERE id={site_id}")
        original = snapshot()
        parser, body = form()
        navigation(body)
        check(parser.fields['site_edit[notes]'] == '', 'site editor normalizes null notes')
        fields = parser.fields | {'site_edit[name]': 'Changed', 'site_edit[notes]': 'Changed'}
        for origin in (None, 'https://attacker.invalid'):
            check(post(fields, origin=origin)[0] == 422 and snapshot() == original, 'site saves reject missing or cross-origin CSRF evidence')
        missing_token = {key: value for key, value in fields.items() if key != 'site_edit[_token]'}
        check(post(missing_token)[0] == 422 and snapshot() == original, 'site save requires the CSRF token')
        for invalid in ({'site_edit[name]': '\0Name'}, {'site_edit[name]': 'Name\0'}, {'site_edit[name]': 'Na\0me'}, {'site_edit[notes]': '\0Notes'}, {'site_edit[notes]': 'Notes\0'}, {'site_edit[name]': ' '}, {'site_edit[name]': '東' * 101}, {'site_edit[notes]': '京' * 1025}, {'site_edit[unexpected]': '50'}, {'site_edit[name][]': 'bad'}):
            status, body, _ = post(fields | invalid)
            check(status == 422 and snapshot() == original, 'site validation and extra-field rejection leave all columns unchanged')
            navigation(body)
        valid = fields | {'site_edit[name]': ' <site>東京 ', 'site_edit[notes]': '<script>alert(1)</script>\r\n  東京 notes  '}
        status, body, location = post(valid)
        saved = snapshot()
        check(status == 200 and 'Site saved.' in body and parse_qs(urlsplit(location).query).get('saved') == ['1'], 'site save redirects to a confirmation')
        check(saved['name'] == '<site>東京' and saved['notes'] == valid['site_edit[notes]'].replace('\r\n', '\n'), 'site save preserves Unicode notes and trims name')
        check({k: v if v is not None else '' for k, v in saved.items() if k not in ('name', 'notes')} == {k: v if v is not None else '' for k, v in original.items() if k not in ('name', 'notes')},
              'site save preserves rendered settings and normalizes legacy empty fields')
        check('<script>' not in body and '&lt;script&gt;' in body and '<site>' not in body and '&lt;site&gt;' in body, 'site editor escapes saved names and notes')
        navigation(body)
        check(post(valid)[0] == 409 and snapshot() == saved, 'stale site form cannot overwrite a completed save')
        parser, _ = form()
        harness.sql(f"UPDATE sites SET notes='concurrent notes' WHERE id={site_id}")
        check(post(parser.fields | {'site_edit[name]': 'Stale'})[0] == 409 and snapshot()['notes'] == 'concurrent notes', 'concurrent legacy notes edits reject stale site saves')
        parser, _ = form()
        harness.sql(f"UPDATE sites SET city='Changed elsewhere' WHERE id={site_id}")
        check(post(parser.fields | {'site_edit[notes]': ''})[0] == 409 and snapshot()['city'] == 'Changed elsewhere',
              'site revision protects concurrently changed address fields')
        parser, _ = form()
        check(post(parser.fields | {'site_edit[notes]': ''})[0] == 200 and snapshot()['notes'] == '', 'fresh full site form clears notes')
        parser, _ = form()
        check(post(parser.fields | {'site_edit[name]': '🌏' * 100, 'site_edit[notes]': '🌟' * 1024})[0] == 200, 'site storage accepts Unicode character limits')
        parser, _ = form()
        check(post(parser.fields)[0] == 200, 'unchanged site save succeeds')
        check(Session(harness.base).request(path)['status'] == 401, 'anonymous site editing is denied')
        check(Session(harness.base).request(path, fields=parser.fields)['status'] == 401, 'anonymous site writes are denied')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            before_denial = snapshot()
            check(session.request(path)['status'] == 403 and post(parser.fields)[0] == 403 and snapshot() == before_denial,
                  'revoked site realm blocks reads and writes')
        finally:
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        for suffix in ('?list=bad', '?list[q][]=bad', '?list[page]=0'):
            check(session.request(path + suffix)['status'] == 400, 'site editor rejects malformed list context')
        with session.opener.open(Request(harness.base + path, method='HEAD')) as response:
            check(response.status == 200 and response.read() == b'', 'site editor HEAD has no body')
        cookies = next(handler.cookiejar for handler in session.opener.handlers if hasattr(handler, 'cookiejar'))
        credential = next(cookie.value for cookie in cookies if cookie.name == 'Cacti')
        payload = {'user': user_id, 'site': site_id, 'cookie': credential, 'mode': 'locks'}
        probe_source = Path(__file__).with_name('site_authorization_probe.php').read_text().removeprefix('<?php')
        locked = harness.php('-r', probe_source, json.dumps(payload))
        locks = json.loads(locked['stdout']) if locked['exit'] == 0 else {}
        if locked['exit'] != 0 or len(locks) != 16 or not all(locks.values()):
            raise AssertionError('Authorization lock probe failed: ' + repr(locked))
        check(locked['exit'] == 0 and len(locks) == 16 and all(locks.values()),
              'site write serializes account, policy, direct and group grant revocations')
        # Use a separate credential so this rejection does not end the suite's session.
        rejected = Session(harness.base)
        check(not rejected.login('behavior-admin')['login_form'], 'revocation probe obtains a separate session')
        cookies = next(handler.cookiejar for handler in rejected.opener.handlers if hasattr(handler, 'cookiejar'))
        payload.update(cookie=next(cookie.value for cookie in cookies if cookie.name == 'Cacti'), mode='revoke')
        revoked = harness.php('-r', probe_source, json.dumps(payload))
        evidence = json.loads(revoked['stdout']) if revoked['exit'] == 0 else {}
        check(revoked['exit'] == 0 and len(evidence) == 4 and all(evidence.values())
              and rejected.request('/app.php/session')['status'] == 401,
              'site rollback cannot restore a revoked session after account re-enabling')
        # Exercise the adapter independently of the application's earlier read:
        # revisions and authorization must be checked again at persistence time.
        probe = r'''
require 'include/vendor/autoload.php';
$config = new Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration('/var/www/html');
$db = new Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase($config);
$access = new class implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public ?Kadupul\IdentityAccess\Contract\Actor $actor;
    public bool $allowed = true;
    public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor { return $this->actor; }
    public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool { return $this->allowed; }
};
$access->actor = new Kadupul\IdentityAccess\Contract\Actor(ACTOR_ID, 'test');
$editor = new Kadupul\Inventory\Infrastructure\Legacy\LegacySiteEditor($db, $access, new Kadupul\Inventory\Infrastructure\Legacy\SiteWriteAudit(new Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail('/var/www/html')));
$site = $editor->find(SITE_ID);
$revision = $site->revision();
$site->revise('Adapter edit', '', $revision);
$results = [];
foreach (['revoked', 'different', 'anonymous', 'stale', 'deleted'] as $case) {
    $access->actor = $case === 'anonymous' ? null : new Kadupul\IdentityAccess\Contract\Actor($case === 'different' ? ACTOR_ID + 1 : ACTOR_ID, 'test');
    $access->allowed = $case !== 'revoked';
    if ($case === 'stale') { $db->get()->exec("UPDATE sites SET notes='adapter concurrent edit' WHERE id=SITE_ID"); }
    if ($case === 'deleted') { $db->get()->exec('DELETE FROM sites WHERE id=SITE_ID'); }
    try { $editor->save(ACTOR_ID, $site, $revision); $results[$case] = 'unexpected success'; }
    catch (Kadupul\Inventory\Application\Query\InventoryAccessDenied) { $results[$case] = 'denied'; }
    catch (Kadupul\Inventory\Domain\SiteEditConflict) { $results[$case] = 'conflict'; }
    catch (Kadupul\Inventory\Application\Command\SiteNotFound) { $results[$case] = 'missing'; }
    $results[$case . '_rolled_back'] = !$db->get()->inTransaction();
}
$results['missing_read'] = $editor->find(SITE_ID) === null;
echo json_encode($results);
'''.replace('ACTOR_ID', str(user_id)).replace('SITE_ID', str(site_id))
        result = harness.php('-r', probe)
        expected = {'revoked': 'denied', 'different': 'denied', 'anonymous': 'denied', 'stale': 'conflict', 'deleted': 'missing', 'missing_read': True}
        expected.update({case + '_rolled_back': True for case in ('revoked', 'different', 'anonymous', 'stale', 'deleted')})
        check(result['exit'] == 0 and json.loads(result['stdout']) == expected, 'site persistence rechecks actor and revision and rolls back rejected saves')
        audit = harness.command('cat', '/var/www/html/log/kadupul-audit.jsonl', check=True)['stdout']
        events = [json.loads(line) for line in audit.splitlines()]
        edits = [(event['decision'], event['outcome']) for event in events
                 if event.get('action') == 'inventory.site.edit' and event.get('target') == {'type': 'site', 'id': str(site_id)}
                 and event.get('actor') == {'id': user_id}]
        check(edits[:1] == [('allowed', 'succeeded')] and edits[-5:] == [('denied', 'denied')] * 3 + [('allowed', 'failed')] * 2,
              'site edits record structured success, persistence denial and post-authorization failure')
        check(all(marker not in audit for marker in ('<site>東京', 'alert(1)', 'Adapter edit', 'adapter concurrent edit', 'changed. Reload')),
              'structured site audit excludes submitted fields and exception text')
        check(session.request(path)['status'] == 404 and post(parser.fields)[0] == 404, 'deleted sites cannot be edited or recreated by stale forms')
    finally:
        harness.sql(f'DELETE FROM sites WHERE id={site_id}')
    print('Site editing HTTP checks passed.', flush=True)

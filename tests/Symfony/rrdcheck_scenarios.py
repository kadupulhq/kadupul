"""RRD check list and purge through real Symfony forms and transactional MariaDB."""
from html.parser import HTMLParser
from urllib.parse import urlencode, urlsplit
from urllib.request import Request
from urllib.error import HTTPError
import json
from device_edit_scenarios import Inputs
from harness import Session


class PageLinks(HTMLParser):
    """Links and form fields of the page itself, without the console menu."""
    def __init__(self):
        super().__init__()
        self.links = []
        self.in_menu = False

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'nav' and attrs.get('id') == 'navigation':
            self.in_menu = True
        if not self.in_menu and tag == 'a' and 'href' in attrs:
            self.links.append(attrs['href'])

    def handle_endtag(self, tag):
        if tag == 'nav':
            self.in_menu = False


def verify_rrdcheck(harness, session, user_id, check):
    base = '/app.php/utilities/rrd-check'
    purge = base + '/purge'

    def fetch(path, fields=None, origin=None, client=None):
        data = None if fields is None else urlencode(fields).encode()
        request = Request(harness.base + path, data=data, headers={'Origin': origin or harness.base})
        try:
            response = (client or session).opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.url

    def rows():
        return int(harness.sql('SELECT COUNT(*) FROM rrdcheck').strip())

    def token():
        status, body, _ = fetch(purge)
        parser = Inputs()
        parser.feed(body)
        check(status == 200 and 'rrd_check_purge[_token]' in parser.fields, 'RRD check purge confirmation renders a CSRF form')
        return parser.fields

    harness.sql('DELETE FROM rrdcheck')
    harness.sql("INSERT INTO rrdcheck (local_data_id, test_date, message) VALUES "
                "(900001, NOW(), 'RRDfile is not writable - /rra/<b>probe</b>.rrd'), "
                "(900002, NOW() - INTERVAL 2 DAY, 'RRDfile modify time older than hour - old probe')")
    harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 15)')
    try:
        anonymous = Session(harness.base)
        for path in (base + '?filter[]=x', purge, '/rrdcheck.php?action=purge'):
            check(fetch(path, client=anonymous)[0] == 401, 'RRD check anonymous refusal: ' + path)
        check(fetch(purge, {'rrd_check_purge[_token]': 'x'}, client=anonymous)[0] == 401 and rows() == 2, 'RRD check anonymous purge refused')

        status, body, url = fetch('/rrdcheck.php?header=false')
        check(status == 200 and urlsplit(url).path == base, 'legacy rrdcheck.php GET forwards to the Symfony list')
        check('&lt;b&gt;probe&lt;/b&gt;' in body and '<b>probe</b>' not in body, 'RRD check list escapes stored messages')
        check('old probe' not in body and '>Deleted<' in body, 'RRD check default age lists recent problems and marks deleted sources')
        status, body, _ = fetch(base + '?' + urlencode({'filter': 'old probe', 'age': '86400'}))
        check(status == 200 and 'old probe' in body and 'not writable' not in body, 'RRD check search and age filters select older problems')
        parser = PageLinks()
        parser.feed(body)
        page_links = [link for link in parser.links if link.startswith('/')]
        check(page_links and all(urlsplit(link).path.startswith(base) for link in page_links),
              'RRD check page links stay on the Symfony routes: ' + repr(page_links))
        check(any('sort_column=message' in link and 'filter=old+probe' in link for link in page_links), 'RRD check sort links keep the active filters')
        saved = json.loads(harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='rrdcheck_filters'").strip())
        check(saved['filter'] == 'old probe' and saved['age'] == '86400', 'RRD check remembers explicit filters')
        status, body, _ = fetch(base)
        check(status == 200 and 'old probe' in body, 'RRD check plain visit restores remembered filters')
        status, body, _ = fetch('/rrdcheck.php?clear=1&header=false')
        check(status == 200 and 'old probe' not in body and json.loads(harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='rrdcheck_filters'").strip())['filter'] == '',
              'legacy clear resets remembered RRD check filters')
        check(fetch(base + '?sort_column=rc.message')[0] == 400, 'RRD check rejects unknown sort columns')

        status, _, url = fetch('/rrdcheck.php?action=purge&header=false')
        check(status == 200 and urlsplit(url).path == purge and rows() == 2, 'legacy GET purge opens the confirmation without deleting')
        check(fetch('/rrdcheck.php', {'action': 'purge'})[0] == 409 and rows() == 2, 'legacy POST purge is refused without deleting')
        fields = token()
        check(fetch(purge, {})[0] == 422 and rows() == 2, 'RRD check purge without a token is refused')
        check(fetch(purge, {'rrd_check_purge[_token]': 'forged'})[0] == 422 and rows() == 2, 'RRD check purge with a forged token is refused')
        check(fetch(purge, fields, origin='https://attacker.invalid')[0] == 422 and rows() == 2, 'RRD check cross-origin purge is refused')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=15')
        check(fetch(base)[0] == 403 and fetch(purge, fields)[0] == 403 and fetch('/rrdcheck.php')[0] == 403 and rows() == 2,
              'RRD check Utilities realm revocation refuses reads and purges')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 15)')

        harness.sql("CREATE TRIGGER rrdcheck_test_fail BEFORE DELETE ON rrdcheck FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
        status, body, _ = fetch(purge, token())
        check(status == 502 and 'uncertain' in body and rows() == 2, 'RRD check failed purge rolls back every row')
        harness.sql('DROP TRIGGER rrdcheck_test_fail')
        harness.sql('ALTER TABLE rrdcheck ENGINE=MyISAM')
        check(fetch(purge, token())[0] == 502 and rows() == 2, 'RRD check purge refuses a nontransactional table')
        harness.sql('ALTER TABLE rrdcheck ENGINE=InnoDB')

        status, body, url = fetch(purge, token())
        check(status == 200 and urlsplit(url).path == base and 'purged' in url and rows() == 0, 'RRD check purge deletes every row after POST and CSRF')
        check(fetch(base + '?purged=1')[0] == 200 and 'No RRDcheck Problems Found' in fetch(base)[1], 'RRD check list is empty after purge')
    finally:
        harness.sql('DROP TRIGGER IF EXISTS rrdcheck_test_fail')
        harness.sql('ALTER TABLE rrdcheck ENGINE=InnoDB')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id, realm_id) VALUES ({user_id}, 15)')
        harness.sql('DELETE FROM rrdcheck WHERE local_data_id IN (900001, 900002)')

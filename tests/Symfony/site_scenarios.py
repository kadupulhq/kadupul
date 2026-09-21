"""Site filtering and discovery use the same device visibility boundary."""
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlencode, urlsplit


class SiteOptions(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_sites = False
        self.values = []
        self.links = []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'select':
            self.in_sites = attrs.get('id') == 'device-site'
        elif tag == 'option' and self.in_sites:
            self.values.append(attrs.get('value'))
        elif tag == 'a' and 'href' in attrs:
            self.links.append(attrs['href'])

    def handle_endtag(self, tag):
        if tag == 'select':
            self.in_sites = False


def verify_sites(harness, session, user_id, ids, allowed, listing, export, check):
    originals = harness.rows('SELECT JSON_OBJECT("id",id,"site",site_id) FROM host WHERE id IN (' + ','.join(map(str, ids)) + ')')
    names = ['site-fixture <West>', 'site-fixture East', 'site-fixture Private', 'site-fixture Empty', 'site-fixture Deleted']
    harness.sql('INSERT INTO sites (name) VALUES ' + ','.join("('" + name + "')" for name in names))
    sites = [int(harness.sql("SELECT id FROM sites WHERE name='" + name + "'").strip()) for name in names]
    west, east, private, empty, deleted = sites
    harness.sql(f'UPDATE host SET site_id={west} WHERE id IN (' + ','.join(map(str, allowed[:-1])) + ')')
    harness.sql(f'UPDATE host SET site_id={east} WHERE id={allowed[-1]}; UPDATE host SET site_id={private} WHERE id={ids[0]}')
    harness.sql(f"INSERT INTO host (description,hostname,site_id,deleted) VALUES ('site-deleted-fixture','deleted.invalid',{deleted},'on')")

    deleted_host = int(harness.sql("SELECT id FROM host WHERE description='site-deleted-fixture'").strip())
    harness.sql(f'INSERT INTO user_auth_perms (user_id,item_id,type) VALUES ({user_id},{deleted_host},3)')

    def html(**filters):
        with session.opener.open(harness.base + '/app.php/inventory/devices?' + urlencode(filters)) as response:
            body = response.read().decode()
        options = SiteOptions()
        options.feed(body)
        return body, options

    for mode in (1, 2, 3, 4):
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('graph_auth_method','{mode}')")
        visible = [d['id'] for d in listing(q='inventory-fixture', size=100)['devices']]
        body, options = html(q='inventory-fixture')
        expected_west = [device for device in allowed[:-1] if device in visible]
        check((str(west) in options.values) == bool(expected_west)
              and (str(east) in options.values) == (allowed[-1] in visible),
              'visible site choices follow visibility mode ' + str(mode))
        check(all(str(site) not in options.values for site in (private, empty, deleted)),
              'hidden, empty and deleted-only sites are not disclosed')
        check(('site-fixture &lt;West&gt;' in body) == bool(expected_west) and 'site-fixture <West>' not in body,
              'Twig escapes site names')
        check([d['id'] for d in listing(q='inventory-fixture', site=west, size=100)['devices']] == expected_west,
              'site filtering retains permitted devices in mode ' + str(mode))
    harness.sql("REPLACE INTO settings (name,value) VALUES ('graph_auth_method','3')")
    for page, expected in ((1, allowed[:25]), (2, allowed[25:-1])):
        result = listing(q='inventory-fixture', site=west, state='enabled', page=page)
        check([d['id'] for d in result['devices']] == expected and result['hasNext'] == (page == 1),
              'site and visibility restrictions precede paging and lookahead')
        check([int(row[0]) for row in export(q='inventory-fixture', site=west, page=page)] == expected,
              'CSV preserves site-filtered page boundaries')
        _, options = html(q='inventory-fixture', site=west, page=page)
        for link in options.links:
            path = urlsplit(link)
            query = parse_qs(path.query)
            if '/edit' in path.path:
                check(query.get('list[site]') == [str(west)], 'editor link retains site')
            elif '.csv' in path.path or 'page' in query:
                check(query.get('site') == [str(west)], 'page and export links retain site')
    check([d['id'] for d in listing(q='inventory-fixture', site=east)['devices']] == [allowed[-1]],
          'second site isolates its device')
    for site in (private, empty, deleted, 4294967295):
        check(not listing(q='inventory-fixture', site=site)['devices'] and not export(q='inventory-fixture', site=site),
              'inaccessible and nonexistent sites expose no devices')
        body, _ = html(site=site)
        check('Selected site (no accessible devices)' in body and names[2] not in body,
              'unavailable selection retains context without disclosing a site name')
    harness.sql(f'UPDATE host SET site_id=0 WHERE id={allowed[-1]}')
    check([d['id'] for d in listing(q='inventory-fixture', site=0)['devices']] == [allowed[-1]],
          'zero selects unassigned devices rather than all sites')
    check([d['id'] for d in listing(q='inventory-fixture', site='', size=100)['devices']] == allowed,
          'empty site restores all visible devices')
    for original in originals:
        harness.sql(f"UPDATE host SET site_id={original['site']} WHERE id={original['id']}")
    harness.sql(f'DELETE FROM user_auth_perms WHERE user_id={user_id} AND item_id={deleted_host} AND type=3')
    harness.sql("DELETE FROM host WHERE description='site-deleted-fixture'")
    harness.sql('DELETE FROM sites WHERE id IN (' + ','.join(map(str, sites)) + ')')
    print('Site filtering and discovery checks passed.', flush=True)

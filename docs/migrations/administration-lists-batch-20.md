# Ten more native administration list views

This main-only slice extends the first ten-list migration with ten native Symfony
controllers and routes, DBAL read models and an autoescaped Twig view. It does not
include procedural PHP pages, capture legacy HTML or use legacy rendering helpers.
The scope is browsing. Editors, bulk changes, discovery, rescans, cleanup and log
purges still use the existing PHP forms and their write paths. Complete workflow
replacement remains migration work.

| List | Route below `app.php` | Feature realm | Existing operations |
| --- | --- | --- | --- |
| Users | `/administration/users` | 1 | `user_admin.php` |
| User groups | `/administration/user-groups` | 1 | `user_group_admin.php` |
| Authentication domains | `/administration/domains` | 1 | `user_domains.php` |
| Discovered devices | `/automation/discovered-devices` | 23 | `automation_devices.php` |
| Collectors | `/data-collection/collectors` | 3 | `pollers.php` |
| Trees | `/graphing/trees` | 4 | `tree.php` |
| Recorded RRD cleanup candidates | `/utilities/rrd-candidates` | 15 | `rrdcleaner.php` |
| SNMP agent objects | `/utilities/snmp-objects` | 15 | `utilities.php?action=view_snmpagent_cache` |
| SNMP notification history | `/utilities/snmp-notifications` | 15 | `utilities.php?action=view_snmpagent_events` |
| Login history | `/utilities/login-history` | 15 | `utilities.php?action=view_user_log` |

Every route also requires console realm 8 and the existing current-account and
group checks. The Symfony console menu opens the six new management lists.
System Utilities opens the four diagnostic lists. The shared `utilities.php`
menu remains reachable. Offline collector menus retain the legacy collector link;
online collectors use the existing verified primary-connection selection.

IdentityAccess owns user, group, domain and login-history readers. Graphing owns
tree reads, Automation owns discovery reads and Platform owns collector and
diagnostic reads. The web connection selects only the displayed fields and a
stable record identity. Password hashes, SNMP authentication fields, collector
database credentials and LDAP bind passwords are not selected. No new write
connection, cache, worker or session preference is introduced.

Search, allowlisted sorting and bounded paging retain the first batch's contract:
10, 25, 50 or 100 displayed rows, one lookahead row, and page numbers from 1 through
99999. Each catalog request uses one query. History defaults to newest first,
SNMP objects to OID order and trees to their stored sequence. Other lists sort by
name. Legacy advanced filters and remembered preferences remain available through
the operations link; the new views carry search and row count without changing
those preferences. Read-only lists have no create link or record-edit link.
Authentication-domain editors use the actual `domain_id` parameter.

## Identity and diagnostic behavior

Login history joins account metadata by both recorded user ID and username.
A reused ID or the same name under another ID cannot attach a different account's
name or realm to an old event. The users list derives its last attempt using the
same pair. That column includes failed attempts, so it is labeled **Last Login
Attempt**. Login events have a composite primary key; the read model retains all
of it as its paging tiebreaker.

Tree search matches names or child titles. Usage counts describe all placements
in the matching tree rather than only matching children. An empty tree has zero
branches. Owner and editor names use batched joins. Ownership and tree edit locks
remain enforced by the existing mutation path; listing a tree does not grant
permission to edit it.

Discovery text retains the legacy colon-to-space normalization. Uptime is shown
in whole seconds from the recorded hundredths-of-a-second sample. Collector
status retains disabled-state precedence and the 310-second heartbeat threshold.
These pages show recorded state without automatic refresh or live probes.

RRD candidates come from `data_source_purge_temp` with `in_cacti=0`. This view
neither scans the filesystem nor proves that a recorded candidate is currently
safe to remove. It shows all recorded candidates; the legacy age filters,
rescans and removal/archive actions remain on RRD Cleaner.

SNMP notification history retains records whose receiver has been deleted. It
does not join notification descriptions by name alone, which could multiply rows
when the same object name occurs in different MIBs. Values, descriptions and
varbinds render as text. The new GET paths do not truncate logs, rebuild caches or
start cleanup. Retained records and files remain owned by their existing writers.

## Verification and review

Local PHP 8.4.25 kernel tests cover all twenty list routes, malformed shapes,
feature admission, escaping, table structure, read-only controls, operation URLs,
HEAD, POST refusal, errors, empty lists, paging context and preference-based French
selection. Catalog wiring is exercised through the real service container.

MariaDB 10.11 runs the shipped table definitions under strict SQL and checks every
column in both sort directions. Four native tests pass with 1,582 assertions,
including one query per list, ID/username mismatches, group and domain joins,
child-search counts, empty trees, collector status, diagnostic record retention,
credential exclusion and duplicate-MIB notification names. The existing database
CI matrix runs these tests; other engines remain CI verification.

A Playwright 1.64.0 smoke check with Chromium loaded twenty kernel-rendered pages and their
compiled theme assets using the route tests' controlled catalog data. It checked
escaped DOM, scoped headers, keyboard focus and native GET filter submission
under the response CSP, with zero page errors. This does not establish installed
session restoration, live collector behavior or the legacy write-form round trip.
Those remain release acceptance work.

The final review compared the new routes and editor parameters with their legacy
callers, checked fields against `cacti.sql`, traced menu and collector routing and
verified that diagnostic reads retain existing data. The authorization classifier
pins the screen-to-realm mapping and controller; the expanded fixture set refuses
changed admission sources. Two existing layout assertions now expect the native
collector route under both supported front-controller layouts.

## Deployment and rollback

Deploy this slice after the first ten-list infrastructure, including its service
contract and Twig view. Deploy PHP, templates and English/French catalogs together.
There is no schema, dependency or runtime upgrade and no second write path.
Reverting this slice restores its menu destinations and removes the added lists;
existing PHP forms, data and in-flight legacy operations remain usable. Reverting
the application does not undo operations already submitted through those forms.

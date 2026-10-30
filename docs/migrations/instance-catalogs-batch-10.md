<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Ten instance browsing views

This main-only slice moves ten browsing boundaries to Symfony and Twig. Existing
PHP editors and operational actions retain their write paths. It does not retire
their root scripts or claim that their complete workflows have migrated.

| Path below `app.php/management/` | Parent | Feature realm |
| --- | --- | --- |
| `graph-items/{id}` | Local graph | 5 |
| `graph-data-sources/{id}` | Local graph | 5 and 3 |
| `aggregate-members/{id}` | Aggregate graph | 5 |
| `data-source-definitions/{id}` | Local data source | 3 |
| `data-source-graphs/{id}` | Local data source | 3 and 5 |
| `report-items/{id}` | Report | 21 |
| `my-report-items/{id}` | Report owned by the viewer | 22 |
| `tree-items/{id}` | Tree | 4 |
| `collector-devices/{id}` | Data collector | 3 |
| `network-devices/{id}` | Discovery network | 23 |

All except personal report contents also need Console Access (realm 8). The
personal report route uses the existing authenticated-account boundary and checks
ownership in the parent query. Another account's report returns 404 before any
item query. Feature checks precede filter parsing and protected reads. Offline
collectors retain the existing refusal rather than falling back to local data.

## Behavior and state ownership

The existing native graph, data-source, aggregate, report, tree, collector and
network catalogs link to the new views. Every view links back to its owning PHP
editor or filtered operation page. GET and HEAD never mutate data; POST is refused.
Responses are private/no-store. Installation subdirectories, front-controller
paths and parent identities survive filtering and pagination.

Graph and data-source readers apply the existing management visibility rules to
parents and referenced resources. A permitted graph does not expose a forbidden
data source, and a permitted source does not expose an unauthorized graph. Multiple
graph items referencing the same source produce one source row. Missing or hidden
parents produce 404; an empty child list remains a successful empty page.

The management visibility implementation now lives behind an IdentityAccess
contract injected into its readers. The existing Graphing scope remains a thin
delegate. Native legacy-policy comparison tests still exercise the four visibility
modes and enabled-group behavior. No new globals, superglobals, service locators
or legacy bootstrap calls are introduced in the native views.

Data-source definitions select local-instance rows, not template-level rows.
Numeric strings such as `1e9` and `-1` remain stored strings. Nullable graph labels
stay visible as unnamed rows; zero, null and empty values are not coerced into one
value. Tree item IDs retain their unsigned BIGINT representation. Parent limits
follow the corresponding unsigned database ID columns.

Report content is a stored-configuration view, not report execution or preview.
Text is escaped, graph/device/tree references remain recorded IDs, and protected
resource names are not resolved through those references. Recipients, BCC lists,
format paths and credentials are excluded. Tree browsing is a flat placement list
with parent IDs and positions; drag-and-drop and tree mutations stay in the editor.
Discovery and collector views exclude SNMP and database credential columns.

Filters reuse the management catalog contract: bounded UTF-8 search, explicit sort
allowlists, 10/25/50/100 rows, pages through 99999, and one lookahead row. Graph,
aggregate and report items default to sequence; tree items default to position.
The other new views default to name. English and French catalogs are selected
through the normal viewer-locale subscriber. Stored text and machine IDs remain
untranslated. Existing legacy display preferences and advanced filters are retained
on the linked PHP pages.

## Verification and limits

PHP 8.4.25 with locked dependencies: the new native reader tests execute shipped
DDL on isolated MariaDB 10.11. They cover every sort in both directions, ownership,
graph/device policies, parent isolation, credential omission, nullable values,
lookahead pagination and a maximum five-query reader budget. Report, network and
tree readers use two queries. New tests are registered in the existing six-engine
database CI matrix and the Symfony coverage producer.

Kernel tests cover all new routes, authorization before malformed input, missing
parents, failed reads, empty results, actual French preference selection,
subdirectory links, HEAD and POST behavior. Browser tests render all ten views in
all seven themes under the response CSP and exercise escaping, keyboard focus and
native GET filter submission. The entry-point classifier retains its source pins
and mutation tests for the new fixed route/realm mappings.

These checks do not establish installed collector/session acceptance, full legacy
editor parity or production latency. Wall-time route profiling remains an acceptance
gap; the native query budget is measured independently of HTTP timing. Existing
legacy writers and their globals remain required until their separate migrations.

## Deployment and rollback

Deploy source, templates, translation catalogs and inventory metadata together;
rebuild normal Symfony caches and assets. No schema, runtime or dependency upgrade
is required. Rollback restores the prior application revision and rebuilds its
caches/assets. There are no new writers, messages or persisted migration markers.

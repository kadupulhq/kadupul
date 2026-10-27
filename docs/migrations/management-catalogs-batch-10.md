<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Ten management catalog views

This main-only slice migrates browsing for six management lists and four template
relationship lists into Symfony routes, injected read services and Twig. Existing
PHP editors, bulk operations, report sending and plugin lifecycle actions retain
their owners. Legacy URLs and preferences remain usable. LTS is unchanged.

| View | Route below `app.php` | Required feature realm | Legacy destination |
| --- | --- | --- | --- |
| Graphs | `/management/graphs` | 5 | `graphs.php` |
| Data Sources | `/management/data-sources` | 3 | `data_sources.php` |
| Aggregate Graphs | `/management/aggregate-graphs` | 5 | `aggregate_graphs.php` |
| Administrator Reports | `/management/reports` | 21 | `reports_admin.php` |
| My Reports | `/management/my-reports` | 22 | `reports_user.php` |
| Recorded Plugins | `/management/plugins` | 101 | `plugins.php` |
| Graph Template Items | `/management/graph-template-items/{id}` | 10 | Graph template items |
| Graph Template Inputs | `/management/graph-template-inputs/{id}` | 10 | Graph template inputs |
| Device Template Graphs | `/management/device-template-graphs/{id}` | 12 | Device template associations |
| Data Query Graph Mappings | `/management/data-query-graphs/{id}` | 13 | Query graph associations |

All views require an eligible authenticated account and their feature realm.
The nine console-management views additionally require console realm 8. Personal
reports retain realm 22 without requiring console access, matching the existing
normal-user role. The personal catalog always lists the current account's reports,
including when that account also has administrator privileges. Administrators use
the separate administrator view for other owners' reports.

The online console menu opens the six management lists. The existing template and
data-query catalogs link to their native relationship lists. Record links open
the actual existing PHP editors, including the separate graph-item and graph-input
scripts and the original parent parameter names. Unknown parents produce 404;
an existing parent with no children produces an empty successful list. Numeric
parent identity is retained through filters, sorting, paging and subdirectory
front-controller URLs.

## Ownership and permissions

Navigation owns list admission, input mapping and presentation. Graphing owns
runtime graph/data-source catalogs and template graph relationships. DataInput
owns query graph mappings, Reporting owns report reads, and Platform owns recorded
plugin metadata. Each module supplies a tagged catalog through a public Navigation
contract. Controllers contain no SQL and do not bootstrap legacy pages or capture
legacy HTML. New services do not read superglobals or declare globals.

`IdentityAccess/Contract/VisibilitySql` holds the existing visibility predicate
implementation. Inventory's original `DeviceVisibilityRules` remains a compatible
delegate, preserving its constants, mode handling, method signatures and exception
callback. This lets Graphing reuse the same policy without importing Inventory
infrastructure or duplicating its policy expression. Existing readers and locked
writers retain their call sites. The legacy policy helpers remain available.

Graph management intersects permitted graph IDs with permitted device IDs, while
retaining `host_id=0`. Data sources follow management-device scope. Enabled group
policies and explicit graph, device and template exceptions remain effective.
Graph-view hide-disabled preferences are not authorization rules. The new lists
show permitted management records without changing those legacy preferences.
Aggregate browsing requires a permitted aggregate graph with its non-device,
non-template graph identity; it does not clean up stale aggregate rows on GET.

Template items are selected by both template identity and `local_graph_id=0`.
Input-association counts exclude local instances and items from other templates.
Device-template dimensions come only from template-level graph metadata. Queries
use stable identities, bound search values, allowlisted sorts and at most 101 rows
including one lookahead row. Catalogs use at most four statements for resource
policies plus records, or two for parent/child lists. There are no per-row lookups.

## Display and operational differences

The report catalogs omit recipients, BCC addresses, message bodies and format-file
paths. Reports whose owner no longer exists are displayed as effectively disabled;
the new GET does not rewrite their stored enabled state. Report timestamps are
explicitly displayed in UTC. Interval and attachment labels retain the stored
legacy constants. Editor behavior and legacy date preferences are unchanged.

Plugin browsing reads only recorded database metadata. It does not discover files,
include plugin PHP, run compatibility checks, refresh the plugin table or enable,
disable, install or uninstall anything. Stored directories and authors are escaped
text; plugin-controlled external links are not published. Realm 101 is the existing
built-in plugin-management realm, recorded by the schema's internal plugin realm.

All routes accept GET and HEAD and reject POST. Native GET forms provide search,
10/25/50/100 rows, allowlisted sorting and pages 1 through 99999. The default is 25
rows. New filter submissions reset paging; preferences are not saved. Advanced
legacy filtering, bulk selection, exports and all mutations remain on linked
operation pages. This slice does not retire those pages or reduce their global
statement count.

Twig uses automatic escaping, scoped table headers, captions and labeled forms.
No new JavaScript or inline event handlers are needed. English and French catalogs
use the existing preference-based locale subscriber. Stored names, OIDs and schema
field identities remain untranslated. The seven-theme browser regression checks
actual kernel/Twig output under CSP, including visible content, escaped DOM,
keyboard focus and native filter submission with the parent path retained.

Supported online collectors use the existing verified primary selection, including
local recovery checks. Offline/recovery collectors retain the limited existing
menu; direct native catalog requests cannot fall back to collector-local data.

## Verification and release limits

`ManagementCatalogRoutesTest` exercises all ten views through the kernel, including
normal-user personal reports, authorization before input handling, malformed
shapes, missing parents, empty and failed reads, escaping, HEAD/POST, preference
selection, installation paths, collector refusal and real container catalog wiring.
`ManagementCatalogNativeTest` creates random disposable schemas from the shipped
DDL, tests every displayed sort column in both directions, checks query budgets,
ownership and secret exclusion, and compares new visibility results against the
actual legacy management helpers across all four policy modes. Group changes are
read afresh. The native test runs in the existing six-engine database job.

The authorization classifier pins both controllers, route-to-screen realm mapping,
access adapter and application query. Its personal-report path recognizes only
the pinned realm-22 controller; arbitrary authenticated callers inherit no grant.
Changes to any enforcing source invalidate classification until reviewed. The HTTP
inventory retains realm-withdrawal probes for personal reports and resolves all
four relationship parents from actual installed rows; missing parents fail fixture
setup.

Local evidence is recorded in `docs/enterprise-readiness.md`. Browser fixtures
control admission and catalog data; installed session restoration, the complete
legacy editor round trip and collector HTTP acceptance remain release work.

## Deployment and rollback

Deploy services, routes, templates and translation catalogs together and clear
Symfony caches. No schema, dependency or runtime upgrade is required. No worker or
second write path is introduced. Reverting this slice restores the previous menu
and relationship links; legacy URLs and persisted data remain available. Reverting
the presentation does not undo operations already submitted through legacy pages.

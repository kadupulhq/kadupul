<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Ten System Utilities views

This main-only slice adds ten Symfony routes and an autoescaped Twig view for
System Utilities. The online Symfony console menu opens the new utilities menu.
It is independent of the administration-list migrations. LTS is unchanged.

| View | Route below `app.php` | Existing view |
| --- | --- | --- |
| System Utilities | `/utilities/system` | `utilities.php` |
| Technical Support | `/utilities/system/summary` | `view_tech`, tab `summary` |
| Database Tables | `/utilities/system/database` | `view_tech`, tab `database` |
| Database Settings | `/utilities/system/database-settings` | `view_tech`, tab `dbsettings` |
| Database Status | `/utilities/system/database-status` | `view_tech`, tab `dbstatus` |
| PHP Information | `/utilities/system/php` | `view_tech`, tab `phpinfo` |
| ChangeLog | `/utilities/system/changelog` | `view_tech`, tab `changelog` |
| Boost Status | `/utilities/system/boost` | `view_boost_status` |
| Poller Cache | `/utilities/system/poller-cache` | `view_poller_cache` |
| Data Query Cache | `/utilities/system/data-query-cache` | `view_snmp_cache` |

The last nine legacy actions are on `utilities.php?action=...`. Legacy URLs and
operations remain available. This is a diagnostic-view migration, not retirement
of `utilities.php` or its global statements. Cache rebuilds, purges, log exports,
plugin utility entries and tuning recommendations retain their existing write and
rendering paths. Each new view links to its corresponding legacy view, and the
new menu links to log, RRD and SNMP operations. GET and HEAD never initiate a
maintenance operation. POST is rejected on all ten new routes.

## Boundaries and deliberate differences

Platform owns routing, diagnostic reads and presentation. Inventory supplies cache
rows through `Inventory/Contract/CacheDiagnostics`; its adapter reuses the existing
visibility rules. Controllers receive dependencies from Symfony. They do not
include `global.php`, capture procedural HTML, read superglobals or use a service
locator. No new runtime, dependency, schema, worker or persistent cache is added.

The current-account and realm 8/15 checks reuse the existing `RrdCheckAccess`
authorizer, whose policy is also the System Utilities policy. Checks run before
filter parsing or diagnostic reads. Device caches additionally apply management
device permissions, including enabled group grants and exceptions. Disabled and
soft-deleted devices retain the management scope, independent of graph-view
preferences. Non-device poller items retain `host_id=0`; data-query records require
an actual permitted device. Credentials and command arguments are not selected
from poller items, and argument contents do not participate in search.

Database reads use the existing web connection. Supported online collectors must
pass the existing local-recovery and primary-availability checks before selecting
the primary. Offline/recovery collectors keep their existing utilities menu.
Database counts and metadata describe the selected database; PHP information
and the changelog describe the web installation serving the request.

The new summary shows installation/runtime versions, inventory counts and recorded
poller settings. It does not execute external tools or reproduce the legacy
memory/connection tuning calculations. Boost shows recorded configuration,
runtime statistics and database storage estimates, without scanning image-cache
directories, probing devices or auto-refreshing. Detailed legacy diagnostics
remain linked. Missing stored settings remain absent rather than becoming zero.

Database settings use an explicit allowlist; unknown plugin variables are not
published. Database status shows numeric counters. PHP information shows loaded
extensions and selected diagnostic INI values, without `phpinfo()` HTML,
environment variables or request/session contents. The changelog reads the actual
`CHANGELOG.md` shipped on main, with a checked 2 MiB maximum; a missing, incomplete
or oversized file is a failed read rather than an empty successful view.

Views use bounded GET filters and paging: 10, 25, 50 or 100 rows, pages 1 through
99999, default 25 rows, and one lookahead record for database/cache tables. The
cache order includes the complete record identity. Search and numeric cache
filters travel in paging links; submitting filters starts at page 1. Preferences
are not written. Legacy sorting, row defaults and automatic refresh remain on the
advanced views. InnoDB row metadata and Boost queue metadata are estimates.

Twig escapes all cells and attributes. The view uses native GET forms, labeled
controls, table captions, scoped column headers and navigation with current-page
state. It needs no JavaScript or inline handlers. English and French catalogs use
the existing preference-based locale subscriber. Stored values, configuration
keys and OIDs remain untranslated.

## Verification

`SystemDiagnosticsRoutesTest` exercises all ten routes through the real kernel:
admitted and denied access, authorization before malformed input, read failures,
empty results, escaping, pagination, zero IDs, HEAD, POST refusal, actual French
preference selection, and offline collector refusal. `SystemDiagnosticsDatabaseTest`
uses production table definitions in random disposable schemas. It checks all ten
readers, device policies, enabled/disabled groups, non-device data, duplicate-name
paging, a four-query cache budget, secret exclusion, actual database failure, account/realm denial and
verified online-primary selection. The native test is registered in the existing
six-engine database CI job.

The existing compiled-assets Playwright suite renders these routes through the
kernel and checks all ten views with all seven shipped themes under the response
CSP. It checks visibility, escaped DOM, keyboard focus and native GET submission.
The visibility regression fails when the reserved legacy `id="main"` is restored;
these views use `id="system-diagnostics"` instead.

Local results and remaining acceptance work are recorded in
`docs/enterprise-readiness.md`. Kernel and browser checks use controlled admission
and data adapters. They do not establish installed authentication or the legacy
maintenance round trip; these remain release acceptance work.

## Deployment and rollback

Deploy the routes, services, templates and translation catalogs together and clear
Symfony caches. No data migration is required. The legacy entry points are kept,
so existing maintenance operations retain their owners. Revert this slice and
clear caches to restore the previous Symfony menu destination. Application
rollback does not undo maintenance operations already performed through legacy
pages.

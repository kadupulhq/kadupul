# Global-state audit of Twig migrations

Audit snapshot: main `df629bf1d` (2026-10-27), plus the three PR heads listed below.
Status: unused bindings removed; native layer guard implemented. The remaining
legacy boundaries have been assessed, not retired. LTS is unchanged.

## Findings

Twig rendering has already removed direct global access from the native page
layers. A token-aware scan of this main snapshot found no `global` statements,
`$GLOBALS` or request/session superglobal tokens in 245 application files, 192
domain files, 67 contract files, or 74 HTTP controllers. All 71 Twig templates
and the five registered Twig function/filter providers were checked. There are
no project-defined Twig `globals` or calls to `Environment::addGlobal()`; Symfony's
normal per-request `app` variable is a separate framework facility.

That does not mean these pages are independent of legacy state. Authentication,
legacy writes, plugin chrome and graph-image requests still use compatibility
adapters. Across `src/`, ten explicit global declarations (18 bindings) remain
in seven files. A further scan finds 48 `$GLOBALS`/superglobal accesses across
the stateful infrastructure set; the union is 19 files. These numbers include
shared upgrade/CLI and subprocess support that is not part of every Twig request.

The pending migrations introduce no direct global-state access in their changed
`src/` files:

| PR | Audited head | Changed PHP files checked | Direct global state |
| --- | --- | --- | --- |
| #1013 | `d10f29052` | 5 | None |
| #1015 | `afd2726d1` | 15 | None |
| #1018 | `463240d19` | 10 | None |

PR #1012 is included in the main snapshot. PR #1018 already removes three unused
bindings in the data-query confirmation function; that cleanup is deliberately
not duplicated here. PR merge state may change after this snapshot.

## Coverage by migration family

| Native Twig family | Native input/configuration boundary | Remaining legacy dependency |
| --- | --- | --- |
| Console home, help, permission-denied, About | Request/actor/configuration services and explicit view data | Native session/remember-me compatibility; plugin console or denial output runs in an isolated authenticated PHP worker |
| Sites and devices, placement/template/collector/state/bulk/removal flows | HTTP request/forms, application use cases and typed ports | Supported write/maintenance commands, hooks and diagnostic redaction retain worker bootstrap and session state |
| Data input methods and fields | Validated forms, use cases and worker contracts | Plugin restrictions and legacy command/input semantics remain in the write worker |
| CDEF and VDEF pages | Explicit domain vocabularies, DBAL stores, revisions and actor/access ports | CDEF legacy deletion/reference adapters and shared legacy callers remain; the native VDEF editor's class name does not imply it reads globals |
| Palette colors, GPRINT, color templates and data-source profiles | Explicit form choices, stores and actor/configuration ports | Existing plugin/helper callers and operations deliberately retained by each migration still use legacy state |
| Automation template CRUD/order | Domain choice catalogs, forms and application/worker contracts | Legacy rule, SNMP-option and discovery editors still own their mutations |
| Navigation links | Explicit configuration, actor, repository and operation contracts | External/plugin content can still run under the legacy include contract |
| Administration lists and system diagnostics | Bounded DBAL snapshots and explicit filters/feature admission | Advanced filters, edits, exports and maintenance remain on the linked PHP pages |
| Graph/data-source/aggregate/report/plugin catalogs and template/query children | DBAL readers, actor visibility and scoped parent identities | Catalogs do not retire the old editors, runtime plugin lifecycle or graph-render backend |
| Relationship, identity membership/permission, identity settings, mapping/MIB slices | Explicit parent IDs, allowlisted fields, DBAL readers and actor/feature checks | Legacy writes, effective-permission/preference resolution and cache lifecycle remain; see audited PR heads above |

## Safe cleanup implemented

A parser found global bindings that occur only in their declaration. The complete
function bodies were checked for includes, `eval`, dynamic variables and symbol-table
operations before selecting them. The cleanup removes 71 unused bindings from 45
functions across 18 retained PHP pages. No active binding, function signature,
condition, query, hook, output string or request helper call is changed.

| Legacy page | Functions cleaned | Bindings removed |
| --- | --- | --- |
| `aggregate_graphs.php` | 1 | 3 |
| `aggregate_templates.php` | 3 | 3 |
| `automation_graph_rules.php` | 4 | 4 |
| `automation_networks.php` | 3 | 6 |
| `automation_snmp.php` | 4 | 7 |
| `automation_tree_rules.php` | 3 | 3 |
| `data_sources.php` | 1 | 1 |
| `data_templates.php` | 1 | 2 |
| `graph_templates.php` | 3 | 4 |
| `graphs.php` | 3 | 8 |
| `managers.php` | 1 | 4 |
| `plugins.php` | 2 | 4 |
| `pollers.php` | 1 | 1 |
| `rrdcleaner.php` | 6 | 10 |
| `user_admin.php` | 1 | 1 |
| `user_domains.php` | 1 | 1 |
| `user_group_admin.php` | 2 | 2 |
| `utilities.php` | 5 | 7 |

Eleven declarations disappear entirely; 34 keep their still-used bindings. The
repository ratchet falls from 902 declarations/1701 bindings to 891/1630. The ten
`src/` declarations are unchanged. These are counts of bindings, not a claim that
the underlying global variable names no longer exist anywhere in the application.

Three further candidates remain unresolved because their functions include PHP
into their local scope: `user_admin.php::form_save()` (`$settings_user`),
`user_group_admin.php::form_actions()` (`$user_auth_realms`), and
`user_group_admin.php::user_group_graph_perms_edit()` (`$config`). The immediate
includes are `PermissionAssociations.php` or `PermissionTemplateGrid.php`.
Review the full include chain and supported extension scope before removal.
A syntactic absence in the outer function alone is insufficient evidence.

Gettext source references are regenerated because removing complete declarations
moves subsequent lines. All message identities, translations and flags are
unchanged. The compiled MO outputs are rebuilt and remain byte-identical.

## Boundaries that need further migration

| State owner | Concrete dependency | Safe direction and prerequisite |
| --- | --- | --- |
| `GraphOptionsGenerator` | `$config` selects `global_arrays.php`; `$image_types` is populated/used when generating RRDtool arguments | Pass an explicit render context and vocabulary through all graph/image/export callers. Characterize command bytes, hooks, timezone and sequential viewers before removing the include or global writes |
| `PermissionFilter` | `$item_rows` supplies the legacy filter choices; request/session helpers preserve each page's filter contract | Hand filter models and choices to Twig as data when these remaining permission forms move. Keep route-specific remembered-filter and associated-selection semantics |
| `PermissionMutation`, `LegacyDatabaseConnection`, `LegacyDatabaseCall` | The current legacy PDO can switch with collector routing; affected rows/errors are published to legacy callers | Complete the writer use case on one explicitly owned connection. Do not substitute the Symfony web/read-only connection or cache a handle across a legacy connection switch |
| `LegacyConnectionRegistry` and `LegacySettingsStorage` | `$GLOBALS` is the named connection/metrics compatibility contract used by db_* helpers and plugins | Keep one adapter until those callers migrate. Replacing `global` with `$GLOBALS` does not remove the dependency; require same-PDO, transaction, reconnect and counter evidence before changing ownership |
| `ConsoleExtensionRuntime` and `PermissionDeniedRuntime` | Plugin hooks/includes observe live `$config`; csrf-magic's global flags decide whether whole-document rewriting runs | Move only after an explicit chrome/content/CSRF protocol preserves include scope, hook order and actual submission origins. In-process plugins remain trusted code, not a sandbox |
| `SharedSession`, native session handlers and browser authentication | Existing cookies, file/database sessions, remember-me tokens and credential generation must remain coherent with legacy entry points | Capture request data behind an HTTP adapter and retain one compatible session owner. Switching to an unrelated Symfony session bag is not sufficient; verify login, disable/lock, password policy, revocation and both backends |
| `PermissionAssociations` and `DeviceMaintenanceExecutor` | Raw submitted field names and diagnostic session flags still belong to the worker protocol | Convert them to validated command data and a scoped diagnostic context when the corresponding write/maintenance use case moves; preserve absent/forced fields and secret redaction on failures |
| `LegacyRrdWebContext` | The timezone cookie and legacy session lifecycle precede RRDtool execution | Pass validated timezone/request context at the graph boundary while preserving session-release ordering and image/export behavior |
| `LegacyUpgradePluginLifecycle` | Upgrade ordering and pruning use installation paths and legacy plugin functions | Separate installer/CLI work. This class is not a native Twig page dependency merely because it is under `src/` |
| `RrdtoolProcess`, `DbalAuditBaselineStore` | `$_ENV` contributes keys that are explicitly cleared for child processes | Preserve environment isolation. Injecting an environment-key provider is possible, but deleting the reads can re-expose inherited secrets |

Static state was also checked. Ten static local/property declarations remain in
five legacy files: `LegacyComponentAutoloader`, `LegacySettingsStorage`,
`LegacyLogText`, `PermissionMutation` and `DeviceDiagnosticScope`. The first three
hold bootstrap/storage/immutable lookup caches. The latter two hold transaction
batch and credential-redaction scope; changing them needs complete ownership,
cleanup and failure-path tests. No mutable static state was found in the native
application, domain, contract or HTTP-controller layers.

## Verification and limits

The source comparison strips only `global` declarations and whitespace/comments;
all other executable tokens are identical in the 18 changed pages. This supports
the narrow cleanup and is not evidence that every legacy action ran.

Existing native automation, permission, form-rendering, graph-template and plugin
harnesses execute production code. The focused run reports 294 passed checks and
seven warnings. The two warning-bearing suites were also run against the clean
main snapshot: they report the same seven warnings (81 passed/676 assertions),
so those warnings are pre-existing, not silently suppressed here.

On PHP 8.4.25 with the locked dependencies and compiled assets, the root
Symfony run reports 4243 tests, 199730 assertions and 275 skips, with no
failures or errors. The skips remain unverified coverage. The isolated
architecture run reports ten tests and 171052 assertions. JavaScript tests
report 20 passes. Gettext verification covers 1218 PHP sources, 4302 static
messages, 195 reviewed compatibility identities and 23 PO/MO pairs.

The architecture suite now checks every HTTP controller for direct globals and
all request/session superglobals, with nonempty discovery. Its existing inward
layer rule now covers cookies, request/files and environment globals as well.
The per-file global ratchet may only fall; it is not expanded to admit a new
violation. Standard root tests and source/generated-artifact checks are recorded
on the PR with their actual source revision.

Sink and hotspot inventories contain no new findings. Their changed rows only
refresh source line references. Four stale hotspot rows for unchanged
`color_templates.php` includes already occur on clean main and are retained;
this cleanup does not rewrite that unrelated baseline drift.

Full installed-session/collector acceptance, every legacy form's browser path,
plugin installations and live graph/maintenance processes are not verified by
this audit. Remaining adapters are assessed, not declared globally migrated.

## Rollout and next work

Deploy the PHP and refreshed gettext artifacts together. There is no schema,
runtime or dependency change. Rollback restores the previous source/catalogs;
this cleanup introduces no persistent state. Keep legacy operations available.

The next useful migration is explicit data for the remaining permission forms
and worker commands. Session, connection and plugin ownership should move as
separate, fully tested use cases, not as a repository-wide search-and-replace.

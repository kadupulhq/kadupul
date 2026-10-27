# Ten mapping and MIB views

Status: implemented. This independent main-branch slice extends the merged
management catalog infrastructure. Paths are under `app.php/management/`.

| Path | Feature realm |
| --- | --- |
| `graph-input-items/{id}` | 10 |
| `query-data-sources/{id}` | 13 |
| `query-graph-suggestions/{id}` | 13 |
| `query-data-suggestions/{id}` | 13 |
| `query-device-templates/{id}` | 13 and 12 |
| `graph-device-templates/{id}` | 10 and 12 |
| `snmp-mibs` | 15 |
| `mib-objects/{id}` | 15 |
| `mib-conventions/{id}` | 15 |
| `mib-notification-attributes/{id}` | 15 |

All ten views require console realm 8 plus the feature realm(s) above. The two device-template relationship views
require realm 12 as well as the source feature grant before displaying names. Existing
current-account, guest, lock, password-change and direct/enabled-group policy
checks remain authoritative. Personal report admission remains separate and
continues to require its existing signed-in-account/realm-22 contract.

## Behavior and navigation

Graph-input associations resolve item metadata only within the input's owning
graph template and with `local_graph_id=0`. Data-query source mappings resolve
RRD definitions only within the recorded data template and with `local_data_id=0`.
Unresolved stored associations stay visible without unrelated instance metadata.
Composite query mappings retain both data-template and RRD-definition IDs.
Suggested expressions and field tokens are displayed as recorded and never
parsed, evaluated or used to load XML files.

Registered MIB IDs resolve the canonical module name produced by `MibCache`.
Objects, textual conventions and notification attributes are scoped by that
module name. Composite convention and attribute keys provide stable pagination;
non-unique cached object names are not used to join descriptions. File paths
and cached runtime values are omitted. No MIB files are read, parsers run,
processes started or caches rebuilt by these pages.

Existing input/query rows link to their new detail views; graph/query catalogs
link to device-template relationships. The MIB registry is reachable from System
Utilities and existing SNMP catalogs, and links to its three metadata views.
System Utilities keeps its diagnostic menu. Existing editing links remain.
The operation links for inputs and query mappings include owning template/query
IDs read from the database. MIB operation links open the unfiltered legacy cache
page, which remains usable when a registered module has no cached objects.

All routes use GET/HEAD; POST is rejected. Filters retain the shared 255-character
UTF-8 search limit, 10/25/50/100 rows and pages 1–99999. Sort keys/directions are
allowlisted and values bound. Each scoped reader uses one parent lookup and one
bounded child query with a lookahead row; the MIB registry needs one query. The
MIB parent routes accept unsigned 32-bit IDs; existing mediumint parent bounds
are preserved elsewhere. Subdirectory/front-controller paths remain supported.
Twig escapes stored metadata, names and expressions. English/French catalogs
cover the interface; machine identities and suggested-value tokens stay unchanged.

## Global state removed

`data_query_item_remove_confirm()` no longer declares `$vdef_functions`,
`$vdef_item_types` or `$custom_vdef_data_source_types`. Inspection of the complete
function and its callers found no reads or writes of those bindings. Its
confirmation data comes from the selected query/graph association.

The complete function is extracted with the existing token-aware test helper.
A before/after run produces byte-identical HTML and identical validation/query
calls; the new behavioral regression also verifies that optional VDEF globals
are not initialized. The per-file global ratchet falls from five statements/nine
bindings to four statements/six bindings in `data_queries.php`. Repository totals
fall from 902/1701 to 901/1698. Required legacy configuration, form catalogs and
plugin/worker state remain on their existing paths.

## Deployment and rollback

No schema, dependency or runtime update is required. Deploy source, templates
and translation catalogs together and rebuild normal Symfony caches/assets.
Rollback restores the previous application revision and rebuilds its caches/assets.
There are no new writes, queued operations or persisted migration markers.
Existing PHP editors, suggested-value reordering and MIB cache lifecycle actions
remain active; no legacy writer is retired by this slice.

## Verification and release limits

Kernel tests exercise all twenty management views: authentication/feature denial
before parsing, malformed filters, escaping, missing/empty/failed reads, HEAD/POST,
real viewer locale selection, authoritative operation parameters, MIB child
links, subdirectory URLs and exactly one owning reader per screen.

Native MariaDB tests load shipped table definitions and exercise all new sorts,
parent isolation, unresolved/foreign/local-instance metadata, composite keys,
large MIB IDs, suggested text preservation, pagination and query budgets. Existing
native feature-admission and collector connection tests include the new routes.
The new native class runs in the existing six-engine CI matrix. Authorization
sampling seeds actual inserted parents for initially empty input/mapping/MIB
catalogs; the complete shipped-schema check verifies all nine scoped routes.
Browser checks render the ten new views under all seven themes and actual CSP,
including keyboard navigation, escaping and GET filter submission.

Installed-session and collector HTTP acceptance remain release checks. Browser
admission/data adapters are controlled fixtures. Local evidence does not verify
legacy saves, production deployment, live devices or cache-building processes.

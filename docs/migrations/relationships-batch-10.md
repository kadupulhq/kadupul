# Ten relationship browsing views

Status: implemented. These views replace HTML browsing boundaries; edits and
operational actions continue through the existing PHP pages.

| Symfony path under `app.php/relationships/` | Parent | Feature realm |
| --- | --- | --- |
| `device-template-queries/{id}` | Device template | 12 |
| `data-template-definitions/{id}` | Data template | 11 |
| `aggregate-template-items/{id}` | Aggregate template | 5 |
| `snmp-option-entries/{id}` | SNMP option set | 23 |
| `graph-rule-device-criteria/{id}` | Graph rule | 23 |
| `graph-rule-query-criteria/{id}` | Graph rule | 23 |
| `tree-rule-device-criteria/{id}` | Tree rule | 23 |
| `tree-rule-steps/{id}` | Tree rule | 23 |
| `receiver-subscriptions/{id}` | Notification receiver | 15 |
| `receiver-history/{id}` | Notification receiver | 15 |

All routes require console access plus the parent's existing feature policy.
Authorization precedes request parsing and parent lookup. Online collectors use
the established primary connection boundary; offline collectors remain refused.

Parent IDs must be positive unsigned 32-bit integers. GET filters use the
existing list contract: search up to 255 UTF-8 characters, 10/25/50/100 rows,
pages 1–99999, and allowlisted sort columns and directions. Each reader uses one
parent lookup and one bounded child query with a lookahead row. Links retain
parent identity, installation subdirectory and front-controller paths.

Graph and tree criteria are partitioned by their stored rule type. Aggregate
item metadata must belong to the parent graph template and have no local graph
instance. An unresolved association remains visible without unrelated metadata.
Receiver subscriptions retain their composite receiver, notification and MIB
identity. History displays recorded rows and UTC timestamps without clearing,
trimming or multiplying logs through cached notification descriptions.

SNMP credentials and credential identifiers are omitted from the reader's
projection. Twig escapes stored names, expressions and varbinds as text.
Expressions are displayed rather than evaluated. English and French catalogs
cover the new interface; stored field names, MIB identifiers and expressions
retain their original bytes.

## Deployment and rollback

Deploy source, templates and translation catalogs together, then rebuild the
normal Symfony cache and assets. No schema or dependency change is required.
Rollback restores the prior application revision and rebuilds its cache/assets.
There are no new writers, queued operations or persisted migration state.

## Verification and limits

Kernel tests exercise all ten routes, malformed requests, authorization order,
missing/empty/failed reads, HEAD/POST behavior, locale preference selection and
subdirectory links. Native database tests use the shipped DDL in disposable
schemas and verify every supported sort, parent isolation, shared rule types,
credential omission and the two-query budget. Browser tests render all ten
views under seven themes and the actual response CSP, exercising visibility,
escaping, keyboard navigation and GET filter submission.

The authorization inventory pins the complete route/parent/policy mapping and
rejects changed admission sources. Installed-session HTTP authorization and
collector deployment acceptance remain release checks. Presentation fixtures
are not evidence that legacy edits, external devices or collector operations
have been exercised. Existing legacy actions remain required and their globals
are not retired by this slice.

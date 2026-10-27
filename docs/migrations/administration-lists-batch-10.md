# Ten administration lists in Twig

This main-only slice moves ten list views into Symfony and Twig. It migrates browsing, search, sorting and pagination. Create, edit, deletion, duplication, discovery and other bulk operations still open the existing PHP forms. Their authorization, CSRF validation and write paths remain there. This is not completion of those ten administration workflows.

| List | Route below `app.php` | Feature realm | Existing forms |
| --- | --- | --- | --- |
| Device templates | `/templates/devices` | 12 | `host_templates.php` |
| Graph templates | `/templates/graphs` | 10 | `graph_templates.php` |
| Data templates | `/templates/data-sources` | 11 | `data_templates.php` |
| Aggregate templates | `/templates/aggregates` | 5 | `aggregate_templates.php` |
| Data queries | `/data-collection/queries` | 13 | `data_queries.php` |
| SNMP option sets | `/automation/snmp-options` | 23 | `automation_snmp.php` |
| Graph rules | `/automation/graph-rules` | 23 | `automation_graph_rules.php` |
| Tree rules | `/automation/tree-rules` | 23 | `automation_tree_rules.php` |
| Discovery networks | `/automation/networks` | 23 | `automation_networks.php` |
| SNMP notification receivers | `/utilities/notification-receivers` | 15 | `managers.php` |

The Symfony console menu opens the new lists. System Utilities links to the new receiver list. Legacy PHP URLs remain available, including bookmarked filters and POST forms. The legacy console's own menu continues to use those URLs.

Navigation owns the shared list contract and presentation. Graphing, DataInput, Automation and Platform supply their own catalog adapters through dependency injection. Each adapter reads only displayed fields through the configured web connection. SNMP credentials, receiver credentials and network notification settings are not selected. Console realm 8 and the feature realm are required before filter validation or catalog reads. The admission check revalidates the account, guest policy and enabled group grants. Responses use `private, no-store`; the new routes accept only GET and HEAD.

Each list uses one query with at most 101 records, including a lookahead record. Sort columns are fixed public aliases, search values are bound parameters, and a primary-key tiebreaker keeps paging stable for duplicate names. Pages accept 10, 25, 50 or 100 records and page numbers from 1 through 99999. The default is 25. There is no unbounded “all rows” option or total-count query. Search and sorting travel in GET links; these views do not save or overwrite legacy filter preferences. Advanced filters and bulk actions are reachable through a separate link, which carries search and row count to the legacy list. Selection, advanced filters and stored preferences have not moved into Symfony.

Two derived-count corrections are intentional. SNMP option counts aggregate networks and entries separately, so two networks and three entries report 2 and 3 rather than multiplying both joins. Data-template metadata comes from `local_data_id=0`; local instances contribute only to the usage count, so an arbitrary local instance cannot supply the displayed input method, profile or active state. These counts describe usage, not a guarantee that deletion is allowed. The legacy mutation path still makes that decision.

The network view reads pending/running/done counts and process status without deleting completed process records during GET. It performs no discovery, automatic refresh or cleanup. Progress can change between requests and is not an atomic snapshot across the legacy MEMORY tables. Existing legacy actions remain available for operations.

English and French strings use the `administration` Symfony domain and the existing preference-based locale subscriber. The shared Twig view autoescapes stored text, has labeled GET controls and scoped table headers, and needs no JavaScript or inline event handlers. Links use the configured installation base path.

## Verification

`AdministrationListRoutesTest` exercises all ten routes through the real kernel with controlled catalogs: escaped text, legacy form destinations, table structure, paging context, anonymous and denied access, malformed filters, empty results, failed reads, HEAD, POST refusal and actual French preference selection. `ConsoleMenuTest` checks the new destinations against the unchanged legacy realm inventory. `ArchitectureTest` enforces feature contracts.

`AdministrationCatalogNativeTest` creates a random disposable schema from the shipped table definitions. It exercises every column and sort direction under strict SQL, separate SNMP aggregates, template-level metadata, receiver counts, network progress without cleanup, duplicate-name pagination, query budgets and current-account/direct/group authorization. It uses the existing `KADUPUL_TEST_MYSQL_DSN` and optional administrator credentials and runs in the existing database CI matrix. No production data is used.

Local evidence and remaining environment limits are recorded in `docs/enterprise-readiness.md`. Installed browser acceptance and the complete legacy form round trip remain release verification work.

## Deployment and rollback

Deploy the application, routes, service wiring, templates and translation catalogs together. There is no schema, runtime or dependency change. Supported online collectors use the existing verified primary-connection selection; offline collectors retain their limited menu. This slice does not introduce a second write path or a worker.

Reverting the slice restores the previous Symfony menu destinations and receiver link. Existing PHP URLs, queued operations and persisted data remain usable. Application rollback does not revert operations an operator already submitted through legacy forms. LTS is unchanged.

## Final diff review

The review pass checked the route defaults against the legacy realm map, catalog ownership, selected columns against `cacti.sql`, editor action names against each PHP switch, installation-relative links, filter bounds, menu reachability and the absence of mutation SQL in the new readers. It identified two integration requirements: register every route with preference-based locale selection, and include each feature realm in the native authorization inventory and its HTTP probe selection. Both are included. The inventory pins the screen mapping and admission sources and rejects a changed source; it does not certify arbitrary callers of a screen-dependent authorization contract.

The full PHP run used the built frontend assets on PHP 8.4.25. An earlier Composer invocation selected system PHP 8.5 and an earlier sandboxed run blocked loopback fixtures; neither is the acceptance result. The later full run passed with 259 optional skips. The final enum argument resolver and catalog wiring were additionally checked by the focused suite. Installed browser and legacy write-form acceptance remain pending, so this slice is recorded as implemented rather than fully verified.

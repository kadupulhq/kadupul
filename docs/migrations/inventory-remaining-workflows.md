# Remaining Inventory workflows on main

This queue tracks complete workflows, not just Twig templates. Each slice includes
Symfony routing/forms, an Inventory application use case, domain rules where
needed, persistence ports/adapters, authorization, regression coverage and legacy
compatibility. LTS remains unchanged. Plugin-owned pages are outside this queue.

| Workflow | Status |
| --- | --- |
| Device listing, filtering, exports and details | Migrated |
| Device creation | Merged in #214 |
| Sites list, full edit/create, bulk duplicate/delete and legacy entry | Merged in #239 |
| Device identity, metadata and enabled state | Migrated |
| Device site assignment | Merged in #250 |
| Device polling settings | Merged in #252 |
| Device SNMP protocol and credentials | Merged in #257 |
| Device template assignment | Merged in #262 |
| Device collector assignment | Merged in #266 |
| Device bulk enable/disable | Merged in #272 |
| Device deletion and graph/data retention choices | PR #276 |
| Device bulk options, statistics and template synchronization | Statistics reset implemented in PR #288; template synchronization implemented in PR #295; bulk location/polling options implemented in PR #299; bulk assignments implemented in PR #302; SNMP credentials implemented in PR #303 |
| Device graph-template associations | Implemented in PR #305 |
| Device data-query associations and reindex settings | Implemented in PR #307 |
| Device reindex, poller-cache/debug and connectivity actions | Implemented in PR #309 |
| Device placement in trees/reports | Implemented in PR #314 with owning-module contracts |
| Legacy `host.php` compatibility entry and menu cutover | Implemented on `feat/inventory-legacy-cutover`; validation and review pending |

The device editor is currently migrating in focused PRs because credentials,
collector replication, and template association changes have different failure
and authorization requirements. The presence of a Symfony device page does not
mean all legacy device operations have moved.

Collector administration, template authoring, graph/data-source administration,
automation and identity administration belong to subsequent module migrations.
Device assignment to a collector or template is part of Inventory and stays in
this queue; administration of those referenced objects does not.

Legacy device deletion has no restore operation: local rows are deleted and remote tombstones are temporary cleanup state, not recoverable inventory.

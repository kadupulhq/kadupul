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
| Device site assignment | PR #250 |
| Device polling settings | In progress |
| Device SNMP protocol and credentials | Pending |
| Device template and collector assignment | Pending |
| Device bulk enable/disable | Pending |
| Device deletion, restoration and purge choices | Pending |
| Device bulk options, statistics and template synchronization | Pending |
| Device graph-template associations | Pending |
| Device data-query associations and reindex settings | Pending |
| Device reindex, poller-cache/debug and connectivity actions | Pending |
| Device placement in trees/reports | Pending; preserve owning-module boundaries |
| Legacy `host.php` compatibility entry and menu cutover | Pending completion of its workflows |

The device editor is currently migrating in focused PRs because credentials,
collector replication, and template association changes have different failure
and authorization requirements. The presence of a Symfony device page does not
mean all legacy device operations have moved.

Collector administration, template authoring, graph/data-source administration,
automation and identity administration belong to subsequent module migrations.
Device assignment to a collector or template is part of Inventory and stays in
this queue; administration of those referenced objects does not.

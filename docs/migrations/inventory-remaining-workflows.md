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
| Legacy `host.php` compatibility entry and menu cutover | Implemented in PR #316; final validation and review pending |

The device editor is currently migrating in focused PRs because credentials,
collector replication, and template association changes have different failure
and authorization requirements. The presence of a Symfony device page does not
mean all legacy device operations have moved.

Collector administration, template authoring, graph/data-source administration,
automation and identity administration belong to subsequent module migrations.
Device assignment to a collector or template is part of Inventory and stays in
this queue; administration of those referenced objects does not.

Legacy device deletion has no restore operation: local rows are deleted and remote tombstones are temporary cleanup state, not recoverable inventory.

## Association and maintenance operational boundaries

Association and maintenance workers snapshot collector connection configuration
without locking the heartbeat row during SNMP or HTTP work. Immediately before
commit, they lock and recheck the current configuration and, for remote collectors,
heartbeat availability. Configuration changes, disablement or deletion reject
commit; transactional local writes roll back. Heartbeat/statistic updates alone
do not invalidate the operation.

Before beginning their owned transaction, these short-lived workers detect
MariaDB's `innodb_snapshot_isolation` setting and select and verify session-level
`OFF`. This retains repeatable nonlocking reads while permitting the final
locking read to see a concurrently updated heartbeat. MariaDB's newer default
otherwise rejects that read and rolls back the transaction. MySQL has no such
setting and receives no setting change; no global server configuration is changed.

The parent process allows the existing 120-second local-work margin plus 300
seconds per possible remote request. This uses the HTTP timeout cap rather than
the current setting, so increasing the setting during an operation cannot make
its parent budget too short. Query association add/change budgets one request;
remote full reindex budgets the number of queries in the validated immutable
maintenance state passed from the use case to the adapter; selected
query diagnostics, reload and connectivity budget one. Local and non-network
maintenance retain 120 seconds. A changed association set changes the revision
and is rejected by the worker before discovery.

Deployments must allow the corresponding request duration through PHP/FPM and
HTTP gateway limits when using these synchronous actions. This process budget
does not make stream timeouts an absolute network deadline or make remote HTTP
effects transactional. If a remote operation survives failure, inspect the
collector and resynchronize before retrying. Rollback of this code change is a
revert; there is no schema change or dependency upgrade.

Association publication commits the authoritative primary first. Primary commit
failure prevents collector commit. Collector commit failure after primary success
is reported as failure, with the primary change retained; inspect the collector
and perform a FullSync to reconcile it. This is a partial outcome, not distributed
atomicity. Malformed command kinds and operations are rejected as invalid before
mutation rather than reported as an uncertain write outcome.

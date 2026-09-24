# Architecture atlas alignment

This ledger maps the repository to the
[Kadupul architecture atlas](https://kadupul-architecture-atlas.tomastest.chatgpt.site/#view=architecture&stage=reads&node=audit).
It records evidence on the main branch; it is not a claim that a planned
component exists. Update the row and its evidence in the same pull request that
changes a boundary.

| Atlas area | Status | Repository evidence | Next gate |
| --- | --- | --- | --- |
| Symfony composition root | Implemented | src/Kernel.php, config/services.yaml, public/index.php, and app.php own migrated requests. | Keep legacy bootstrap out of Symfony entry points. |
| Domain and application isolation | Implemented for migrated modules | tests/Symfony/ArchitectureTest.php rejects framework, global, infrastructure, and cross-module internal dependencies. | Extend modules only with a working use case and the same guard. |
| Twig read presentation | Implemented for migrated Inventory routes | Inventory controllers render local templates under templates/inventory; JSON and CSV use the same application queries. | Validate accessibility and graph output before replacing the remaining legacy routes. |
| Read repository ports | Partial DBAL adoption | Inventory queries depend on application ports. Assignable-site, device-creation choice, device-site filter, device details and site catalog reads use Doctrine DBAL behind their existing ports, with device visibility read on the same connection and built by rules shared with the PDO path. The device list and write adapters retain prepared PDO projections. The DBAL connection accepts an optional SELECT-only MySQL user and falls back to the primary credentials when none is set. | Move another read adapter only with behavior coverage; application and domain APIs must not change. |
| Schema ownership | Legacy installer | cacti.sql and install/upgrades own every table. Doctrine is used as DBAL only; there is no ORM mapping and no Doctrine Migrations configuration. | Adopt ORM or Migrations only after a table has one documented owner and upgrade tests cover both paths. |
| Pinned offline browser assets | Guarded foundation | Migrated Twig pages make no browser asset requests, and BrowserAssetBoundaryTest rejects remote resource URLs. Legacy browser dependencies are exact-version npm inputs with a lockfile and verified local build outputs. | Pin, build locally, and add offline verification for any browser dependency introduced by migrated pages. |
| Route rollback controls | Partial | Legacy pages remain deployed and provide operational rollback. There is no route-level feature-flag service. | Add explicit per-route cutover flags before replacing a legacy URL. |
| Authorized transactional writes | Implemented for listed Inventory slices | Application commands authorize before ports; write adapters recheck authorization and revisions under transaction locks. Behavioral probes exercise revocation races and rollback. | Remove each legacy writer only after its compatibility harness passes. |
| Asynchronous work | Partial | Symfony Messenger and Scheduler run row-cache cleanup with explicit enablement and failure limits. Most migrated writes remain synchronous. | Require idempotency, retry, and failure behavior before moving a command to Messenger. |
| Structured audit | Partial | IdentityAccess Contract AuditEvent defines a closed, versioned event. Device edit, device creation, and device template assignment correlate the Symfony adapter with the isolated worker; site creation, editing, deletion, and duplication record each site after the adapter transaction resolves. Each records success, post-authorization failure, and persistence-recheck denial in a dedicated JSONL sink. | Move device collector assignment and the bulk device state writes to the contract once the open state-worker changes land, record application-level denials, add tamper-evident archival, and set an operator retention policy. |
| Entry-point authorization | Evidence in CI | tests/security/baselines/entry_points.baseline.tsv inventories every HTTP entry point with the gate read from the PHP syntax tree; CI fails on drift, on an unclassified entry, and on a path Nginx denies that Apache would serve. CLI tools refuse HTTP before doing any work. entry_point_authorization.py refuses anonymous, revoked-realm and console-only callers on a real install. Apache .htaccess files deny the directories and include files Nginx denies; its root dotfile and manifest denies are opt-in through .htaccess.dist. The sweep checks each returns 403, with and without .htaccess.dist, while page assets still load. | Add each new route or page to the baseline in the change that adds it. |
| IdentityAccess | Partial | Public actor, console-access, locale, contact, and audit contracts exist; legacy sessions remain behind adapters. | Migrate credential issuance, external providers, logout, and CSRF ownership before retiring native-session compatibility. |
| Inventory | Partial | Device and site reads plus selected edit/create/lifecycle/assignment commands use domain/application/port boundaries. | Complete remaining advanced settings, plugin contributions, exports, and legacy route cutover. |
| Alerting | Partial | Test mail and administrator notification paths have application ports and infrastructure adapters. | Move alert rules, evaluation, incidents, and notification intent behind the module boundary. |
| Collection and Graphing | Planned | These capabilities remain in the procedural application; the architecture document defines ownership only. | Establish a first tested use case and port before adding module scaffolding. |

## Audit event policy

The kadupul.audit.v1 schema contains only a correlation identifier, actor
identifier, action, target type and identifier, authorization decision, and
outcome. The contract intentionally has no arbitrary context field. Submitted
form values, credentials, session identifiers, exception messages, hostnames,
and plugin output must not enter an audit record.

The current legacy adapter appends one JSON object per line to the fixed
log/kadupul-audit.jsonl path after the worker transaction resolves. It does not
depend on the configurable generic log destination or verbosity. The sink
rejects symbolic links and non-regular files, locks each append, and enforces
0600 permissions. The correlation identifier is generated in trusted adapter
code, passed as data over the fixed worker protocol, and validated again in the
worker. A rejected or malformed worker request receives a worker-generated
identifier.

Site writes run in the Symfony process rather than a worker. Their adapter
records through the same sink once commit or rollback returns, and a bulk
operation writes one event per selected site under a single correlation
identifier.

Administrators who can write the installation log directory can still rotate or
replace the audit file. Operators must restrict filesystem access and define
retention and protected archival for their deployment. Do not claim tamper
resistance or a project-wide retention period until that deployment policy and
an archival destination exist.

For each write migrated to this contract, behavioral coverage must demonstrate:

1. a successful authorized attempt records allowed and succeeded;
2. a rejected authorization records denied and denied;
3. a post-authorization conflict or operational error records allowed and failed;
4. no submitted secret or exception text appears in the record; and
5. the record is emitted only after commit or rollback has resolved.

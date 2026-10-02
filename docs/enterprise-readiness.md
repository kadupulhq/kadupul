# Enterprise readiness

| Requirement and acceptance criteria | Subsystem | Status | Evidence | Gaps and risks | Release gate |
| --- | --- | --- | --- | --- | --- |
| Failed or incomplete audit baseline imports preserve working tables and stop comparison and repair | Database maintenance | implemented | `AuditDatabaseTest`, `DbalSchemaAuditTest`: canonical dump rejection and duplicate column/index insertion failures; MariaDB 10.11 isolated regression run | MySQL 8.4, permission-denied rename and cleanup failures, and full CLI parity remain to be verified | Before retiring the compatibility audit CLI |
| Failed core or plugin upgrades cannot authorize audit work | Database maintenance | implemented | `LegacyWorkerProcessTest`: real worker subprocesses for failed core, standard, alternate, setup and plugin process paths | Full installation upgrade and recovery rehearsal remain unverified | Before releasing the migrated audit command |

Statuses: not assessed, planned, implemented, verified. Code existence alone
does not verify a requirement. The compatibility CLI remains supported while
the migrated command is assessed; merging this work is not a release.

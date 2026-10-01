# Data Source Profile reference integrity

Deleting a profile locks its parent row before checking template and local-data
usage. A range lock alone cannot protect a writer that resumes after deletion
commits, so the database guards every newly assigned nonzero profile reference.

Profile copies and profile/RRA form saves run within a transaction, lock and revalidate the
posted parent ID before saving, acknowledge all mutations and raise success only
after commit. A queued save that resumes after deletion cannot recreate the
deleted parent. Failed child writes roll back the parent change.

Fresh `cacti.sql` imports and the registered `1_2_34` upgrade install
`kadupul_profile_reference_insert` (AFTER INSERT) and
`kadupul_profile_reference_update` (BEFORE UPDATE) on `data_template_data`.
The same insert/update guards, with `_rra` and `_cf` in their names, protect
`data_source_profiles_rra` and `data_source_profiles_cf`. The six insert/update guards
perform a locking parent lookup and reject a missing profile with SQLSTATE
45000. InnoDB rolls back the rejected statement. This covers form saves, imports,
duplication, installer writes, suggested-value updates, and direct SQL writers.
Two BEFORE DELETE guards on RRA and CF definitions also lock their existing parent, allowing historical orphan cleanup while coordinating deletion.
The insert guard runs after insertion so existing-row upserts use the update
rule; unchanged historical orphan IDs remain editable, and zero retains its
legacy meaning. Assigning a new orphan reference is rejected. No existing
references are rewritten and no foreign key conversion is performed.

Both bulk collector replication and per-device replication copy every referenced
nonzero profile parent and its RRA/CF definitions before writing `data_template_data`.
Every requested profile must have RRA and CF rows. Delivery replaces stale
collector definitions within one InnoDB transaction and verifies all three
catalogs against the source rows before committing. A conflicting RRA identity,
missing definition, rejected write, or altered delivered value rolls back the
catalog changes. Schema creation occurs before the transaction because MySQL
DDL implicitly commits. A missing parent or rejected definition copy stops that table's replication before its schema or
existing rows are changed. This keeps guards active on collectors without losing
their existing data-source definitions when custom profiles are introduced.

Bulk all/settings/data synchronization validates collector guards immediately after connecting, before any table writes, including the schema version. A collector without intact guards retains its recorded version so the required upgrade remains eligible. Data delivery repeats the guard check before replacing definitions.

Source catalogs must also use InnoDB and expose all eight intact guards. This prevents child insert phantoms while a caller-owned READ COMMITTED transaction holds selected parent rows. Missing or changed source guards refuse copying without committing or rolling back the caller. Their locking reads share a transaction, preventing an interleaved source edit from producing a mixed catalog. A transaction opened by replication is acknowledged and released before remote delivery; a caller-owned transaction remains open and retains its pending writes and locks for the caller to commit or roll back.

Before catalog delivery, both collector paths inspect all eight expected guard bodies, tables, timing and events through the collector connection. Missing tables or missing/changed/inaccessible guards refuse delivery and preserve references; schema creation alone does not install triggers. Provision or rerun the registered migration on the collector before retrying.

Reference delivery then opens a separate InnoDB transaction and locks every
referenced parent in ID order, rechecking that all parents remain present. Bulk
replacement uses DELETE inside that transaction instead of TRUNCATE. Both
bulk and per-device paths require acknowledgement of each reference write and
exact read-back of each delivered value and an explicit successful commit. A refused cleanup or a later rejected row rolls
back the whole reference delivery, preserving previous rows and preventing
success messages, dependent table replication and sync-flag clearing. Schema
creation is checked before the transaction; schema mismatches fail closed rather
than dropping existing collector tables. An existing caller transaction is
preserved and the operation is refused. Bulk and device entry points acknowledge setting the retry flag before availability or connection attempts; a rejected retry-state update aborts before transport or collector mutations. Bulk all/data synchronization centrally acknowledges both last_sync and retry completion, so CLI, UI and installer callers share one successful transition. Partial auth/settings runs preserve pending data retries. Device delivery leaves FullSync queued.

The installation account must have TRIGGER privileges on the database, and the
trigger definer must retain permission to read and lock `data_source_profiles`.
The application account also needs TRIGGER privileges to inspect the guard
metadata before deletion; an account that cannot inspect it fails closed.
Binary-log policies may impose additional server privileges during installation.
A denied creation or an existing modified guard stops the upgrade explicitly.
Physical profile deletion and form saves check all eight trigger bodies, target tables, timing, and events and
fail closed if any guard is missing, modified, or inaccessible, or if any of `data_source_profiles`, `data_template_data`,
`data_source_profiles_rra`, or `data_source_profiles_cf` uses a non-InnoDB engine. Run the
upgrade after restoring the required privileges; do not remove this check.

Keep triggers in database backups and restores. The project's default
`mysqldump`/`mariadb-dump` invocation includes triggers; an external `--skip-triggers`
backup requires reinstalling them before deletion is available. The schema audit
currently reconciles columns and indexes using ALTER TABLE and leaves triggers
in place. It does not create or validate these guards; the upgrade and deletion
check do that. A restore or a tool that recreates `data_template_data` must retain
or reinstall the guards. The default schema keeps its semicolon delimiter until
the explicit trigger section, which temporarily uses `$$`.

The database contracts exercise data-reference, RRA and consolidation writers
waiting on deletion: after commit it
is rejected without an orphan, and after rollback it succeeds. They also cover
unchanged orphan upserts, zero references, invalid reassignment, and missing or
modified guards. Tests use unique InnoDB tables and observe the writer's actual
lock wait through a separate database connection before completing deletion.

Database semantics:
[MySQL trigger syntax](https://dev.mysql.com/doc/refman/8.4/en/trigger-syntax.html)
and [MariaDB trigger overview](https://mariadb.com/docs/server/server-usage/triggers-events/triggers/trigger-overview).
Backup defaults:
[mysqldump](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html) and
[mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

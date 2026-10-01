# Data Source Profile reference integrity

Deleting a profile locks its parent row before checking template and local-data
usage. A range lock alone cannot protect a writer that resumes after deletion
commits, so the database guards every newly assigned nonzero profile reference.

Fresh `cacti.sql` imports and the `1_2_31` upgrade install
`kadupul_profile_reference_insert` (AFTER INSERT) and
`kadupul_profile_reference_update` (BEFORE UPDATE) on `data_template_data`.
They perform a locking parent lookup and reject a missing profile with SQLSTATE
45000. InnoDB rolls back the rejected statement. This covers form saves, imports,
duplication, installer writes, suggested-value updates, and direct SQL writers.
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

Reference delivery then opens a separate InnoDB transaction and locks every
referenced parent in ID order, rechecking that all parents remain present. Bulk
replacement uses DELETE inside that transaction instead of TRUNCATE. Both
bulk and per-device paths require acknowledgement of each reference write and
an explicit successful commit. A refused cleanup or a later rejected row rolls
back the whole reference delivery, preserving previous rows and preventing
success messages, dependent table replication and sync-flag clearing. Schema
creation is checked before the transaction; schema mismatches fail closed rather
than dropping existing collector tables. An existing caller transaction is
preserved and the operation is refused.

The installation account must have TRIGGER privileges on the database, and the
trigger definer must retain permission to read and lock `data_source_profiles`.
The application account also needs TRIGGER privileges to inspect the guard
metadata before deletion; an account that cannot inspect it fails closed.
Binary-log policies may impose additional server privileges during installation.
A denied creation or an existing modified guard stops the upgrade explicitly.
Physical profile deletion checks both trigger bodies, timing, and events and
fails closed if either guard is missing, modified, or inaccessible, or if any of `data_source_profiles`, `data_template_data`,
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

The database contracts exercise a writer waiting on deletion: after commit it
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

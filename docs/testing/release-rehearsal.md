# Upgrade and rollback rehearsal

`tests/Support/Behavior/release_readiness.py` builds the actual baseline source,
installs its schema in a disposable MariaDB container, creates and polls RRD data,
then upgrades that database using the candidate installer. It preserves an enabled
compatibility plugin, a custom device template and graph association, and a custom watermark. Before another poll changes samples,
it compares the RRD byte hashes and database records against the snapshot.

The rehearsal checks login, the production graph renderer's PNG output, plugin
callbacks and polling. It repeats the upgrade both before and after completed-wizard settings are cleared
to check idempotence. Rollback
restores the baseline image **and** its matching database and RRD snapshot, checks
the restored records and hashes, then verifies login, graphing, plugins and polling
again. It never points at an existing installation.

The candidate upgrade includes one exclusively owned collector marked down. The
actual native synchronization path must retain a partial receipt and one pending
collector, including after an explicit finishing retry. A separate database on
the same disposable database service supplies the repaired collector. Its template
name and plugin version are deliberately made stale after cloning the upgraded
schema. A successful retry must replace those values through real replication,
clear the pending marker and record `last_sync`; an unchanged clone is insufficient.

Before that successful retry, two finishing attempts are interrupted at the real
collector replication write. An independent InnoDB transaction locks only the
owned collector row. The harness observes the running receipt and the blocked
native `UPDATE`, then either kills the verified native CLI descendant or stops the
dedicated database service. PID ancestry, user, working directory, exact worker
argument and process start time establish ownership. Both the native worker and
its outer command must exit before recovery. A stale attempt number must be
rejected; recovery of the exact stopped attempt must not start another worker.

All interrupted attempts preserve the confirmed primary version, representative
application records and RRD bytes. A database trigger rejects primary version
updates during finishing retries, so schema replay fails the rehearsal. The
report requires all three cases (`partial_collector_sync`, `worker_crash`, and
`database_outage`) and their persisted invariants before `complete` can be true.

```sh
git fetch https://github.com/Cacti/cacti.git 6482af547c204199e829b7a0df0b7a13db3e0a58
PHP_VERSION=8.4 mise exec python@3.12.12 -- python tests/Support/Behavior/release_readiness.py \
  --baseline 6482af547c204199e829b7a0df0b7a13db3e0a58
```

The pinned baseline is release 1.2.30. Evidence goes to
`tests/behavior/results/release-readiness/<project>/observations.json`; an exception or
failed invariant leaves `complete: false` and returns a nonzero status. The
manifest records source revisions, runtime provenance, RRD hashes and observed
commands. Database dumps and raw snapshots live only in a temporary directory.
The unique `kadupul-release-<uuid>` Docker project is removed after the run.

The pinned PHP provenance check remains mandatory for the full release report.
A local test using cached images may record their actual image IDs and exercise
the native fault helpers separately. Such a test does not establish full pinned
release verification; missing provenance must leave the full report incomplete.

The process and database interruptions occur after the primary schema has
committed and before collector synchronization. They do not establish safe
interruption at every DDL statement, distributed atomicity, independent collector
host operation or recovery for every external plugin.

This verifies the fixture upgrade path and compatibility plugin. It does not
establish compatibility with every external plugin or every historical schema.
A production rollout still needs a quiesced, restorable backup from that specific
installation; replacing application files alone is not a database rollback.

Each invocation uses a unique Compose project, image tags and lock. Cleanup
only removes that invocation’s resources. Invalid baseline revisions and
unavailable Docker still produce `complete: false` observations. Default evidence paths include the unique project name. If overriding
`--output`, use distinct paths for concurrent rehearsals.

The harness disables seeded discovery networks after installation so background subnet discovery cannot mutate fixture data while rehearsal evidence is collected.

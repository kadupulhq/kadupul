# Remote collector synchronization and recovery

The seven findings reviewed at `45782e207167e6850126575b2c17769fc5908661`
are addressed locally on `fix/lts-remote-sync-recovery`. No publication or
release is claimed.

Table replacement now rejects unknown engines, incompatible column contracts
and caller-owned transactions before changing existing rows. InnoDB replacement
uses a transaction for empty and nonempty sources. It no longer drops an
incompatible table or falls back to `TRUNCATE`. Column names, types, collation,
nullability, defaults and key/extra attributes are compared, including NULL versus
an empty default. This is a compatibility check, not a schema migration engine.

All `replicate_out` callers acquire the same destination-database advisory lock.
Recovery acquires ownership on its main connection, scoped to the collector.
Publication writes do not transparently reconnect: a disconnected owner cannot
overwrite its successor. PID cleanup matches the owner. Locks are released by
the owning connection, not by an expiring timer.

Queue-read and metadata-copy failures now fail recovery. A local
`settings.recovery_pending` entry preserves unfinished work even with empty
queues. Settings replication excludes this marker. Metadata results propagate
to recovery, device and data-query callers; the remote data-query caller also
preserves a pending recovery obligation.

Resource reads require valid base64 and a matching advertised digest before
replacement. A temporary file in the destination directory receives the checked
bytes and metadata before atomic rename. Failures preserve the old file; symlink
destinations are refused. Failed source reads or cache saves leave the directory
digest unchanged so an unchanged directory can be retried.

Profiling found another reliability defect: completed recovery pages remained
live during the next fetch. Recovery now releases those arrays and fetches at
most 10,000 rows per page. The previous one-second throttle remains after each
150,000 examined rows, independent of page size.

## Verification

PHP 8.1.34, MariaDB 10.11.19, isolated tables and temporary filesystem fixtures:

- Boost/poller suites: **429 passed, five skipped**. The skips require real
  legacy RRDtool 1.3/1.4; installed modern RRDtool tests ran.
- Native replacement: **11 passed**, including schema drift, NULL/empty defaults,
  caller transactions, metadata failures, insertion failure, exceptions and
  successful/failed empty replacement.
- Native ownership: **12 passed**, covering contention, recursive acquisition,
  wrong-owner release, replication classes, exceptions and a stale connection.
- Existing native recovery retry contract: **ten passed** across case, accent,
  whitespace and charset differences.
- PHP syntax, `git diff --check` and the changed workflow's `actionlint` check
  passed. Replacement checks were repeated after the final strict comparison.

New cases use the maintained token-based production-function extractor. Database
adapters are explicit; transaction and advisory-lock behavior execute against
MariaDB. Tests are registered in the existing Boost suites. The capacity workflow
also drains full-width samples under a 128 MiB PHP limit. No production database,
device or RRD file was used; dependencies were reused without installation.

Complete installed CLI/web/collector flows, Windows file replacement, other
database versions, hosted CI and an independently reviewed release remain
unverified locally.

## Profiling evidence

`tests/benchmarks/remote_collector.php` records elapsed time, client PHP CPU,
process peak allocation and database-call time/counts. It runs extracted production
functions against native temporary tables and verifies final row counts. Metadata
handoff, scheduler sleeps, network latency and production bootstrap/plugin memory
are excluded. Resource saves use an explicit persistence adapter.

The final backlog fixture has 3,000 sources with 100 timestamps each:

| 300,000 samples | Original implementation | Final implementation |
| --- | --- | --- |
| Two-byte output | Exceeded 128 MiB; with a 512 MiB limit: 194.1 MiB peak, 8.56 s | 14.0 MiB peak, 10.15 s, completed under 128 MiB |
| 512-byte output | Exceeded even the 512 MiB profiling limit | 43.8 MiB peak, 19.86 s, completed under 128 MiB |

These are individual observations, not capacity guarantees. Smaller pages trade
additional scans for lower memory use. Both final runs requested two seconds of
scheduler sleep, excluded from measured elapsed time.

Earlier three-run medians on the first fix iteration were 2.90/2.99 s for
original/patched 100,000-sample recovery, 0.378/0.359 s for 100,000 configuration
rows, and 0.481/0.460 s for 1,000 resource files plus an unchanged scan. These
precede the final memory/error-handling changes and are historical measurements,
not final throughput claims. Acknowledgment deletes dominated that recovery
fixture at roughly two-thirds of elapsed time. No speculative index or deletion
optimization was introduced.

Reproduce against a disposable database using the existing `BOOST_DB_HOST`,
`BOOST_DB_PORT`, `BOOST_DB_NAME`, `BOOST_DB_USER` and `BOOST_DB_PASSWORD` inputs:

```sh
cd tests
mise exec php@8.1.34 -- php vendor/bin/pest --configuration phpunit-boost.xml
mise exec php@8.1.34 -- php vendor/bin/pest --configuration phpunit-boost-mariadb.xml integration/RemoteSyncReplacementMariaDbTest.php
mise exec php@8.1.34 -- php vendor/bin/pest --configuration phpunit-boost-mariadb.xml integration/RemoteCollectorOwnershipMariaDbTest.php
PROFILE_KIND=recovery PROFILE_ROWS=300000 PROFILE_SAMPLES_PER_SOURCE=100 PROFILE_OUTPUT_BYTES=512 mise exec php@8.1.34 -- php -d memory_limit=128M benchmarks/remote_collector.php
```

Other profile kinds are `replacement` and `resources`; `PROFILE_SOURCE_ROOT`
selects a comparison checkout. Use one positional Pest file per invocation.

## Rollout and rollback

Stop synchronization/recovery workers before replacing code; older workers do
not participate in the new locks. Deploy main-server and remote-collector LTS
code together. Verify matching schemas and InnoDB replacement tables before
retrying full sync. Schema drift and nontransactional replacement now fail
without destructive automatic repair. Use supported upgrade procedures to
correct incompatible tables; this patch performs no engine conversion.

Back up configuration, resources and queues. To roll back, stop workers and
restore prior code; no column/index migration is needed. Keep queued rows intact.
The old code ignores `recovery_pending`, so explicitly reconcile unfinished
metadata replication before declaring recovery complete. Rollback restores the
reviewed failure modes.

The local `main` revision `63f963c4d4346bc259beb091683ab381ee72cac3` retains the
corresponding legacy entry points and destructive/empty-result patterns; it is
not treated as unaffected. Equivalent migration-branch corrections and their
verification remain separate work. This implementation covers the requested LTS
branch.

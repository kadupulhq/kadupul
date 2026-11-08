# LTS subsystem failure regressions

Scope: correctness and collection reliability on `lts/1.2`, based on
`b3fcf7eb3c`. The earlier review used `ef9145d0de`. The intervening sql_save fix
already rejects failed writes; this change retains and exercises its native
regressions instead of duplicating it.

`SubsystemFailureNativeTest` executes production functions with the shipped
MySQL schema in a uniquely owned temporary database. It covers failed cache
reads and update/insert/delete stages, successful and empty replacements,
nontransactional tables, temporary-table shadows, caller transactions, site
unlink failures, name limits, invalid and successful device saves, sample retry,
permissive-mode truncation, stable delayed timestamps and real local script exit
statuses. A separate destination database verifies byte-preserving parameter
binding with quoted and Unicode values, native and emulated prepares, and
`NO_BACKSLASH_ESCAPES`. Both prepare modes retain truncating batches and restore
the original connection mode. Logging, request/session setup and unrelated hooks
are fixture boundaries. It does not contact devices or write production RRDs.

Run from `tests/` with a disposable server and an account allowed to create and
drop fixture databases:

```sh
KADUPUL_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306' php vendor/bin/pest --configuration phpunit-unit.xml Unit/Database/SubsystemFailureNativeTest.php
KADUPUL_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306' php vendor/bin/pest --configuration phpunit-unit.xml Unit/Database/SqlSaveOutcomeNativeTest.php
php vendor/bin/pest --configuration phpunit-unit.xml Unit/DataCollection/Cmd
```

Use environment-provided database credentials where required. The suite is
registered in `phpunit-boost-mariadb.xml` for the existing database CI matrix.
The unit runner discovers the local queue tests automatically. A missing database
skips native database tests; it is not a database pass. Invoke the full runner
with `--php` pointing to the selected runtime's actual binary so nested processes
do not resolve a different local shim.

Local evidence: PHP 8.1.34, MariaDB 10.11.19 and MySQL 8.4.13 each passed all 43
database cases. The 43 cases also passed on PHP 8.4.25 with MySQL 8.4.13, using
the unit runner's legacy-Pest deprecation setting. The existing five sql_save
outcome cases passed on MariaDB at
`ab6685862e`. Twenty command/queue tests passed on PHP 8.1.34. The Boost contract
suite with RRDtool passed 260 cases, with five environment-dependent skips.
Historical native-fixture coverage at `ab6685862e` measured 82.2% of executable
lines in `database_atomic.php` and 83.5% in `poller_output_spool.php`; this is not
100% code coverage or a measurement of the subsequent correction. Additional
local queue tests exercise paths outside that subprocess measurement. Full-suite outcomes
and final revision checks belong in the PR verification record.

A separate source review traced web and CLI rebuild outcomes, device disable
ordering, duplicate-site validation and the three PHP poller flush sites. It
reviewed the exclusive queue file creation, same-connection metadata checks,
rollback/savepoint ownership and the distinct remote commit boundary. Sink
inventory entries were refreshed only for affected files after reviewing these
changes; existing checks were retained.

CI exposed an obsolete handoff assertion for manual SQL quoting. Replacing it
with destination-binding coverage also caught MySQL's unsupported prepared
`SHOW WARNINGS` protocol: PDO's fallback overwrote insert diagnostics. The warning
query now uses the text protocol temporarily, preserving both insert diagnostics
and the caller's prepare setting. The native-destination case failed before this
correction. Review database diagnostics under both prepare modes when changing
the handoff path; success and retained-warning cases must both execute.

Main propagation assessment: main's device save has already removed the early
disable call, and `sites.php` forwards to Symfony workflows. Those replacements
need separate acceptance verification. Main still ignores the script exit status;
its host rebuild marks rows before generation. Main's output helper checks write
results but does not retain an unavailable-database batch locally. Equivalent
collection corrections remain pending on main; this PR makes no migration-branch
or release claim. Installed browser flows, native Windows queue ACL behavior,
remote-collector propagation and deployment-scale performance remain unverified.

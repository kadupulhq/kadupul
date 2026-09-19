# MariaDB and MySQL compatibility

Track engine differences in [issue #104](https://github.com/kadupulhq/kadupul/issues/104). A passing MariaDB run does not establish MySQL compatibility, or vice versa. CI records the server version, vendor, SQL mode, server collation, and default storage engine before running the contracts.

## Regression targets

The database contract job runs independently on MariaDB 10.6, 10.11, and 11.8, and MySQL 8.0, 8.4, and 9.7. These are test targets, not a promise about vendor maintenance lifetimes or full application compatibility. Installation, upgrade, rollback, and the remaining application queries still need their own evidence on each release target. Image tags select release lines; use the exact server version printed by the job when reporting a result.

## Confirmed differences

| Area | Observed behavior | Handling and regression |
| --- | --- | --- |
| Queue acknowledgement query plan | MariaDB 10.11 used `PRIMARY/range` for the four-column tuple predicate. MySQL 8.4 did not use the primary key. The indexed fields are `(local_data_id, rrd_name, time)`; `output` is unindexed. | Use explicit primary-key equalities with a binary payload guard, in batches of 500. Assert primary-key range access on sparse 2-row and 500-row deletions from a 10,000-row queue. A tuple-locator attempt still failed the larger prepared-query test on MySQL 8.4 and was replaced. |
| Observed-value comparison | Case-insensitive/PAD SPACE collations can equate `U` with `u`, or `42` with `42 `, and delete a replacement that the writer never observed. This risk applies to both engines. | Convert both payloads with `CONVERT(... USING utf8mb4)`, then compare using `CAST(... AS BINARY)`; the older `BINARY expr` syntax produces deprecation warnings on MySQL 8.4. Assert replacement retention and normal acknowledgement on both engines, including real-time pollers. |

`tests/integration/BoostProcessTableMariaDbTest.php` retains its historical filename but executes the same production contracts on both vendors. `tests/phpunit-boost-mariadb.xml` also runs the durable queue migration contract. Tests use a dedicated disposable database and must never be pointed at production.

SQLite unit fixtures translate MySQL binary casts to BLOB casts and model the case-insensitive/PAD SPACE comparison. They test control flow; the real-engine integration tests establish SQL behavior and query plans.

For each new difference, retain a reproducer, exact engine version and settings, result/plan observations, chosen handling, and automated regression. Keep missing evidence visible in issue #104 rather than treating an untested version as passing.

Plan assertions request `EXPLAIN FORMAT=TRADITIONAL` explicitly. The MySQL 9.7.2 image returned a different default format, so assuming `key`/`type` columns was a test-adapter error. [MySQL documents the explicit format and session-dependent defaults](https://dev.mysql.com/doc/refman/9.7/en/explain.html). This is distinct from a failed index-use assertion.

## Mixed character sets

A binary cast alone compares storage bytes against connection bytes. With a latin1 queue column and a utf8mb4 connection, even an unchanged `café` could remain unacknowledged. Convert both operands to utf8mb4 before the binary comparison. The contract matrix covers both collations, case and trailing-space replacements, and non-ASCII unchanged samples. This preserves exact character comparison without relying on identical storage and connection encodings.

## Verified queue-contract matrix

Local disposable-server runs on 2026-09-17 passed the following matrix. Main ran
35 queue acknowledgement contracts per engine; LTS ran 28 contracts, including
its additional Boost and migration checks. These results cover these contracts,
not full installation or application certification.

| Engine | Exact server version | Main | LTS |
| --- | --- | --- | --- |
| MariaDB 10.6 | 10.6.28 | 35 passed | 28 passed |
| MariaDB 10.11 | 10.11.19 | 35 passed | 28 passed |
| MariaDB 11.8 | 11.8.8 | 35 passed | 28 passed |
| MySQL 8.0 | 8.0.46 | 35 passed | 28 passed |
| MySQL 8.4 | 8.4.11 | 35 passed | 28 passed |
| MySQL 9.7 | 9.7.2 | 35 passed | 28 passed |

The LTS expiry contracts deliberately use different PHP and database timezones.
Incomplete samples expire using database-time arithmetic; complete unwritten
samples and samples exactly at the retention boundary remain queued.

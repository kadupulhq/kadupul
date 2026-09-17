# Retained-sample drain capacity diagnostics

The benchmark defaults to one million InnoDB queue rows. CI also runs the legacy
MEMORY fixture separately. Each fixture has 250,000 sources, two RRD fields
and two timestamps per source. It runs the production-shaped joined first-page
and retained-page cursor queries with 40,000-row pages. Independent queue tables
are populated before measurement. Each query and variant receives one warm-up;
six paired warm rounds alternate which index runs first. Warm-up times are
reported separately and are not cold-cache measurements. Tables are
connection-local temporary tables; it does not modify application tables.
The schema deliberately models the existing `(local_data_id, rrd_name, time)`
BTREE and compares an additional `(local_data_id, time, rrd_name)` BTREE.
Latin1 and its collation are explicit on both joined name columns: other character sets, row sizes, concurrent writers and
hardware need separate measurements. SHA-256 comparisons require identical
ordered samples across every run and index variant.

Historical MEMORY Docker diagnostics from the earlier sequential single-table method
(seconds, all three repetitions; not production capacity). These runs did not
control warm-up order, so the timing difference cannot be attributed solely
to the index. New CI artifacts use the paired warm method above:

| MariaDB | Query | Existing index | Matching secondary index |
|---|---|---|---|
| 10.11.19-MariaDB-ubu2204 | first | 0.172, 0.151, 0.147 | 0.053, 0.047, 0.046 |
| 10.11.19-MariaDB-ubu2204 | after_retained_page | 0.163, 0.160, 0.161 | 0.050, 0.049, 0.050 |
| 11.8.8-MariaDB-ubu2404 | first | 0.147, 0.142, 0.145 | 0.047, 0.041, 0.041 |
| 11.8.8-MariaDB-ubu2404 | after_retained_page | 0.157, 0.159, 0.157 | 0.044, 0.044, 0.044 |

Both servers used filesort with the existing key and selected `drain_order`
without filesort when the secondary index was available. CI repeats the
million-row diagnostic for both engines on MariaDB 10.6, 10.11 and 11.8 and
MySQL 8.4 and 9.7 LTS, and uploads separate raw timings,
EXPLAIN plans and ordered result hashes. Timing thresholds are deliberately
not CI assertions; ordering and page contents are.

Recommendation: retain the current primary key and evaluate adding the
secondary key during a controlled upgrade. Do not change the primary-key
identity or timestamp-group pagination. Index creation can take metadata locks and consume additional resources;
production migration needs an engine-specific measured resource budget and
quiesced pollers. Temporary-table timings do not measure production ALTER locking. This PR measures that candidate rather than
silently altering deployed queue tables. Query time alone excludes RRD I/O,
retention work, producer contention, batch deletion and end-to-end drain time.

## Real legacy RRDtool

`tests/benchmarks/build_rrd149.sh PREFIX` builds checksum-pinned RRDtool 1.4.9
source. `RRDTOOL_LEGACY_TEST_BINARY` must identify a real 1.3/1.4 executable;
an incorrect executable fails its version assertion. The normal retry test
uses the detected binary version instead of pretending a modern binary is old.
The dedicated pending-pipe test and the `legacy`/`legacy-unknown` rejected-field
cases run against RRDtool 1.4.9. The complete Boost contracts use the system
RRDtool binary, including their stale-timestamp and newer-sample retry cases.
The existing incomplete-leading-page and split-timestamp-group cases remain.

Keep the synchronous legacy update path: a successful pipe write is not an
acknowledgement that RRDtool persisted the sample. An acknowledged batching
implementation would need to consume each command response, preserve error
boundaries and only delete successfully committed source keys. The measured
query-index benefit can be pursued without weakening those guarantees; no
unacknowledged pipe optimization is enabled by these diagnostics.

These queue contracts do not establish full application compatibility with every
database release; installer, upgrade, and end-to-end coverage remain separate.

Run locally with an isolated MariaDB or MySQL database and credentials supplied through
`BOOST_DB_HOST`, `BOOST_DB_PORT`, `BOOST_DB_NAME`, `BOOST_DB_USER` and
`BOOST_DB_PASSWORD`:

```sh
QUEUE_ENGINE=InnoDB mise exec php@8.1.34 -- php tests/benchmarks/poller_queue.php > queue-innodb.json
QUEUE_ENGINE=MEMORY mise exec php@8.1.34 -- php tests/benchmarks/poller_queue.php > queue-memory.json
```

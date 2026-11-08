# Data-query reindex recovery

This maintenance change addresses the seven correctness/reliability findings in
host-cache replacement, command acknowledgement, retry publication, per-source
remapping, remote outcomes, index type selection and XML source-index fallback.
The implementation is present on `lts/1.2`; it is not a release certification.
Related tracking: #758. The companion branch carries equivalent behavior while
retaining its existing database and collector implementations.

Host-cache replacement and local sort/index/value/graph changes use separate
local transactions on the checked connection. Failed writes preserve the previous
state of that phase. Caller-owned transactions retain their earlier work through
savepoints. Permanent InnoDB tables are required; temporary shadows and
nontransactional tables fail before mutation. A per-host/query advisory lock
serializes complete reindexes without holding row locks across device I/O.
Database helpers stop on failure instead of reconnecting after ownership is lost.

Cache publication runs on every successful local reindex, including a retry whose
indexes already changed. A failed local write or collector publication returns
failure before completion hooks. Collector commits remain separate: a later
failure can leave earlier phases committed. Retrying repairs publication; this
is not a distributed transaction. Automation and plugin completion hooks retain
at-least-once execution semantics when a command must be retried.

Queue acknowledgement matches the selected command and its timestamps. Failed,
unprocessed and replaced commands remain queued. The existing schema has
second-resolution timestamps, so a successfully processed command from the
current second remains until a later invocation can safely acknowledge it.
This may cause one additional execution. Main keeps its existing transactional
collector-purge path; the LTS change retains the legacy purge contract.

Index type/value changes are decided independently for each source. Configured
numeric and alphabetic policies apply to actual cached values, including a
single candidate and the OID-index shortcut. XML source-index fallback normalizes
the parsed definition. Shared graphs retain the prior last-supplied-source rule.

Local verification on 2026-11-08 used PHP 8.1.34, MySQL 8.4.13 and MariaDB
10.11.19 in disposable databases. All 58 registered regression cases passed on
each engine. They execute shipped DDL and production database code, with device
acquisition, item construction and unrelated completion effects as explicit test
boundaries. Cases cover failed first/late writes, mark/delete/preserve failures,
silent PDO failures, begin/commit/savepoint refusal, caller rollback, absent
hosts/connections, temporary tables, MyISAM refusal, lock contention/release,
reversed source order, orphan/transient recovery, unchanged-index retry and queue
arrivals/replacements/deadlines. The remote-envelope and publication tests add
14 and 17 cases respectively.

Run from `tests/` with the branch's locked test dependencies and a disposable
`cacti_boost_contract` database. Supply its loopback port through `BOOST_DB_PORT`:

```sh
BOOST_DB_PORT=<port> mise exec php@8.1.34 -- php vendor/bin/pest --configuration=phpunit-boost-mariadb.xml integration/ReindexRecoveryMariaDbTest.php --colors=never
```

Supplemental Xdebug measurements use PHP 8.4.25 and 50 native scenarios. Only
executed lines in the named production functions are counted below; extracted
coordinator/transport fixtures do not establish whole-application coverage.

| Function | Executed/executable lines | Coverage |
|---|---|---|
| run_data_query | 4/4 | 100.0% |
| data_query_update_host_cache_from_buffer | 33/33 | 100.0% |
| data_query_reindex_local | 88/103 | 85.4% |
| data_query_remap_indexes | 22/24 | 91.7% |
| data_query_statement | 4/4 | 100.0% |
| data_query_serialized | 28/29 | 96.6% |
| data_query_transaction | 40/44 | 90.9% |

The 1,001-source graph fixture uses six SQL statements (three reads and three
writes), compared with 3,003 in the original function. Three runs against the same
local MySQL instance had median elapsed times of 378.75 ms before
and 10.94 ms after. Stored graph indexes match in both versions.
This measures graph remapping only, not a complete poll cycle or device latency.
The regression asserts the six-query budget and checks all 1,001 persisted rows.

Before publication, the final diff and direct callers were reviewed separately:
web reindex, the remote agent, CLI reindex and queued reindex retain their entry
contracts; no authorization route or request parser changes. The source mutation
phase is local, remote publication is checked separately, and graph batching
preserves supplied ordering. Sink inventories were checked without broadening
their normalized entries. Translation catalogs follow the changed messages.

Stop reindex workers while deploying the code to the primary and collectors;
older writers do not participate in the new advisory lock. No schema migration,
dependency upgrade or configuration change is required. Resolve the logged
backend failure and let retained commands retry, or run
`php cli/poller_reindex_hosts.php --id=<host> --qid=<query>` after recovery.
Application rollback restores the earlier code; it does not reverse already
committed cache/index changes. Reindex again after any rollback. Back up normal
application data under the existing operational procedure.

Full installed browser/collector workflows, real SNMP devices, native Windows,
network partitions and production capacity remain unverified. Hosted checks and
independent review remain release gates.

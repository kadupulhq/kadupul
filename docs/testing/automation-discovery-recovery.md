# Automation discovery recovery on LTS

This change addresses correctness and reliability defects in `lts/1.2` discovery.
The reviewed base is `9376f1a1c00d610217ebcccc3f5f618d3a87aaef`. It introduces
the reachable 1.2.33 schema upgrade; it does not declare a released version.
Related reports: #207 (overlapping queues), #1032 (address ranges and `.255`
hosts), and #72 (retaining ownership when termination fails).

## Invariants and corrections

| Failure in the previous implementation | Required behavior and correction |
|---|---|
| A claimed address was fetched by PID/thread without its network. | Every claim, read, hostname update and acknowledgement includes the network and random run identity. |
| One departing worker deleted its siblings' queue. | Worker cleanup releases only its own unfinished claims and process row. |
| Separate check/delete/register operations admitted competing masters. | A database connection owns the network advisory lock before preparation. A successor also checks all child-slot locks before replacing an old queue. |
| A dead worker could leave the master polling forever; `run_limit` was unused. | Retained process handles expose child exit status. The master checks its deadline and cancels its own remaining children. |
| Failed database reads were treated as zero running workers and successful completion. | Checked queries fail the run. The CLI's nested legacy calls fail without reconnecting past the ownership lock. |
| Global IP identity and plain INSERT lost overlapping work. | The queue uses `(network_id, ip_address)`, deduplicates within a network, inserts in batches of 1,000, and commits preparation together with registration. |
| Wildcard counts disagreed with enumeration and `/31` had no usable endpoints. | One parser supplies cardinality and enumeration. Constrained wildcards enumerate their actual Cartesian set; `/31` has two endpoints. The ping consumer accepts valid `.255` hosts. |
| Monthly due instants were skipped and initial dates were ignored. | Due checks retain the unconsumed occurrence and honor the full start date. Daily and weekly schedules use calendar arithmetic; monthly dates reject rollover into another month. |
| Checking or launching consumed an occurrence before work succeeded. | Only successful scheduled completion advances `next_start`, in the same transaction as final status and queue cleanup. A concurrent administrator edit is preserved. |

Discovery retains its existing global device inventory and host lookup semantics.
Queue isolation does not introduce separate host identities for overlapping networks.
Plugin/device/graph effects are not one transaction with discovery completion. A retry
re-primes the network and can repeat already completed external effects; this is an
at-least-once retry contract, not exactly-once discovery.

## Upgrade, operation and recovery

1. Back up the database and configuration. Stop scheduled and manual discovery on
   the primary and every remote collector; wait for existing discovery children
   to exit before changing the schema or code.
2. Deploy the code and run the normal installer upgrade to 1.2.33. The upgrade
   retains existing queue rows, adds run/connection identities, changes the queue
   primary key, and requires InnoDB for the queue, process and network tables.
   Failed schema validation leaves the stored version unchanged. Correct the
   database error and rerun the installer.
3. Upgrade all collectors before enabling discovery. Mixed old/new discovery
   workers are unsupported: old workers do not follow the ownership protocol.
4. Run a small manual discovery and check its status before restoring schedules.
   Manual/forced discovery does not consume the next scheduled occurrence.

After a child exit, cancellation, launch failure, database error or timeout, the run
reports failure and leaves its due time and queued work available for retry. The
next admitted master replaces the old queue transactionally. A live predecessor
child prevents replacement, even after its parent exits. Resolve that child on its
own collector before retrying; do not delete process rows to defeat ownership.
The list page never deletes process records, and manual discovery checks the live
lock rather than stale process rows.

Configuration is limited to 1–50 threads and 1,048,576 submitted addresses per
network, counting duplicates toward the preparation budget. Invalid or larger
ranges are rejected before launching children. A legacy zero run limit uses
1,200 seconds. The configured limit is checked around queue batches and worker
supervision; it cannot interrupt a blocking database call or an uninterruptible OS
process. Normal shutdown attempts graceful termination for five seconds before
requesting forced termination. A child that remains alive retains its lock.

Rollback requires stopping discovery everywhere first. Restoring old application
code does not undo discovered devices, schedules or the new schema. Restore the
pre-upgrade database backup with the old application when a schema rollback is
required; do not blindly recreate the old globally unique IP key after overlapping
networks have queued the same address.

## Verification and review boundaries

PHP 8.1.34 and disposable MariaDB 10.11.19 were used locally. No real device or
production database was contacted.

- 173 focused cases pass in 18 isolated test files: address sets and scanner
  handoff, calendar boundaries, existing automation rules, process ownership,
  CLI listing failures and adjacent database contracts. Restoring only the old
  `.255` guard produces three failures and two passing controls.
- 20 native integration cases pass. Real PDO connections, shipped table DDL,
  advisory locks, transactions, triggers and child PHP processes exercise competing
  owners, old-child exclusion, overlapping queues, cancellation, deadlines, failed
  launch, failed reads, failed completion/schedule writes, a rejected second insert
  batch, caller-owned transactions, manual/forced runs and concurrent schedule edits.
  Each retained-work failure is followed by a successful retry.
- The actual version-gated `Installer::upgradeDatabase()` runs with CLI and web
  configuration modes, successful upgrades and injected DDL failures. Failed
  upgrades preserve 1.2.32, retries reach 1.2.33, and repeated upgrades retain rows.
  Upgraded columns and indexes are compared with newly imported shipped DDL.
- PHP 8.1 syntax, whitespace, actionlint and the reviewed sink/hotspot inventories
  pass. The one new process sink uses an absolute configured executable, argument
  arrays, a fixed worker script, validated numeric identifiers and a generated run
  token. Only retained child process handles are signalled.
- The registered database suite also completes with 79 passed (including these
  20 cases) and 13 pre-existing DSN-dependent cases skipped. Its first local run
  rejected the automation-only database name in seven existing fixtures; rerunning
  with the suite's allowed disposable database name resolves those setup failures.
- Native cases are registered in `phpunit-boost-mariadb.xml` and its existing
  MariaDB/MySQL CI matrix. Unit cases are discovered by the existing isolated
  whole-unit runner. Hosted checks must pass on the published head before merge.

Native child fixtures execute the production ownership/claim/recovery helpers;
DNS, ping, SNMP, plugin and notification I/O are explicit fixture boundaries.
The scanner handoff test executes `discoverDevices()` with a non-network ping
adapter. Installer bootstrap, logging and settings-cache boundaries are fixtures;
these are not full installed CLI or browser installation tests. Native Windows,
real collector propagation, installed UI interactions and capacity at the maximum
address budget remain unverified. No benchmark or percentage-coverage claim is made.

A separate final review traced the CLI scheduler, remote connection switch,
network master, child claims/acknowledgements, manual/cancel actions and installer
version gate. The previous cancellation source assertion expected deletion and a
success report after cancellation; native failure/retry coverage replaces that
incorrect expectation. Denied child termination and live predecessor exclusion
remain covered.

## Supported-branch status

- **LTS:** implementation and focused local verification complete; current-head
  CI, independent review, coordinated rollout and release remain required.
- **Main:** the inspected `56d8c208606dd085dfd57225c0d956fc14987c4b` still contains
  the legacy queue, cancellation and scheduling paths. Equivalent corrections are
  pending in the migration branch. Its stored version is already 1.2.38, so this
  1.2.33 upgrade must not be copied there without a newer reachable migration.
  Keep #207 and #1032 open until both supported branches are handled.

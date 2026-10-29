<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Boost load and equivalence test

This harness measures how Boost flushes queued poller samples into RRD files
at 10,000, 50,000 and 100,000 data sources, in forked-children mode and in
Messenger queue mode, and checks that both modes write identical RRD files.
It also injects four faults into queue mode and checks that nothing is lost
or written twice. It runs on two DigitalOcean droplets, or on two local
containers for a smaller dry run.

## What a run does

For each scale, `remote/seed.sh` builds a fresh installation and snapshots it:

1. It loads `cacti.sql`, runs `cli/install_cacti.php` with the Local Linux
   Machine and Net-SNMP Device packages, and creates the 64-bit interface
   traffic graph with `cli/add_device.php`, `cli/add_data_query.php` and
   `cli/add_graphs.php` against an SNMP agent on localhost.
2. Creating 100,000 sources through the CLI would take hours, so `seed.php`
   copies the rows those commands wrote (`data_local`, `data_template_data`,
   `data_template_rrd`, `data_input_data`, `graph_local`,
   `graph_templates_graph`, `graph_templates_item`, `poller_item`) with
   `INSERT ... SELECT`, changing only identities, paths, and, for two kinds,
   the data source type. Boost resolves paths and templates from exactly these
   tables. The mix per 1,000 sources is 400 GAUGE (Unix - Processes), 250
   COUNTER (Interface - Traffic, 64-bit), 100 DERIVE and 100 ABSOLUTE (the
   stock templates with `data_source_type_id` changed on the copy), 100
   multi-field GAUGE (Unix - Load Average, one poller item with three
   fields) and 50 64-bit COUNTERs that start within 1.6e9 of 2^64 and wrap
   during the test.
3. `seed.php rrds` creates every RRD file with the product's
   `rrdtool_function_create()` and writes one sample at t0 into each, as a
   previous poll would have.
4. `generate.php` queues six 5-minute intervals (t0+300 to t0+1800) in
   `poller_output_boost` with the poller's own upsert. One sample in 100 is
   written twice, half of those with a different first value; one source in
   100 also gets a stale row at t0 that `--skip-past-updates` must drop;
   insert order is shuffled; one field in 200 is `U`. Values are a pure
   function of the seed, so a scale can be rebuilt exactly.
5. The database is dumped with `mariadb-dump --triggers` and the RRD directory
   is tarred. Every cell at that scale starts from this snapshot.

Each cell (`remote/cell.sh`) restores the snapshot, drops the page cache, sets
the mode, starts the monitors and runs `poller_boost.php --force`:

| Cell | Mode | `boost_parallel` | Consumers |
| --- | --- | --- | --- |
| A | children | 8 | none |
| B | queue | 8 | 8 |
| C | queue | 16 | 8 |
| A-main (10k only) | children, `origin/main` code | 8 | none |

Consumers run `bin/console messenger:consume boost --time-limit=3600
--memory-limit=256M` under a loop that restarts them five seconds after they
exit, as `service/kadupul-boost-worker.service` does with `Restart=always`.

After A, B, C and A-main, the cell hashes `rrdtool dump` of 500 RRD files
spread across the scale plus every multi-field and every near-wrap source.
B, C and A-main must match A exactly.

At 50k the queue mode gets four faults. Each is followed by a recovery cell on
the state the fault left, with eight consumers, whose dumps must match A:

1. No consumer running. The run must fail, keep the archive, and log
   `run "bin/console messenger:consume boost"`.
2. SIGKILL of a consumer while it runs a partition.
3. SIGTERM of the master while it waits for partitions.
4. A MariaDB restart while partitions run. The same restart is also applied
   to children mode for comparison.

The master waits three times `boost_rrd_update_max_runtime` (one hour at the
default of 1200 s) for queue partitions. The fault cells set it to twice the
50k children wall time, at least 60 s, so a stalled run ends in minutes.

## What is recorded

For every cell, under `results/<run id>/`:

- `cells/<cell>/`: the cell's parameters and wall time, vmstat and sar on the
  application host at 1 s, every Boost process's RSS and peak RSS (VmHWM) at
  0.5 s, the final Boost settings, rows left in `poller_output_boost` and the
  archive tables, queue rows left, the `processes` table, processes still
  alive after the partitions settle, `cacti.log`, consumer logs, the dump
  hashes, and the RRD files whose last update is not the final queued step
  (every file is checked, not only the comparison set).
- `db/<cell>/`: `SHOW GLOBAL STATUS` every 5 s (`status.csv`, and
  `status-deltas.csv` after collection), row counts and sizes of the Boost and
  queue tables every 5 s, vmstat on the database host, the
  performance_schema statement digests (reset before the cell), the slow log
  at `long_query_time = 0.1` with a `pt-query-digest` report,
  `SHOW ENGINE INNODB STATUS` at the end and on any deadlock, and the
  server variables that matter.
- `cells.csv` and `summary.md`, written by `collect.php`.

In the summary, wall time is the master process from start to exit. It
includes a fixed 7 s sleep the master takes after rotating the table, which
dominates small runs. Rows per second is Boost's own count of queued rows
written, over wall time. Peak RSS is per process, not summed. The queue path
share is the latency of statements on `kadupul_messenger_messages` plus the
run-scoped status count on `poller_output_boost_processes`, as a share of all
statement latency in the installation schema.

Nothing under `results/` is committed. `run.sh` replaces any droplet address
that appears in the fetched files with a placeholder.

## Running it on DigitalOcean

Requirements on your machine: `doctl` authenticated, `jq`, `ssh`, `git`,
`composer` with PHP 8.4 (through `mise` if present), and `openssl`.

```sh
cd tests/load/boost
export STATE_ROOT=/somewhere/private   # SSH key, passwords, droplet IDs
run_id="$(./provision.sh)"
./run.sh "$run_id"                       # tears everything down when it exits
```

`LARGE_SCALES` runs cycle mode, for example
`SCALES= LARGE_SCALES=1000000 LARGE_CELLS_1000000="A16:children:16:0 B32:queue:32:32"`,
and `RESUME=1` continues on droplets that already ran without redeploying.
`CELL_FILTER` limits a run to matching cells, for example
`SCALES=50000 BASELINE_SCALE=0 CELL_FILTER='^50000-(A|F4.*)$'` for the
database restart cells alone.

`provision.sh` creates a project named `kadupul-boost-loadtest`, a VPC, a
`c-8` application droplet and an `s-8vcpu-16gb` database droplet (Ubuntu
24.04, region `sfo3`), an SSH key, and a cloud firewall. Every droplet is
tagged `kadupul-boost-loadtest` and with the run id. The firewall admits SSH
only from your current public address, and MariaDB (3306) and the two
exporters (9100, 9104) only from the application droplet's private address.
MariaDB, the exporters and Grafana listen on private or loopback addresses
only. No cloud or GitHub credentials go to the droplets; code arrives as a
`git archive` with its Composer dependencies, copied over SSH.

Before it creates anything billable, `provision.sh` starts a local watchdog
that runs `teardown.sh` after six hours (`WATCHDOG_SECONDS`). `run.sh` tears
down when it exits for any reason, including Ctrl-C, unless `KEEP=1`.
`teardown.sh <run id>` deletes droplets by the run-id tag, deletes the
firewall, SSH key, VPC and project by their recorded IDs after checking each
one's name, and prints what remains.

While a run is going, `run.sh` prints an SSH tunnel command. With the tunnel
open, Grafana is at <http://localhost:3000> (user `admin`, password in
`$STATE_ROOT/<run id>/grafana-admin-password`, generated per run). It shows
the MySQL overview from the mysqld_exporter project, Node Exporter Full, and
an InnoDB and Boost tables dashboard from this directory. The dashboards are
the committed files under `grafana/`; nothing is fetched at run time.

### Cost

At list prices the two droplets cost about $0.39 an hour ($0.25 for `c-8`,
$0.14 for `s-8vcpu-16gb`). Provisioning, including Prometheus, Grafana and the
exporters, took 3 to 13 minutes, depending mostly on package mirror speed.
The full matrix took about 63 minutes of droplet time, so a run costs about
$0.40 to $0.80 depending on whether partial hours are billed in full. The
watchdog caps a forgotten run at six hours, or about $2.40. The
million-source pair costs $4.31 an hour; set `WATCHDOG_SECONDS`, `APP_SIZE`,
`DB_SIZE`, `DB_BUFFER_POOL` and `DB_LOG_FILE_SIZE` for it.

## Results from 2026-10-28

Run `kbl-202610280406-947a80`: PR #1136 at `daabab80ab`, baseline
`origin/main` at `ecaa940d41`, `c-8` application host and `s-8vcpu-16gb`
database host, PHP 8.4.27, RRDtool 1.7.2, MariaDB 10.11.14. Timings come from
one run each, and the same cell on a second pair of droplets took up to 48 %
longer, so compare modes within a scale rather than across runs.

| Scale | Cell | Wall s | Rows/s | Peak RSS MiB master / child / partition / consumer | App CPU avg % | App iowait avg % | DB QPS avg (peak) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 10k | A children, 8 | 13.4 | 6,235 | 44 / 49 / - / - | 12 | 5.7 | 1,928 (4,267) |
| 10k | A on `origin/main` | 12.8 | 6,542 | 44 / 50 / - / - | 13 | 6.5 | 1,924 (4,198) |
| 10k | B queue, 8 / 8 | 14.5 | 5,770 | 48 / 50 / 43 / 44 | 14 | 5.0 | 1,659 (5,509) |
| 10k | C queue, 16 / 8 | 13.5 | 6,203 | 48 / 47 / 43 / 44 | 17 | 5.1 | 1,968 (6,001) |
| 50k | A children, 8 | 34.4 | 12,227 | 44 / 58 / - / - | 18 | 12.4 | 4,350 (8,046) |
| 50k | B queue, 8 / 8 | 36.5 | 11,506 | 48 / 59 / 43 / 44 | 20 | 10.8 | 3,449 (7,278) |
| 50k | C queue, 16 / 8 | 35.7 | 11,768 | 48 / 58 / 43 / 44 | 20 | 11.4 | 3,534 (7,951) |
| 100k | A children, 8 | 58.6 | 14,360 | 44 / 65 / - / - | 21 | 15.2 | 5,095 (7,535) |
| 100k | B queue, 8 / 8 | 59.9 | 14,039 | 48 / 64 / 42 / 44 | 23 | 13.7 | 4,854 (7,953) |
| 100k | C queue, 16 / 8 | 60.8 | 13,850 | 48 / 59 / 43 / 44 | 23 | 13.7 | 4,682 (8,058) |

Every cell above ended `complete` with no rows, archive tables, Boost
processes or `processes` rows left and `boost_active_run` empty. No cell had a
deadlock; the most row lock waits in any cell was 6. Queue mode's own
statements (Doctrine's transport plus the run-scoped status count) took 0.06 %
to 0.6 % of database statement time. B, C and A on `origin/main` produced
`rrdtool dump` output identical to A at every scale (1,894, 7,999 and 15,638
files).

Faults at 50k, queue mode with eight supervised consumers:

- No consumer: the run failed after the shortened deadline, kept all 419,984
  rows, and logged `no Boost queue worker reported; run "bin/console
  messenger:consume boost"`. The recovery was identical to A.
- SIGKILL of a consumer mid-partition: the run still completed, because the
  orphaned partition finished and reported. The killed consumer's message
  stayed in `kadupul_messenger_messages` as delivered; it is redelivered only
  after `redeliver_timeout` (15,600 s). Identical to A.
- SIGTERM of the master: status `terminated`, but the master exits 0. The
  running partitions finished and emptied the archive, which nobody dropped. A
  following cycle with nothing new queued fails with `failed - preparation`
  and adds another empty archive; the next cycle with samples drops them all.
  Identical to A.
- MariaDB restart mid-run (repeated in run `kbl-202610280513-0afc7d` with a
  check of every RRD file): the master reported `complete`, yet about half of
  the RRD files (25,004 of 50,000) had not been written and 210,300 rows were
  requeued for the next cycle. After that cycle, 4 RRD files had none of their
  six samples. The same restart in children mode gave 25,002 and 2. In the
  first run one of the lost files was in the comparison set, so that
  comparison differed.

## Results at one million data sources

Run `kbl-202610280605-429774` used an `so1_5-32vcpu-256gb-intel`
application host (32 vCPU, 256 GB, 7.2 TB local NVMe holding every RRD file)
and an `m-16vcpu-128gb` database host (16 vCPU, 128 GB,
`innodb_buffer_pool_size = 96G`, `innodb_log_file_size = 8G`), at $4.31 an
hour together. Each cell is one Boost cycle in cycle mode: the default
60-minute `boost_rrd_update_interval`, so 12 samples for each of the 1,000,000
sources, 16,816,182 rows, flushed by one `poller_boost.php --force`.
Copying 151 GB of RRD files per cell is out of reach, so every cell queues
the next cycle on the same RRD set. The 700 comparison sources (500 spread,
100 multi-field, 100 near-wrap) are restored from a stash before each cell and
always replay cycle 1, so their dumps still compare exactly.

| Mode | Parallel / consumers | Cycle s | Share of the 60 min interval | Rows/s | RRD files/s | App CPU avg % | App iowait avg % | DB CPU avg % | DB QPS | Row lock waits | Archive DELETE share | Template lookup share |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| children | 8 | 470 | 13 % | 35,801 | 2,129 | 4 | 8.6 | 20 | 6,619 | 0 | 50 % | 31 % |
| children | 16 | 338 | 9 % | 49,789 | 2,961 | 6 | 13.6 | 42 | 9,242 | 0 | 60 % | 24 % |
| children | 64 | 311 | 9 % | 54,063 | 3,215 | 9 | 20.9 | 68 | 10,020 | 0 | 70 % | 6 % |
| queue | 8 / 8 | 568 | 16 % | 29,610 | 1,761 | 4 | 11.1 | 17 | 5,480 | 0 | 51 % | 32 % |
| queue | 16 / 16 | 404 | 11 % | 41,621 | 2,475 | 6 | 18.3 | 33 | 7,676 | 0 | 60 % | 26 % |
| queue | 32 / 32 | 260 | 7 % | 64,792 | 3,853 | 10 | 23.1 | 59 | 11,872 | 0 | 70 % | 19 % |
| queue | 64 / 64 | 349 | 10 % | 48,236 | 2,868 | 10 | 30.7 | 57 | 8,898 | 11 | 73 % | 7 % |

All seven cells left the queue, the archives and the process table empty,
every one of the 1,000,000 RRD files at its expected last update, and the
comparison set identical to the first cell. At equal parallelism queue mode
took 21 % (8) and 20 % (16) longer than children; its 32 / 32 cell was the
fastest overall, and 64 / 64 was slower again, with the first row lock waits.
The children spend their time waiting on the database: the application host
never passed 10 % CPU on average, while 50 % to 73 % of all statement time
went to `boost_delete_samples()`, which deletes 500 sample tuples per
statement with an `OR` chain that compares `output` through
`CAST(CONVERT(...))` (about 40 ms a statement), and most of the rest to the
three per-source template lookups. The queue's own statements stayed under
0.1 % of database time. The archive peaked at 1.1 GiB, and its rotation
(`RENAME`, `CREATE` and `ANALYZE`) took 0.1 s.

10M and 30M were not measured. Seeding 10M took 1 h 51 min for the lean clone
(84,003,465 `graph_templates_item` rows took 5,114 s, 14 times the 1M figure
for 10 times the rows) and was 44 minutes into creating RRD files at about
1M's rate of 3,473 files/s when the operator machine's network egress changed
(a VPN came up), the cloud firewall stopped admitting its SSH, and the run was
torn down rather than widen the firewall. 30M was not attempted: at these
rates its seeding alone is at least 5.5 h of clone (likely more, given the
superlinear growth) plus 2.4 h of RRD creation for 4.5 TB, before the first
cycle. A 30M cycle is 504M rows; at the best rate measured here (64.8k rows/s
at 1M) that would be about 2.2 h, over the 60-minute interval. That figure is
an extrapolation from 1M, not a measurement.

## Running it locally

```sh
cd tests/load/boost
./local-up.sh
SCALES=10000 FAULT_SCALE=10000 BASELINE_SCALE=10000 RRD_WORKERS=4 ./run.sh --docker
./local-up.sh down
```

This runs the same scripts in an `ubuntu:24.04` container prepared by the
droplet's `setup-app.sh` and a `mariadb:10.11` container with a scaled-down
`my.cnf`, without Prometheus and Grafana. Container numbers are only useful to
check the harness.

## Files

| File | Runs on | Purpose |
| --- | --- | --- |
| `provision.sh`, `run.sh`, `teardown.sh`, `lib.sh` | your machine | resources, matrix, cleanup |
| `local-up.sh` | your machine | local dry-run containers |
| `collect.php` | your machine | `summary.md` and `cells.csv` |
| `seed.php`, `values.php`, `generate.php` | app host | data sources, RRD files, samples |
| `remote/setup-app.sh`, `remote/setup-db.sh`, `remote/my.cnf` | both hosts | cloud-init and MariaDB settings |
| `remote/setup-monitoring.sh` | app host | Prometheus, node-exporter, Grafana |
| `remote/deploy.sh`, `remote/seed.sh`, `remote/cell.sh`, `remote/procs.sh` | app host | deploy, seed, one cell, process sampler |
| `remote/db-monitor.sh` | database host | per-cell database recorder |
| `grafana/` | app host | dashboards; see `grafana/README.md` for sources and licences |

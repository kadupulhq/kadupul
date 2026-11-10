<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Failure behaviour and recovery

This page describes what an operator or plugin author sees when the poller,
the installer, package import, the Data Source Troubleshooter, SNMP walks or
plugin table setup refuse to continue, and what to do next. RRD storage has
its own page, [Upgrading RRD storage and the poller queue](upgrading-rrd-storage.md),
which covers tune failures and abandoned temporary files.

## Poller startup

When polling is enabled, `poller.php` checks the selected collector binary
before it records anything for the run (`poller_collector_preflight()` in
`lib/poller.php`):

- with **Poller Type** spine, **Spine Binary File Location** (`path_spine`);
- with cmd.php, **PHP Binary Path** (`path_php_binary`).

Both are under Settings > Paths. The path must name a regular file that the
account running `poller.php` can execute. If it does not, the poller writes
one `POLLER` log line and sends the admin e-mail "Kadupul System Warning",
then exits with status 1:

```text
ERROR: The spine path: /usr/local/spine/bin/spine is invalid.  Poller can not continue!
ERROR: The PHP binary path: /usr/bin/php is invalid.  Poller can not continue!
```

It exits before the run's `poller_lastrun_<id>` and system MIB timestamps are
written, so a failed start is not recorded as a completed run. Before #1360,
a bad spine path was found only at launch, after the run was recorded, and
the poller exited with status 0; a bad PHP binary path was never checked.
Cron jobs and service units that watch the exit status now see the failure.

To recover, correct the path in Settings > Paths, or install or restore the
binary, and check its execute permission for the poller's account. The next
scheduled run starts normally. The later spine check at launch is still
there, for a setting changed between sub-minute runs.

## Installer schema check

An installer run records the installed version only after the database has
every table that `cacti.sql` creates
(`Installer::confirmInstallationVersion()` in `lib/installer.php`). Tables are
compared case-insensitively, and extra tables, such as plugin tables, are
ignored. The check runs for install, upgrade and remote-collector runs. If it
fails, the installer shows its error step with an error under
**Database schema**, for example:

```text
Required core database tables are missing: poller_item, settings
```

It also fails when the selected database's table list cannot be read, or when
`cacti.sql` cannot be read or declares no tables. In each case the version
marker and `install_version` are not written, so the run can be repeated
after the cause is fixed. Before #1397, such a run was recorded as installed.

To recover, compare the database with `cacti.sql`. A table missing after an
upgrade usually means an upgrade step failed earlier; read the installer log
for that step. Either create the missing tables from their `cacti.sql`
definitions or restore the pre-upgrade backup, then run the installer again.
`cli/audit_database.php` audits only tables that exist and does not report
missing ones.

One table was listed in `cacti.sql` from 1.2.19 but never created by an
upgrade: `poller_output_boost_local_data_ids`, which Boost creates on its
first run. The 1.2.38 upgrade step now creates it when it is missing and
leaves an existing table unchanged, so an install that never ran Boost does
not fail this check.

`cli/upgrade_database.php` does not run this check.

## Package import size limit

Every read of a template package (`.xml.gz`) goes through
`import_package_read()` in `lib/import.php`: the web import and its
public-key preview in `package_import.php`, `cli/import_package.php`, and
signature verification. It expands the package in 64 KiB chunks and stops at
32 MiB (33,554,432 bytes) of expanded data, before any metadata, signature or
XML processing. The largest package shipped with Kadupul expands to about
1.4 MB.

A package over the limit is refused and the `IMPORT` log says why:

```text
FATAL: Kadupul Package /path/to/package.xml.gz expands beyond 33554432 bytes and was not read.
```

Missing, unreadable and empty packages are refused the same way, with
`Unable to open` or `Unable to read`. To import a larger package you trust,
raise the limit in `include/config.php`:

```php
define('IMPORT_PACKAGE_MAX_EXPANDED_BYTES', 67108864);
```

`import_package()` can remove PHP's memory limit before it reads the package
(its `$limitex` argument), so this constant, not `memory_limit`, bounds how much a package can expand in
memory during an import.

## Data Source Troubleshooter actions

In the Data Source Troubleshooter (`data_debug.php`), **Apply Suggested
Fixes** and **Rerun Analysis** submit a POST with the page's CSRF token. The
server enforces this for the `run_repair` and `run_debug` actions:

| Request | Response |
| --- | --- |
| GET | 405, with `Allow: POST` |
| POST without a valid CSRF token | 403 |

Both responses are sent before any repair or rerun work starts. The read-only
refresh link is still a GET. A bookmark or script that requested
`data_debug.php?action=run_repair` or `action=run_debug` by GET stopped
working with #1365; use the buttons on the page instead.

## SNMP walks

When Kadupul walks a device with the net-snmp `snmpwalk` or `snmpbulkwalk`
binary, `cacti_snmp_walk()` in `lib/snmp.php` judges the walk by the
command's exit code. The binary is used whenever `path_snmpbulkwalk` names an
existing file, for SNMPv3, for hex output and when PHP has no SNMP
extension. The PHP extension's walk is unchanged.

A walk that exits with a non-zero status returns no rows, even if it printed
some before failing. Before #1391, a walk cut short by a timeout returned its
partial rows as if complete, and a device value containing `Timeout` or
`(tooBig)`, such as an interface alias, produced a false warning. Data
queries, reindex checks, discovery and plugins that call
`cacti_snmp_walk()` now see an empty result for a failed walk.

Two `SNMP` log lines describe the failure. Both are written at the HIGH
level, so they appear only when **Generic Log Level** (Settings > General) is
HIGH or above:

```text
WARNING: SNMP Error:'Exit Code 2', Device:'192.0.2.1', OID:'.1.3.6.1.2.1.2.2.1'
WARNING: SNMP Error:'Missing credentials', Device:'192.0.2.1', OID:'.1.3.6.1.2.1.2.2.1'
```

`Missing credentials` means the device's SNMP version, or its SNMPv3
protocol, gave no credentials, so no command was run. Check the device's SNMP
settings.

net-snmp also exits with status 1 after printing rows when an agent returns
OIDs out of order. Such a device now gives no rows, and the `Exit Code 1`
line adds: "if the device returns OIDs out of order, enable 'Disable
increasing OID Check' under Settings > Poller." That setting
(`oid_increasing_check_disable`) passes `-Cc` to the walk.

## Plugin table definitions

`api_plugin_db_table_create()` calls `db_update_table($table, $data, false)`
on every plugin install and upgrade for a table the plugin created, so a
plugin's table definition is reconciled with the live table each time.
Since #1395, `db_update_table()` in `lib/database.php` treats the definition
more precisely:

- Indexes under `unique_keys` are created as `UNIQUE` when missing and kept
  when present. Before, they were dropped as undeclared whenever the
  definition also had `keys`, and never created.
- A live unique index listed under `keys`, as `cli/sqltable_to_php.php` writes
  every secondary index, keeps its uniqueness.
- Index columns are compared in order and with prefix lengths: a declared
  `name(10)` matches a live `name` index with a 10-character prefix, and a
  live `(b,a)` index declared as `(a,b)` is rebuilt.
- Integer types are compared without their display width (unless `ZEROFILL`),
  so `int(11)` and `int` no longer cause an `ALTER ... CHANGE` on every call.
- A primary key or index column list may be a string (`'id'`, or the legacy
  `` 'col1`,`col2' `` form) as well as an array.
- A changed primary key or index is rebuilt in one `ALTER` statement. If the
  server rejects it, for example a `UNIQUE` index over duplicate rows, the old
  key stays in place and the function returns `false` with the server error
  logged.

The first setup after upgrading may therefore rebuild indexes whose declared
column order or uniqueness differs from the live table. On a large table,
that `ALTER` can take time and lock the table. Undeclared indexes are still
dropped only when the definition has `keys`, and columns are not removed,
because the plugin path passes `false` for `$removecolumns`. Column defaults
are still not compared.

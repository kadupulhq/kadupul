# Command-line tools: roadmap

This tracks the move of the `cli/` scripts to Symfony Console commands. The
design, conventions and write-command rules are in
[cli-symfony-console.md](cli-symfony-console.md). Each group is its own PR.
Each migrated script becomes a forwarding shim that keeps its flags and output,
and parity scenarios compare it with a frozen copy of the original.

`cli/index.php` and `cli/.htaccess` stay as they are; they only block web
access to the directory.

| Group | Scripts | Status |
| --- | --- | --- |
| Foundation | `analyze_database.php` | Merged in #414 |
| Schema tools | `convert_tables.php`, `fix_mediumint.php` | Merged in #434 |
| Audit | `audit_database.php` | #451 |
| Repair | `repair_database.php` | In progress, after #451 |
| Users and permissions | `copy_user.php`, `add_perms.php` | Planned |
| Graphs and trees | `add_graphs.php`, `add_graph_template.php`, `add_tree.php`, `remove_graphs.php`, `remove_broken_graphs.php`, `repair_graphs.php`, `poller_graphs_reapply_names.php` | Planned |
| Data sources and queries | `add_datasource.php`, `add_data_query.php`, `reorder_data_query.php`, `poller_data_sources_reapply_names.php`, `rebuild_poller_cache.php`, `poller_output_empty.php`, `poller_replicate.php`, `replay_rejected_samples.php` | Planned |
| Templates, import and automation | `import_template.php`, `import_package.php`, `repair_templates.php`, `input_whitelist.php`, `apply_automation_rules.php` | Planned |
| RRD tools | `splice_rrd.php`, `float_rrdfiles.php`, `batchgapfix.php`, `removespikes.php`, `structure_rra_paths.php`, `update_heartbeat.php` | Planned |
| Miscellaneous | `plugin_manage.php`, `md5sum.php`, `refresh_csrf.php`, `sqltable_to_php.php` | Planned |
| Install and upgrade | `install_cacti.php`, `upgrade_database.php` | Planned, last: the parity harness installs through them |
| Devices | `add_device.php`, `change_device.php`, `remove_device.php`, `host_update_template.php`, `poller_reindex_hosts.php`, `push_out_hosts.php` | After the open Inventory stack (#288 to #316) merges |

## Audit and repair decisions

A review of `audit_database.php` and `repair_database.php` found that both
originals can change or delete data an operator still needs. The ports keep
each script's legacy output where they can, and change behaviour only where the
original was unsafe. Each change is listed as a known difference in
`docs/symfony-migration.md`.

Audit (#451):

- A repair never narrows a column. A column wider than the baseline, such as
  one widened by `kadupul:database:widen-id-columns`, is reported as widened
  locally.
- An index the baseline does not list is reported, not dropped, unless
  `--drop-unknown-indexes` is given.
- Under `bin/console`, `--repair` only plans unless `--force` is given, and the
  plan shows each table's `SHOW CREATE TABLE` and row count.
- Under `bin/console`, `--report` and `--alters` write nothing, so they need
  the Utilities realm instead of Installation/Upgrades.

Repair:

- Under `bin/console` a run is a dry run unless `--apply` is given.
  `--force` keeps its old meaning: it adds the checks that delete rows.
- Incomplete data sources are removed only under `--force`, and through the
  data source API, so nothing is left behind.
- A data template with no data input method (`data_input_id = 0`) is valid and
  is left alone.
- Graph items with a missing GPRINT preset get the default preset instead of
  being deleted.
- `--force` is refused on a remote collector, which holds only part of the
  data.
- `REPAIR TABLE` runs only on MyISAM and Aria tables.
- `--check=` runs a single check.

## Later work

- A CI check that a fresh install and an upgrade both audit clean (#452).
- A baseline generated in CI instead of edited by hand (#453).
- A comparison that reports defaults, collation, prefix indexes and missing
  tables (#454).
- An allow-list for local and plugin schema extras (#455).
- Removing the unused audit staging tables (#456).
- Deprecating the audit's `--upgrade` (#457).
- One maintenance command in place of audit and repair (#458).
- Shim removal, in a later major release, once the parity suite has run clean
  for a full release cycle.

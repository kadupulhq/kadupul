<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Dashboards

`remote/setup-monitoring.sh` provisions these files into Grafana unchanged.

| File | Source | Licence |
| --- | --- | --- |
| `mysql-overview.json` | `mysqld-mixin/dashboards/mysql-overview.json` from prometheus/mysqld_exporter at tag `v0.15.0`, which matches the `prometheus-mysqld-exporter` 0.15.0 package in Ubuntu 24.04 | Apache-2.0, see `LICENSE-Apache-2.0.txt` |
| `node-exporter-full.json` | `prometheus/node-exporter-full.json` from rfmoz/grafana-dashboards at commit `99a25cc154c21c4fcbae1aa24352bf1b6764d847` | Apache-2.0, see `LICENSE-Apache-2.0.txt` |
| `innodb-boost.json` | written for this harness | GPL-3.0-or-later |

The InnoDB and Boost tables dashboard reads the mysqld_exporter metrics and
two metrics the run recorder publishes through node-exporter's textfile
collector while a cell runs: `kbl_boost_table_rows` and
`kbl_boost_table_bytes`, labelled by table.

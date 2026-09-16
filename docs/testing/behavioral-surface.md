# Kadupul behavioral surface

Baseline revision: `6ce3572dab3264be563b765f25dcadd8cc046252`. Version marker: 1.2.31. This is a modified Kadupul checkout, not verified upstream Cacti.

Inventory was collected before adding scenarios. A machine-readable companion is produced by `make inventory` and is not committed, because it is 35,000 lines of generated output that no one reviews by hand. It lists source locations, signatures, literal plugin hook call sites, AJAX candidates, database call sites and SNMP/RRD boundaries. This lexical inventory is a discovery aid, not runtime coverage; dynamic hook names and multiline expressions need manual review.

| Boundary | Architecture and contract |
|---|---|
| HTTP/UI | Root PHP entrypoints; include/global.php bootstrap, include/auth.php realm gates, csrf middleware, PHP sessions; status, redirect, form and message contracts |
| API/AJAX | PHP lib/api_*.php APIs and page action dispatch; no assumption of a generic REST device API. utilities.php ajax_hosts is a representative JSON endpoint |
| Database | cacti.sql; lib/database.php fetch/execute/save helpers, MariaDB, global connection and cache state |
| Devices/templates | host.php, cli/add_device.php, change_device.php, remove_device.php; lib/api_device.php, template.php, api_data_source.php, api_graph.php |
| Poller | poller.php coordinator; cmd.php worker; script_server.php; cactid.php; poller_commands, boost, automation, recovery, maintenance, dsstats and realtime scripts |
| SNMP | lib/snmp.php PHP SNMP extension and net-snmp executable boundaries; timeouts and typed result conversion |
| RRD | lib/rrd.php external executable and pipe interface; graph_image.php, graph_json.php, graph_xport.php; create/update/graph/fetch |
| Plugins | lib/plugins.php registration, lifecycle, ordered callback dispatch; plugins.php and cli/plugin_manage.php |
| Boost | poller_boost.php and lib/boost.php, poller_output_boost queue, bulk loading, retry/error semantics |
| Automation | automation_*.php, lib/api_automation*.php, poller_automation.php |
| Threshold/events | Plugin-dependent; do not equate core snmpagent events with a thold installation. No threshold plugin is seeded by this harness |
| Install/upgrade | cacti.sql, cli/install_cacti.php, cli/upgrade_database.php, install/upgrades; schema import alone is not installer validation |

## Entrypoints

- `about.php`
- `aggregate_graphs.php`
- `aggregate_templates.php`
- `auth_changepassword.php`
- `auth_login.php`
- `auth_profile.php`
- `automation_devices.php`
- `automation_graph_rules.php`
- `automation_networks.php`
- `automation_snmp.php`
- `automation_templates.php`
- `automation_tree_rules.php`
- `cactid.php`
- `cdef.php`
- `clog.php`
- `clog_user.php`
- `cmd.php`
- `cmd_realtime.php`
- `color.php`
- `color_templates.php`
- `color_templates_items.php`
- `csp_report.php`
- `data_debug.php`
- `data_input.php`
- `data_queries.php`
- `data_source_profiles.php`
- `data_sources.php`
- `data_templates.php`
- `gprint_presets.php`
- `graph.php`
- `graph_image.php`
- `graph_json.php`
- `graph_realtime.php`
- `graph_templates.php`
- `graph_templates_inputs.php`
- `graph_templates_items.php`
- `graph_view.php`
- `graph_xport.php`
- `graphs.php`
- `graphs_items.php`
- `graphs_new.php`
- `help.php`
- `host.php`
- `host_templates.php`
- `index.php`
- `link.php`
- `links.php`
- `logout.php`
- `managers.php`
- `package_import.php`
- `permission_denied.php`
- `plugins.php`
- `poller.php`
- `poller_automation.php`
- `poller_boost.php`
- `poller_commands.php`
- `poller_dsstats.php`
- `poller_maintenance.php`
- `poller_realtime.php`
- `poller_recovery.php`
- `poller_reports.php`
- `poller_rrdcheck.php`
- `poller_spikekill.php`
- `pollers.php`
- `remote_agent.php`
- `reports_admin.php`
- `reports_user.php`
- `rrdcheck.php`
- `rrdcleaner.php`
- `script_server.php`
- `service_check.php`
- `settings.php`
- `sites.php`
- `snmpagent_mibcache.php`
- `snmpagent_mibcachechild.php`
- `snmpagent_persist.php`
- `spikekill.php`
- `templates_export.php`
- `templates_import.php`
- `tree.php`
- `user_admin.php`
- `user_domains.php`
- `user_group_admin.php`
- `utilities.php`
- `vdef.php`

## CLI scripts

- `cli/add_data_query.php`
- `cli/add_datasource.php`
- `cli/add_device.php`
- `cli/add_graph_template.php`
- `cli/add_graphs.php`
- `cli/add_perms.php`
- `cli/add_tree.php`
- `cli/analyze_database.php`
- `cli/apply_automation_rules.php`
- `cli/audit_database.php`
- `cli/batchgapfix.php`
- `cli/change_device.php`
- `cli/convert_tables.php`
- `cli/copy_user.php`
- `cli/fix_mediumint.php`
- `cli/float_rrdfiles.php`
- `cli/host_update_template.php`
- `cli/import_package.php`
- `cli/import_template.php`
- `cli/index.php`
- `cli/input_whitelist.php`
- `cli/install_cacti.php`
- `cli/md5sum.php`
- `cli/plugin_manage.php`
- `cli/poller_data_sources_reapply_names.php`
- `cli/poller_graphs_reapply_names.php`
- `cli/poller_output_empty.php`
- `cli/poller_reindex_hosts.php`
- `cli/poller_replicate.php`
- `cli/push_out_hosts.php`
- `cli/rebuild_poller_cache.php`
- `cli/refresh_csrf.php`
- `cli/remove_broken_graphs.php`
- `cli/remove_device.php`
- `cli/remove_graphs.php`
- `cli/removespikes.php`
- `cli/reorder_data_query.php`
- `cli/repair_database.php`
- `cli/repair_graphs.php`
- `cli/repair_templates.php`
- `cli/splice_rrd.php`
- `cli/sqltable_to_php.php`
- `cli/structure_rra_paths.php`
- `cli/update_heartbeat.php`
- `cli/upgrade_database.php`

The fixture disables seeded discovery networks after recording the untouched schema and installer result. Every poller invocation owns a process group and waits for its shells and background descendants to finish (30-second limit after the parent exits); configuration callbacks from discovery workers must not leak into later plugin lifecycle captures. The historical plugin callback golden was refreshed for this fixture change: ten extra configuration callbacks were removed, with lifecycle events and payloads unchanged. A Linux self-test starts a delayed shell and PHP grandchild, verifies completion, and checks that the parent exit status is preserved.

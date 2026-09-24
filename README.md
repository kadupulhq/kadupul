# Kadupul

Network monitoring and graphing. Kadupul polls devices over SNMP and scripts,
stores measurements in RRD files, and renders graphs with RRDtool.
It is an independent fork of [Cacti](https://github.com/Cacti/cacti), without affiliation or endorsement from that project.

This is the `lts/1.2` branch, which tracks the 1.2 series and keeps its
formatting, database schema and plugin API. `main` carries the Symfony
migration and requires a newer PHP.

[Documentation](https://kadupulhq.github.io/website/) · [Issue tracker](https://github.com/kadupulhq/kadupul/issues) · [Discussions](https://github.com/kadupulhq/kadupul/discussions)

## Requirements

- PHP 8.1 or later, built as a CLI binary so data collection can run from cron
- MySQL 8.0 or later, or MariaDB 10.6 or later
- RRDtool 1.3 or later, 1.5 or later recommended
- NET-SNMP 5.5 or later
- A web server with PHP support

Continuous integration runs PHP 8.1 through 8.4, MySQL 8.0, 8.4 and 9.7, and
MariaDB 10.6, 10.11 and 11.8.

### php-snmp

The php-snmp module is optional. It is safe so long as you are not using IPv6
devices, SNMPv3 engine IDs or SNMPv3 contexts. Otherwise consider uninstalling
it, because it will create problems.

### RRDtool

Multiple RRDtool versions are supported. If graphs fail to render, confirm the
configured RRDtool version first.

## Platform requirements

Local Unix RRD storage requires PHP's POSIX extension for account identity and
filesystem trust checks. This applies to ordinary poller writes as well as
exclusive maintenance. Without it, local writers fail closed and retain queued
samples; enable the extension for both CLI and web PHP before running collection.

Spike removal and exclusive RRD maintenance are unavailable on Windows because
Windows ACL validation is not implemented. Ordinary Windows updates use
synchronous RRDtool acknowledgements; Unix capacity evidence does not establish
Windows throughput. Remote RRDtool proxy storage retains its existing path.
See [filesystem requirements](docs/testing/spikekill-safety.md).

## Running from source

Schema changes are committed to `cacti.sql`, used for new installations, and to
the installer upgrade path, used for existing ones. A source checkout does not
change its version number between releases, so the upgrade may not run on its
own. If you see errors about missing tables or columns, force the upgrade with
the database upgrade script or set the version in the database directly.

Upgrading from a pre-1.x release requires the database upgrade script.

Recommended MySQL and MariaDB settings are printed at upgrade time; apply the
ones the installer reports for your instance rather than copying a fixed list.

## Functionality

### Data Sources

Data sources gather data through input methods: devices, hosts, databases,
scripts and so on. Data sources are the direct link to the underlying RRD
files, governing how data is stored in them and how it is retrieved.

### Graphs

Graphs are created by RRDtool from the data source definitions.

### Templating

Graph, data source and RRA templates allow the creation and consumption of
portable definitions, so graphing a new device does not start from scratch.

### Data Collection (The Poller)

Local and remote data collection, with configurable collection intervals
through Data Source Profiles. A profile can be applied to graphs at creation
time or at the data template level.

Remote data collection replicates resources to remote data collectors. A remote
collector that loses connectivity to the main installation stores its collected
data until connectivity returns. It requires only MySQL and HTTP or HTTPS access
back to the main installation.

### Network Discovery and Automation

- Multiple definable network discovery rules

- Automation templates that specify how devices are configured

### Plugin Framework

The plugin framework allows functionality to be extended and augmented without
modifying the core. The 1.2 plugin API is preserved on this branch.

### Dynamic Graph Viewing Experience

- Dynamically loaded tree and graph view

- Searching by string, graph and template types

- Viewing augmentation

- Simple time span adjustments

- Convenient sliding time window buttons

- Single click realtime graph option

- Easy graph export to csv

- RRA view with just a click

### User, Groups and Permissions

Per user and per group permissions at a per realm, per graph, per graph tree
and per device level. The permission model is role based access control (RBAC).
Password complexity, password age and expired password changes can be enforced.

## RRDtool Graph Options

Most RRDtool graphing abilities are supported.

### Graph Options

- Full right axis

- Shift

- Dash and dash offset

- Alt y-grid

- No grid fit

- Units length

- Tab width

- Dynamic labels

- Rules legend

- Legend position

### Graph Items

- VDEFs

- Stacked lines

- User definable line widths

- Text alignment

## Documentation

- [Getting started](https://kadupulhq.github.io/website/start/what-kadupul-is/)
- [Installation](https://kadupulhq.github.io/website/start/install/)
- [Documentation map](https://kadupulhq.github.io/website/map/)
- [Compatibility](https://kadupulhq.github.io/website/project/compatibility-with-cacti/)

## Contributing

Both documents live on `main` and apply to this branch too. Read
[CONTRIBUTING.md](https://github.com/kadupulhq/kadupul/blob/main/CONTRIBUTING.md)
before opening a pull request, and report vulnerabilities through
[SECURITY.md](https://github.com/kadupulhq/kadupul/blob/main/SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE) for code inherited from Cacti; files added by this
project are GPL-3.0-or-later. Dependencies retain their own license terms.
See the [licensing documentation](https://kadupulhq.github.io/website/project/license/).

-----------------------------------------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
Copyright (c) 2026 - The Kadupul project and contributors

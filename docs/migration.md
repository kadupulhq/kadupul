# Migration design

Nobody adopts a monitoring system by starting over. An existing installation that has run
for eight years holds graph history nobody can recreate, device templates
somebody tuned by hand, and thresholds that encode what "normal" means on that
network. If Kadupul cannot take that over, it has no reason to exist.

This is the design. Nothing here is built yet.

## The shape of the problem

An existing installation is four things, and only one of them is a database.

| Part | What it is | Hardest problem |
|---|---|---|
| Database | 126 tables | Distinguishing configuration from local state |
| RRD files | One per data source, on disk | Paths are stored in the database |
| `include/config.php` | Credentials, paths, connection | Hand-edited, never uniform |
| `plugins/` | Third-party code | Not ours, versions vary, some abandoned |

## Configuration against state

Most of the 126 tables are configuration and must come across intact. A
meaningful minority are local state that the poller rebuilds on its next cycle,
and copying them wastes time and imports staleness.

Poller output queues contain measurements that may not yet exist in the RRD
files. Preserve `poller_output` and its boost and realtime variants until their
samples have been acknowledged; do not truncate them during migration. See the
[durable-queue upgrade procedure](upgrading-rrd-storage.md) for the maintenance
window and storage checks required by the current code.

Other runtime tables, including `poller_item`, `poller_time`, `host_snmp_cache`,
and the `data_source_stats_*` family, need an explicit migration policy and
validation of their rebuild behavior before any reset is automated.

Everything else moves. `settings` is a two-column name and value table, which
makes it easy to move and easy to get wrong: a setting the fork renames has to
be translated on the way through, not copied.

## The RRD files are the hard part

`data_template_data.data_source_path` stores paths as `<path_rra>/name.rrd`,
with `<path_rra>` expanded at read time from a setting. That indirection is the
one piece of luck in this design: move the files, change the setting, and the
stored paths still resolve.

What that does not survive is a change of RRD step or heartbeat, or a rename
that changes the file names. Neither is planned. If either becomes necessary it
is a data migration in its own right, with its own tool and its own reversal.

Verify the files rather than trusting the count. For every data source the
database knows about, the file exists, `rrdtool info` reads it, and its last
update is within a poller cycle of what the database believes.

## Plugins

Plugins are the reason the rename stops at the plugin API, and they are the part
we cannot fix from here. The tool's job is to be honest about them: list what is
installed, what version, whether it is known to work, and say plainly which ones
we cannot vouch for. It must never silently disable one.

## Reversible, or nobody runs it

An operator will not point a one-way tool at a production monitoring system, and
they are right not to. So:

- Nothing is destructive. The tool reads the source install and writes a new one
  beside it. The original keeps running until someone stops it.
- The two can poll in parallel during a soak. Double polling costs device load,
  which is the operator's call to make, not ours.
- Rolling back means pointing the web server back at the old install. No undo
  script, because an undo script is another thing that can be wrong.

## Stages

Each stage is separately runnable and separately verifiable.

1. **assess** reads an existing installation and reports what it found, what would move,
   and what will not. Read only, no credentials written, safe against
   production. This is the stage worth building first, because it is useful
   before any of the rest exists.
2. **plan** turns an assessment into a written plan: what changes, estimated
   downtime, which plugins have no path.
3. **migrate** copies the database, moves or copies the RRD files, translates
   settings, and writes the new configuration.
4. **verify** compares the two installs: device counts, data source counts,
   every RRD readable and current, and a sample of graphs rendering the same.

## What assess reports

- source version, from `SELECT cacti FROM version`, and whether it is one we
  support migrating from.
- Counts: devices, data sources, graphs, templates, users, and how much of each
  is disabled or orphaned.
- RRD files: how many the database expects, how many exist, how many are
  readable, and how many are stale.
- Plugins: name, version, and whether it is known to work.
- Anything unusual: remote pollers, non-default RRD paths, settings that have
  been changed from their defaults, data sources pointing at files that are not
  there.
- Disk needed, so nobody starts a copy that runs out of space at 90 percent.

## Versions we migrate from

1.2.x and later only. Earlier installs upgrade to 1.2 with the supported upgrade
path first, which is 47 upgrade scripts we are not reimplementing. The tool
should say so rather than trying.

# RRDfile path containment

Kadupul creates, updates, reads and maintains a Data Source RRDfile only when
its path resolves inside the RRA directory, `<path_cacti>/rra`, or inside a
directory that `include/config.php` lists in `$config['rrd_additional_paths']`
(see below). This is the default on `main` (1.3) and cannot be turned off. Cacti 1.2 and Kadupul 1.2
accept any absolute path an administrator enters as a Data Source Path.
Kadupul 1.2.32 offers the same check as the opt-in setting *Restrict RRDfiles
to the RRA Directory*, off by default.

The check resolves the deepest part of the path that exists, so:

- a symbolic link inside the RRA directory that points elsewhere is refused;
- a link that stays inside, or an RRA directory that is itself a link to
  other storage, is followed and accepted;
- a file or structured subdirectory that does not exist yet is judged by the
  directory that will hold it;
- a dangling link and a `..` segment are refused.

With the RRDtool proxy (`storage_location`), only the text of the path is
checked and the proxy confines it to its own root.

A refused path is logged as `RRD path is outside the RRA directory or cannot
be used, and was not accessed`. Samples the poller collects for it are moved
to the dead letter table instead of being written.

## Upgrading an install that keeps RRDfiles elsewhere

During an upgrade, the installer's *Directory Permission Checks* step has an
**RRDfile Locations** section. It counts the Data Sources whose stored path
would be refused and lists up to 25 of them by Data Source ID and stored
path. It reports only and does not block the upgrade. If the database cannot
be read, the section says that the locations were not checked.

For each install that reports Data Sources there, choose one of these before
or right after the upgrade:

1. **Keep the files where they are.** List the directories that hold them in
   `include/config.php`:

   ```php
   $config['rrd_additional_paths'] = array('/data/rrd', '/srv/cacti-archive/rrd');
   ```

   Each entry must be an absolute path. A listed directory is checked exactly
   like the RRA directory: links are followed, and a path that leaves the
   directory is refused. A directory that resolves to the filesystem root
   contains nothing and is ignored. List only directories that hold RRDfiles.
   The list is read from `config.php` only, so it cannot be widened from the
   web interface. Remote data collectors read their own `config.php` and need
   the same entry if they update these files locally.

2. **Move the files into the RRA directory.** Stop the poller, move the files
   under `<path_cacti>/rra`, and then either clear each Data Source Path so
   the poller generates it, or run `cli/structure_rra_paths.php` to place them
   in the structured layout.

Once the upgrade finishes, `grep "outside the RRA directory" log/cacti.log`
lists any Data Source that is still refused.

## Rollback

`rrd_additional_paths` is read at every request. Removing an entry makes the
Data Sources below it stop updating again, without changing any stored data.
Reverting to a version without containment restores the old behaviour, but
samples already moved to the dead letter table are not written back on their
own. Replay them with `bin/console kadupul:rejected-samples:replay` once
their path is allowed again.

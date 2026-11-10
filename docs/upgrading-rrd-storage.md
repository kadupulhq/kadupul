# Upgrading RRD storage and the poller queue

The durable-queue changes require an explicit maintenance window. Deploying the
code alone does not convert an existing MEMORY queue. The installer and poller
preflight refuse an unsuitable queue so collection cannot silently continue on
volatile storage.

1. Stop scheduled and running collectors, including remote collectors, and stop
   web-triggered collection and maintenance. Back up the database, configuration,
   and RRD files together before changing either code or storage.
2. With collectors stopped, make the new CLI available and run
   `php cli/upgrade_database.php --migrate-poller-queue`. This converts the selected
   `poller_output` table to InnoDB without deleting its retained samples. The
   conversion may require extra database disk space and a table lock; allow the
   database operation to finish before restarting any producer.
3. Use the same queue destination as the collector. Online remote collectors
   produce into the main database; the command keeps that established target.
   Use `--local` only when intentionally checking or migrating a local queue.
4. Run `php cli/upgrade_database.php --check-rrd-storage` under every actual web
   and poller service account. Resolve storage ownership and trusted-user/group
   settings described in [the storage guide](testing/spikekill-safety.md) until
   every check passes. A successful check as root is not sufficient.
5. Complete the normal database upgrade, then restart collectors. Confirm that
   healthy samples drain and inspect logs for retained samples. Repair any RRD
   schema or storage failures before replaying their queued data.

Monitor database free space and retained queue depth throughout recovery. Samples
are not expired automatically: removing a queue backlog to make a check pass
would lose measurements. Preserve the pre-upgrade backup for rollback and stop
all writers before restoring its matching database, RRD files, configuration,
and code.

## Proxy reply deadlines

Proxy replies use the same `rrd_command_timeout` value as local acknowledged
commands: 60 seconds by default, clamped to 1–3600 seconds. The active poller
budget can shorten that deadline. Partial reply bytes do not restart the clock.
A command whose budget has already expired is not sent.

A timeout discards the connection without sending another frame and returns an
unconfirmed result. The poller retains samples without acknowledgement and
reconnects on a later attempt. A caller holding the old pipe receives a controlled
failure; it must open a new connection. Check proxy health and network latency
before increasing the command timeout. This bounds reply waiting; connection,
encryption and command writes have their existing behavior.

No schema or credential changes are required. Reverting the code restores the
previous unbounded reply wait; it does not reverse commands the proxy may have
executed before its reply was lost. LTS remains unchanged for issue #784.

## POSIX one-shot deadlines

On main, local POSIX one-shot commands drain stdout while sending stdin. Their
I/O uses the same `rrd_command_timeout` setting: 60 seconds by default, clamped
to 1–3600 seconds and shortened by an active caller budget. Receiving more bytes
does not extend the deadline. Discard-output calls drain without retaining the
response in memory.

A timeout or incomplete input exchange kills and reaps the child, returns
failure and logs that the command outcome is unconfirmed. Timed-out partial
output is not returned or displayed. Graph exports propagate this failure.
A timeout does not roll back changes RRDtool already made. Check the RRD or
export file state before retrying a write.

This does not change persistent acknowledged pipes, the Windows fallback or
LTS (#782). No schema or credential migration is required. Reverting restores
the former blocking path; it does not restore prior RRD or export file contents.

## Tuning an RRDfile

`rrdtool_function_tune()` in `lib/rrd.php` changes a data source's heartbeat,
minimum, maximum, type or name with `rrdtool tune`. RRDtool applies each option
as it reaches it, so a rejected later option used to leave the earlier ones
stored without any report (#777). Core has no caller of this function;
plugins do.

The function now changes all requested options or none. Under the exclusive
RRD maintenance lease it copies the RRDfile to
`kadupul-tune-<16 hex digits>.bak` in the same directory and checks the copy
by SHA-256. If `rrdtool tune` fails, the copy is restored through a new file
in that directory that is renamed over the RRDfile, so the RRDfile is never
left empty or half written. The account that tunes therefore needs write
access to the RRDfile's directory, as RRD restores already do.

It returns `true` when every option was applied or there was nothing to
change, and `false` otherwise. Before #1393 it returned nothing. It refuses,
without running RRDtool, when `RRDCACHED_ADDRESS` is set, when the RRDfile or
the RRDtool binary is missing, when the maintenance lease is not free within
5 seconds, or when the copy cannot be made. Most of these write an error log
line:

| Log message | Meaning |
| --- | --- |
| `RRDtool tune failed for '<file>'; the RRDfile was restored unchanged.` | The file is as it was before the call. Fix the rejected option and retry. |
| `RRDtool tune failed for '<file>' and the RRDfile could not be restored; its previous contents are in '<copy>'.` | The RRDfile may hold some of the changes. The named `.bak` file is the only intact copy and is kept. |
| `RRD tuning could not copy '<file>' before tuning; no changes were made.` | Check free space and permissions in the RRDfile's directory. |
| `Stop external RRD writers and disable RRDCACHED_ADDRESS before tuning.` | The copy and restore cannot be coordinated with rrdcached. |

To recover after the second message, stop the poller and other writers for
that file, compare the RRDfile with the copy (`rrdtool info`), and move the
copy over the RRDfile if the RRDfile is wrong. The copy's name does not end in
`.rrd`, so the RRD Cleaner does not list it; delete it once it is no longer
needed.

## Abandoned temporary files

RRD restores, including the restore after a failed tune and the restores in
`cli/splice_rrd.php` and `cli/float_rrdfiles.php`, write a candidate named
`.rrd-restore-<random>` beside the RRDfile and remove it in a `finally` block.
A killed process cannot run that block, so the file stayed behind for good.
Since #1362, a restore first removes candidates in the same directory that
are more than a day old. Each process checks a directory at most once an hour.
Only regular files whose name matches exactly are removed: directories,
links, look-alike names and `.rrd` files are kept. A candidate less than a day
old may belong to a restore that is still running and is left alone.

The tune copies described above are not swept, because one that is kept may
be the only intact copy of an RRDfile.

The data input whitelist writer (`cli/input_whitelist.php`) does the same for
its own replacements. After it takes the whitelist lock, it removes
`.whitelist-<32 hex digits>` files in the whitelist's directory that are more
than an hour old.

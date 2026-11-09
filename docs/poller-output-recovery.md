# PHP poller output recovery

The PHP poller records each batch in a local SQLite queue before writing
`poller_output` and, when redirect is enabled, `poller_output_boost`. Both database
writes use the original batch timestamp and, when both tables are InnoDB, one
transaction. When either table uses another engine (for example a MEMORY
`poller_output_boost` chosen for Boost), the writes run without a transaction as
in 1.2.31; replay stays safe because a duplicate key keeps the stored row. The
log notes each such table once per process. The timestamp
comes from the collector clock at enqueue time; keep collector and database
clocks synchronized. Only a
confirmed commit permits deletion from the local queue. A failed handoff stops
collection, records the run as complete so the main poller does not wait out its
maximum runtime, logs an error and exits nonzero; the next invocation replays
retained work before collecting newer samples. This change does not alter Spine's writer.

If the poller is ever run as root while the spool directory does not exist yet,
root creates it, and later runs as the collector account refuse to start. The log
then names the directory, its owner and the account the poller runs as. Stop the
poller, run `chown -R <collector account> <spool directory>`, keep the directory at
mode 0700 and the queue files at 0600, and do not delete pending queue files. A
directory or file with wider permissions is reported with the `chmod` to run.

Configure `$config['poller_output_spool']` in `include/config.php` for persistent
local storage outside the web document root. Its parent must exist. On POSIX,
the final directory must belong to the collector account and have mode 0700;
queue files must be private regular files with one hard link. On Windows,
restrict the directory ACL to the collector and trusted administrators. All PHP
poller children for the installation must share the directory. SQLite3 is already
an application dependency. Network filesystems are not a supported queue location.

Without an override, the poller uses an application-specific directory under
`sys_get_temp_dir()`. This survives process restarts, but OS cleanup, systemd
PrivateTmp lifecycle changes or reboot may remove it. Persistent storage is
required for retention across those events. Back up the queue with the collector
stopped; retain its journal alongside the database when recovering an interrupted
write. Do not edit or remove pending rows to silence an error.

Each collector queue is limited to 16,384 SQLite pages (normally 64 MiB), with
batches of at most 2,002 rows and 4 MiB of encoded payload. Replay attempts at most
20 batches per invocation and stops starting batches after ten seconds. A single
database call can exceed that interval. Repeated invocations drain a larger
backlog. Queue operations serialize children briefly; the change adds a local
durable write per batch. No deployment-scale throughput guarantee is claimed.

If collection stops, check the collector log, database availability, local disk
space and queue ownership. Correct the cause and allow the scheduled PHP poller to
retry. An over-width value or name is stored truncated, as in 1.2.31, with a
warning that lists the database warning codes. A queued batch that cannot be
decoded is moved to the queue file's `quarantine` table with an error, and the
batches behind it are delivered. The transaction must own its database connection;
caller-owned transactions cannot acknowledge queued work. Retention cannot
recover samples lost before enqueueing, failed local writes, or lost storage.
A process crash after database commit can cause replay; duplicate database keys
are retained unchanged. This is not an exactly-once guarantee for downstream RRD
processing, nor a transaction across primary and remote collectors.

Before rollout, back up database/configuration state and quiesce old PHP poller
children. Deploy the matching PHP files together, verify directory access, then
resume collection. For rollback, stop scheduling, let active children finish,
and drain the queue using the new implementation before restoring old files.
Old versions do not read this queue. Preserve an undrained queue for recovery
with this version; reverting code does not recover or undo already written data.
No schema migration or dependency upgrade accompanies this change.

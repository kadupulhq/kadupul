# Spike-removal filesystem safety

Run spike removal as the account that owns the RRD and backup directories.
The working directories must belong to that account or root and must not be
group- or world-writable. Their ancestors must also prevent other accounts
from replacing them; a sticky temporary root is allowed when the child belongs
to the running account or root. Root must not process files through directories
owned by an unprivileged account. Prefer the poller account over root.

These checks are necessary because a pathname identity check is not an atomic
filesystem operation. Exclusive creation prevents replacement of an existing
file, but does not make an attacker-controlled parent safe. Directory identities
are also checked before and after creation. If POSIX account identity is not
available, creation fails closed; Windows ACL validation is not implemented.

Restore writes an exclusive sibling first. A failed, empty or partial restore
leaves the live RRD unchanged. On success the original owner, group and mode are
preserved, and the validated sibling replaces the live directory entry with
rename. Rename replaces a swapped symlink itself rather than following its target.
Requested recovery snapshots remain available after success or failure.

Local RRDtool writers hold a shared advisory lock on the configured RRA directory
inode. Synchronous commands release it only after the child exits; piped commands
retain it until `rrd_close()` drains and waits for RRDtool (also at PHP shutdown).
Spike removal waits for an exclusive lock before its dump and holds it
through backup and atomic replacement. It retries brief contention for up to
60 seconds, bounded further by spikekill_timeout, then safely refuses if polling
still owns the store; retry after polling finishes. A new writer waits until maintenance completes.
Before opening a lease, all local writers validate the configured directory,
its symlink entries, its canonical target, and their ancestors against the same
ownership and permission policy, with explicitly configured service-account
trust for split web/poller deployments (see below). PHP's POSIX extension is
required on Unix; unsafe or unverifiable storage fails closed. Root and the
service account are trusted: this does not protect against either changing
permissions or replacing directories during an operation. They must stop all
writers and maintenance before changing the storage namespace.
The piped hot path reuses its existing lock without reopening or statting storage.

All Kadupul processes sharing RRD files must configure the same RRA directory,
including when individual RRDs live elsewhere. Stop pollers before replacing that
directory or changing storage configuration. External writers and cache daemons do
not participate in this protocol: quiesce them before maintenance. Spike removal
refuses a configured `RRDCACHED_ADDRESS`. Use local POSIX storage with working
cross-process directory `flock`; unsupported locking fails closed. No lock file is
created or removed, so a user cannot split the lock by unlinking a sidecar file.

Heartbeat tuning holds an exclusive lease and waits for active writers. XML rewrite utilities hold an exclusive lease from before their dump through child completion and refuse contention or a configured cache daemon. Splicing takes an exclusive lease before reading and rewriting its RRDs. Floating
first flushes through the regular backend and fetches its inspection data, then
waits for an exclusive lease before dumping and rewriting. Float workers
therefore serialize the rewrite phase rather than racing each other or polling.
A busy splice exits before dumping. Cache-daemon rewrites are refused. Windows
retains its existing non-spike CLI behavior because spike removal remains disabled.
Private replacement pipes opened during crash recovery are drained and closed
before returning; later calls with the closed original pipe use synchronous I/O.

Batch gap repair uses one worker even when multiple threads are requested, so its own children cannot reject one another under the global exclusive maintenance lease. A repair refuses an already-active polling writer and requires a retry; normal polling writers wait up to five seconds behind active exclusive maintenance. An unavailable or replaced storage directory fails closed. Heartbeat tuning also requires the external cache daemon to be disabled.

Float children handle SIGTERM and SIGINT while waiting for a lease, unregister their own task identity, and leave queued samples intact. Failed writer initialization prevents normal and on-demand Boost queue consumption; main Boost cleanup retains nonempty or unverifiable archive tables.

An exclusive rewrite aborts after a broken pipe; it cannot release its lease and retry a now-stale dump snapshot. Ordinary writer recovery remains available.

### Separate web and poller accounts

When the web server and poller run as different Unix users, configure
`$config['rrd_maintenance_trusted_uids']` in `include/config.php` with the numeric
UIDs of both service accounts (including any account owning a storage ancestor).
Use identical configuration for every process sharing the store. If the storage
is group-writable, also explicitly list its numeric GID in
`$config['rrd_maintenance_trusted_gids']`; every member of that group must be trusted
to administer the storage. Normal filesystem access permissions still apply.
Do not infer trust from the directory's current owner or from group membership.
World-writable storage remains rejected, as do unlisted owners and writable
ancestor groups. Stop all participating processes before changing storage paths.
The stricter spike backup filesystem checks remain in effect independently.

### Upgrade prerequisite

Before upgrading an existing split-account installation, identify the numeric
UIDs of the web and poller accounts and the groups allowed to modify RRD storage.
Set the explicit trust lists in the existing `include/config.php` on every
participating collector; changing `config.php.dist` does not update an installed
configuration. PHP's POSIX extension is required for local Unix RRD storage.
For example, a `0775 cacti:apache` store requires the actual numeric UID of
`cacti` (and the web account) and the numeric GID of `apache` in those lists.
Use the IDs from the installation, not example numbers from another server.
Do not make the storage world-writable to work around a permissions error.

The web/CLI installer permission step reports this prerequisite, and the actual
installation/upgrade operation checks again before schema or version changes.
`cli/upgrade_database.php` also refuses an unsafe storage configuration before
running upgrades. The force option cannot bypass the storage prerequisite.
Run the check as both service accounts before putting the upgraded code in
service. Remote RRDtool proxy storage retains its existing path. See the Windows acknowledgement limitation below.

Queue deletion uses exact selected sample keys so newer timestamps remain queued.
The queue-query benchmark does not measure acknowledged RRD write throughput.


### Acknowledged updates and bounded waits

Local Unix pollers reuse one full-duplex RRDtool process for acknowledged updates.
Only recognized permanent sample errors (unknown data-source name, wrong value
count, or an already-written timestamp) consume an unwritten queue key. The
poller logs its path, timestamp, values, and reason before continuing with later
timestamps. Filesystem, cache-daemon, resource, and unrecognized errors remain
queued for retry. This prevents a permanently invalid
sample from filling the MEMORY queue. Timeouts, crashes, and missing responses
retain samples for retry. Rejected data can be recovered from the logged values
after correcting the underlying storage or template problem; monitor these errors.
A rejected update still makes the drain report failure. It is never counted as a
successful write. RRDtool protocol output is suppressed in web requests.

Read-only graph, graphv, xport, fetch, info, first, last, and lastupdate commands
do not acquire a writer lease or require writer trust configuration. Their normal
filesystem read permissions still apply. Writers wait at most five seconds for
shared access; float workers also have a five-second exclusive acquisition bound.
Contention fails the operation and retains queued samples for a later retry.
Utility XML restores write to a temporary file beside the original and replace it
only after RRDtool acknowledges success and ownership/mode are preserved. Failed
or timed-out restores leave the original file and recovery XML intact.

Windows uses synchronous per-command acknowledgements; persistent nonblocking
RRDtool pipes are POSIX-only. Windows poller throughput has not been validated by
this change. Do not treat the Unix capacity evidence as Windows capacity evidence.

The primary poller and Boost master also check this prerequisite before launching
collection or worker processes, including after a code-only deployment. They exit
nonzero, log the required configuration, and use the existing administrator
notification settings. This prevents new collection from silently filling a
queue that cannot be drained; existing queued samples remain intact.

# Poller cache buffer ownership

Buffered cache publication uses the configured authoritative primary PDO and
verifies the complete buffer before mark/insert/delete. Positive host IDs are
locked in order before data-source mappings are locked and revalidated. An
absent, deleted, differently assigned, or changed source refuses the buffer;
it does not reroute previously generated payloads. Explicit host 0 sources
remain supported on poller 1. Empty item lists still remove selected stale
cache rows, while empty selections update the last-change timestamp without
writing polling rows. Null/false/empty tuple entries preserve the producer's
disabled-device and invalid-SNMP omission semantics.

The operation preserves caller transactions through savepoints. Remote writes
use the captured remote PDO and corresponding host locks. The raw tuple
payload, plugin hook ordering, SQL defaults and packet size remain unchanged.
Only the three numeric identity fields are inspected. Plugin tuples must use
the existing local-data ID, poller ID, host ID prefix.

Before each database unit completes, checked locking reads confirm the stored
host assignment of rows on the requested poller, removal of selected stale
rows, and at least one present row for every supplied record ID. Rows on other
pollers remain outside cleanup. Duplicate tuples retain last-write behavior.
The primary timestamp is read back before it is returned to the cache. These
checks do not verify every raw tuple's composite `(local_data_id, rrd_name)`
key or full payload; plugin fields after the identity prefix are not parsed.

An unavailable remote retains the original warning and primary-only update.
A write or ownership failure throws a generic error rather than returning an
ignored false. DataInput/device-state worker boundaries already translate
exceptions to unsuccessful outcomes; legacy CLI/UI callers stop before their
success/redirect path. Offline collector-local state is not authoritative for
publishing these ownership-dependent updates.

The servers do not share a transaction. A primary commit failure can occur
after the remote committed, and a caller's outer rollback cannot undo a
committed remote unit. Such failures remain explicit; distributed rollback
or all-or-nothing propagation is not claimed.

`PollerCacheBufferWriteTest` runs ordinary fixed-code business/failure cases
against disposable schemas using the shipped DDL. It covers current owner,
host 0, empty cleanup, batching, raw payload preservation, unavailable remote,
invalid identity, transaction ownership, read/write failures, and commit
failure with an explicit partial outcome. It does not execute old writers,
contact devices, or claim a reproduced race or installed CLI proof.

# Review dispositions — 2026-09-17

Incomplete-sample expiry now derives its cutoff from the database clock, using
retention seconds rather than a PHP-local formatted timestamp. Three real-engine
timezone cases pass on each of the six engines in the compatibility matrix.

Complete unwritten samples are deliberately retained during writer outages.
Deleting them to bound backlog would violate the retry contract. Queue backpressure
and capacity monitoring remain follow-up work under issue #104; the retention
limit applies only to incomplete samples.

Plugin sorting must supply an explicit per-query column allowlist to
`get_order_string`. The empty default fails closed; restoring unchecked request
columns would reopen the injection path. All in-repository callers provide maps.

RRD command deadlines now accept 1–3600 seconds (default 60), and completed
response lines are scanned once. Real-process tests cover 8 MiB replies and split
acknowledgements; deterministic clock tests cover long and clamped deadlines.

Windows storage preflight probes actual file access. Its new native Windows CI
job must pass before that platform-specific behavior is considered verified.

Schema mismatches now retain complete samples until the RRD is repaired. Direct
writer tests replay the original values after extending the schema; Boost tests
replay a 512-timestamp buffer after repairing its data-source name. Unknown names,
extra values and template-size errors no longer return success without a write.
Terminal old timestamps do not defer later drains; transient errors still do.

Boolean commands on a supplied legacy write-only pipe fail without submission,
avoiding a separate process overtaking queued commands. Native acknowledged-pipe
and legacy-pipe cases verify ordering and unchanged timestamps. Failed RRD dumps
return maintenance errors before XML parsing, preserving the original file.

Queue diagnostics and explicit migration resolve the producer's actual destination:
primary for online remote collectors, local otherwise, with an explicit `--local`
override. Ordinary schema upgrades check that selected queue first. The new
`--migrate-poller-queue` command is idempotent and independent of filesystem access.
Offline/recovery collectors' transient normal queue is excluded from that gate;
their authoritative backlog is in Boost. Native tests record connection identity
and verify that the probe and ALTER use the same connection.

Failed background drains wait five seconds before opening another writer or scanning
the pending queue again. The final drain bypasses this delay, and a successful
drain clears it. New samples can wait up to five seconds during a failure; they
remain queued. Per-path retained-sample errors repeat at most once per minute
within a process unless the reason changes or a successful write clears the
suppression. The existing retained-output warning also sends a debounced
administrator email. These bounds do not expire valid measurements or provide
an unlimited storage guarantee.

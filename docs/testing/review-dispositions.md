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

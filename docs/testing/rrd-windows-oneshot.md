# Windows RRDtool one-shot execution

Issue #782 also affects the Windows fallback on LTS and main. It writes all
input before reading stdout, so a child producing output before consuming a
large command can deadlock. Blocking pipes also prevent the configured command
deadline from terminating a stalled child.

The LTS Windows fallback now prepares the complete command in an owned binary
temporary file and gives the child file handles for stdin and stdout. This
avoids anonymous-pipe backpressure without relying on Windows pipe selection.
The existing argv and inherited stderr behavior remain unchanged. The parent
polls the child against the monotonic command deadline, capped by the poller
deadline, and terminates and reaps a timed-out child before closing its files.
Positive short input writes are retried. Temporary-file failures return an
unconfirmed outcome; boolean callers still require exit zero, an OK response,
and no ERROR response. No schema or dependency changes are required.

Windows execution now requires writable temporary storage for the command and
response. Files are created with `tmpfile()` and closed on all return paths;
no caller-owned file is removed. Disk usage scales with command and output
size. Filesystem stalls and process-creation latency are outside the polling
deadline's control. A timeout cannot undo an RRD mutation already executed.

`tests/integration/rrd_windows_oneshot.php` runs 21 checks through real PHP child
processes and the complete production library. It verifies input hashes, binary
output larger than pipe capacity, discarded output, stalled children, partial
responses, nonzero exits, empty output, acknowledgements, diagnostics and the
poller deadline cap. It passes locally on PHP 8.1.34 and 8.4.25 on Darwin.
The Pest wrapper imports subprocess coverage through the existing collector.
The existing Windows storage CI job runs the same probe with `--native-windows`
on PHP 8.1. Native Windows CI and a real Windows RRDtool binary remain pending.

Run from the repository root:

```sh
mise exec php@8.1.34 -- php tests/integration/rrd_windows_oneshot.php
```

Deploy with the normal application update and writable PHP temporary storage.
Revert the change to roll back application behavior; that restores the Windows
blocking-pipe defect. This patch does not roll back RRD writes. Main still needs
the equivalent correction, so #782 must remain open until both branches pass
their Windows checks. No release or deployment is claimed.

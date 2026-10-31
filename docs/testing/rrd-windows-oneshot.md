# Windows RRDtool one-shot execution

Issue #782 affects the Windows fallback on main. It writes all input before
reading stdout, so a child producing output before consuming a large command
can deadlock. LTS has the equivalent correction in #1227, merged as
`d92a59ced42b86940124ead100ff3060b8d59221`.

`WindowsOneShotRrdtool` prepares the complete command in an owned binary
temporary file and gives the child file handles for stdin and stdout. The
parent polls against the configured monotonic deadline, capped by the poller
deadline. It terminates and reaps a timed-out child before closing its files.
No shell, dependency, schema or runtime change is introduced. Main's existing
line-break normalization and web-context preparation remain in place. POSIX
execution continues through `OneShotRrdtool`.

Windows now needs writable temporary storage proportional to command and
response size. Files use `tmpfile()` and close on all return paths. Positive
short input writes are retried. Filesystem stalls and process creation are
outside deadline polling; a timeout cannot undo an executed RRD mutation.
Boolean callers require exit zero, an OK response and no ERROR response.

On PHP 8.4.25, the complete-library probe passes 21 checks using real children:
large input and binary output, exact normalized input hashes, discarded output,
stalled children, partial responses, nonzero exits, empty output, diagnostic
printing, acknowledgements and the poller deadline cap. The original main
library reaches the large-command exchange and deadlocks beyond a 12-second
watchdog. All 20 existing POSIX one-shot tests pass, including real RRDtool.

PCOV executes 41 of 50 tracked lines in the Windows class. The Pest wrapper
imports child coverage with source/scenario hashes, required source hits and
completion markers. Twenty-one negative evidence controls reject incomplete,
stale or unregistered reports. Temporary-file creation failures and filesystem
failure injection are not covered by these native process cases.

Run from the repository root:

```sh
mise exec php@8.4.25 -- php tests/integration/rrd_windows_oneshot.php
```

The existing Windows storage CI job runs the same probe with `--native-windows`
on PHP 8.4. Current-head native Windows CI, real Windows RRDtool and deployed
poller/browser workflows remain pending. Require branch CI and review before
merge. Deploy with normal application updates and writable PHP temporary
storage. Reverting restores the blocking-pipe defect and cannot undo RRD writes.
No deployment or release is claimed.

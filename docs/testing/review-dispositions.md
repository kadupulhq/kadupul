# Release-readiness review dispositions

The 2026-09-17 local review identified two queue-command defects on remote
collectors. `--check-rrd-storage` and `--migrate-poller-queue` now retain the local
database connection. Ordinary schema upgrades retain their existing default.
Migration checks the engine first, skips DDL for InnoDB, and rejects unavailable
engine metadata. Native CLI regressions cover these cases and explicit `--local`.

Windows preflight now probes actual create/read/write/delete access instead of
using `is_writable()` as a directory ACL test. PHP 8.1's
[`php_win32_ioutil_access_w`](https://github.com/php/php-src/blob/PHP-8.1/win32/ioutil.c#L629-L654)
rejects the read-only attribute even for a directory. The installer regression
injects that false-negative metadata result while exercising real file access;
the Windows CI job tests the actual directory attribute. The latter requires a
completed native Windows CI result before this platform-specific fix is verified.

The formatting finding concerns an aggregate PR diff, not a mixed commit.
Commit `58cd3ae2532674f260a682324e11ff01fcb34ead` contains only the poller formatting
conversion. Using the same token normalization as `tests/tools/check_php_style.sh`,
its parent and result have the identical SHA-256 token digest
`61892c64a89d2e252d4c68bb46c37f07c5efffa17e31aefd244c1d27fde9db12`.
Behavior changes are separate commits. Review those commits independently; do not
rewrite already-reviewed history merely to make the aggregate diff smaller.

Splice cleanup now explicitly records unlink failures as well as directory-removal
failures. A native permission-denial regression requires the retained-artifact
warning and verifies that the recovery XML remains available.

The next review found that a terminal rejection incorrectly latched the poller
cycle into deferred mode. Batch draining now distinguishes an absent
acknowledgement from a recorded terminal rejection: rejected samples remain
logged, while a subsequent healthy sample drains during the same cycle. Native
regressions cover both local and proxy writers; transient and database failures
still retain samples and defer retries.

Ordinary CLI upgrades now check the collector's local queue before switching a
remote collector to the main database. MEMORY or unavailable engine metadata
fails before any version mutation and gives the explicit migration command.

Local writers intentionally close after each nonempty batch to release the
maintenance lease. Empty queue checks open no process. A local Docker PHP 8.1 /
RRDtool diagnostic on 2026-09-17 measured 300 production `rrd_init`/`rrd_close`
pairs: 4.084 seconds total, 13.873 ms median, 15.181 ms p95, 15.378 ms maximum.
This measures process/lease overhead without updates on this machine, not a fleet
performance guarantee. Reusing a lease-holding local pipe across waits would
block maintenance; any future caching must separate transport from lease ownership.

The malformed-key deletion path reports failure together with the count of prior
committed chunks; callers honor that failure and retain remaining samples. It
must not report prior successful chunks as unconsumed.

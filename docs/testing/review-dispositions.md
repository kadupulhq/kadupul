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

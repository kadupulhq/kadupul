# Release-readiness review dispositions

Queue diagnostics, explicit migration and ordinary CLI upgrades resolve the same
queue destination as producers: the primary connection for online remote
collectors, otherwise local. `--local` explicitly overrides that choice. Native
CLI tests record the actual connection argument, require migration to use that
same connection, and cover unavailable engine metadata and idempotent InnoDB
migration. Queue migration does not require RRD filesystem access.

Offline/recovery remotes retain their authoritative backlog in the Boost queue;
their transient `poller_output` table is cleared by the existing collector loop.
The normal-queue durability gate therefore applies to primary and online remote
collectors, not that transient offline table.

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

Ordinary CLI upgrades check the selected producer queue before schema changes. MEMORY or unavailable engine metadata
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

Unknown data-source names, template-size mismatches and extra-value rejections
are retryable schema errors. No shortened sample is written: the complete input
remains queued and that RRD's timestamp stays unchanged. Native tests repair the
schema and replay the original values, including RRDtool 1.4. Another healthy RRD
continues processing in the same batch. Only already-past timestamps are terminal
RRDtool errors; syntactically invalid field names remain explicitly diagnosed.

A boolean command supplied with a legacy write-only pipe now returns false without
submitting it through a second process. Native tests verify that a queued create
completes, the rejected update never changes its timestamp, and acknowledged pipes
still execute both commands in order.

Failed dump commands return maintenance errors before XML parsing. Native tests
cover adding ordinary and computed data sources, cloning/deleting archives,
debug output, read-only files, failed dumps and failed restores.

`RRDsProcessed` counts acknowledged sample updates. The old implementation also
incremented per timestamp, despite the file-count wording in its docblock. Failed
or rejected writes are now excluded rather than reported as successful updates.


The wait loop re-evaluates a failed batch on its next iteration rather than
latching off until collectors finish. Successful acknowledgements are counted
even when another RRD is deferred, including across the 40,000-row page boundary.
A failed RRD remains blocked across subsequent pages of that drain so a newer
sample cannot invalidate the retained sample's eventual replay. Regression tests
exercise transient recovery, partial progress and a failure limited to page one.

Retryable schema errors deliberately retain valid observations until repair;
they are not silently expired to hide a persistent failure. Operators must repair
the reported schema mismatch and monitor queue growth during an outage. The
nonzero exit still signals unresolved writes while healthy RRDs continue draining.
A bounded archival/retention policy remains an operational design requirement.
The Windows storage probe is run by `.github/workflows/ci.yml` in the
`windows-storage-preflight` job; it is not a standalone unexecuted fixture.

Failed background drains wait five seconds before opening another writer or scanning
the pending queue again. The final drain bypasses this delay, and a successful
drain clears it. New samples can wait up to five seconds during a failure; they
remain queued. Per-path retained-sample errors repeat at most once per minute
within a process unless the reason changes or a successful write clears the
suppression. The existing retained-output warning also sends a debounced
administrator email. These bounds do not expire valid measurements or provide
an unlimited storage guarantee.

## Symfony route links and reusable workflow syntax

Semgrep's `generic.html-templates.security.var-in-href.var-in-href` flags six
Inventory Twig anchors. Each calls Symfony `path()` with a literal route name;
IDs and filter values are encoded route/query parameters, never an input URI.
Twig retains attribute escaping. The existing HTTP tests verify escaped values
and generated compatibility-entry links. Suppressions name only this rule on
those six anchors (including the current-page CSV download); arbitrary href
variables remain subject to scanning.

Zizmor's `self-repository` recommendation conflicts with the CI-pinned actionlint
image, which rejects `$/` reusable workflow calls. Keep the supported `./` form
with one annotated compatibility exception until that parser supports `$/`.
Both spellings resolve the same repository workflow; token permissions are unchanged.

## Dependency-independent sink inventory

The positive `*.php` glob followed the exclusion globs, overriding exclusions for
direct child files. Moving it before the exclusions consistently omits the
already-excluded generated dependency/cache and locale trees. The reviewed
baseline removal contains only the redirect sentinels in `include/vendor/index.php`
and `locales/index.php`; no application execution sink was removed. A fixture
checks that adding direct/nested vendor PHP and cached PHP leaves the inventory
unchanged while an authored application sink remains visible.

## Symfony migration Sonar findings

The [PR 145 quality gate](https://sonarcloud.io/dashboard?id=kadupulhq_kadupul&pullRequest=145)
reported an explicit supplied session-ID assignment (`php:S5328`). The bridge now
lets PHP resume the native HTTP cookie, with strict mode and cookie-only sessions
explicitly enabled and URL session propagation disabled. A synthetic request
cookie cannot replace the native cookie, and an already selected different ID
fails closed. HTTP regression tests verify unknown IDs are neither adopted nor
created, query parameters cannot select an existing authenticated session, and
both supported handlers retain login, revocation and logout behavior. This is a
code correction, not a scanner suppression.

The other three annotations are corrected directly: the asset builder requires
a URL object and uses its `href`; runtime selection is extracted from the offline
builder; the saved-device message uses the native `output` element.

The initial coverage report omitted the Symfony test runner and HTTP processes.
The workflow now imports measured module, file/database HTTP, save-worker,
offline-verification, dependency-installer, Python-builder and browser-build
coverage. Integration measurements must match source hashes and successful test
inventories before PHPUnit publishes a combined Clover report. Negative checks
reject stale sources, missing evidence, invalid line observations and unexecuted
workers without replacing the previous report. Generated `var/` cache is excluded
consistently; authored source remains in scope and the quality gate is unchanged.

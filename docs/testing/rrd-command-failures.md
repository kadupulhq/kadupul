# RRDtool command failure maintenance

This LTS change addresses issues #769, #776, #782, #783 and #784. It preserves
PHP 8.1 support, the procedural entry points, encrypted proxy framing and the
existing boolean distinction between rejection and an unavailable response.

| Issue | LTS correction | Main status at `9b9bbfdd4f` |
|---|---|---|
| #769 | Require a success trailer for returned proxy data; reject error replies, including replies containing both ERROR and OK. Failed or empty realtime renders leave the cached file untouched. | Existing fix in #954. |
| #776 | Reject an ERROR diagnostic before parsing a fetch header. Genuine zero and a data source named ERROR remain valid. | Existing fix in #915. |
| #782 | Exchange POSIX one-shot input and output concurrently, retry partial writes, enforce a monotonic deadline and kill stalled children before closing stdin. Propagate an unconfirmed local export as failure. | Existing fix in #965. |
| #783 | Retry partial proxy writes, bound writes, give each connection attempt a fresh socket and close failed sockets. | Complete-write and failed-connect cleanup already implemented. |
| #784 | Bound proxy command replies with the configured command timeout and any shorter poller deadline; discard failed connections so a late reply cannot acknowledge later work. | Existing fix in #939. |

The proxy key exchange is bounded to ten seconds and 16 KiB. Command timeout
configuration uses the existing `rrd_command_timeout` setting: default 60 seconds,
clamped to 1–3600 seconds. A shorter `rrd_command_deadline()` takes precedence.
Key and quit writes have a ten-second budget. TCP connection establishment still
uses the operating system's connect timeout.

## Verification

On 30 October 2026, the changes based on LTS revision `87aa922d1a` passed
123 tests across 15 focused files on PHP 8.1.34. These include real process and
socket exchanges, encrypted proxy interoperability, short-write injection,
failed-connect cleanup, stalled and dripping peers, large binary output, children
ignoring SIGTERM, fetch statistics consumers, existing path guards, and measured
zero values. The same regression scenarios reproduce failures on the unchanged
LTS base, including an ignored 150 ms proxy budget and a truncated command frame.

Main at `9b9bbfdd4f` passed 79 existing tests across `RrdOneShotIoTest`,
`RrdProxyDeadlineNativeTest`, `RrdProxyNativeTest` and `RrdFetchFailureTest` on
PHP 8.4.25. Neither run skipped tests. Both used application packages matching
their lockfiles, prepared from existing local packages; no dependency update is
part of this change. LTS uses its existing Pest 1 runner and main uses Pest 4.

From `tests/`, with the locked test dependencies present, run each LTS file
in its own process, for example:

```sh
mise exec php@8.1.34 -- php vendor/bin/pest --test-directory=. --configuration phpunit.xml Unit/Core/Rrd/RrdCommandFailuresNativeTest.php
mise exec php@8.1.34 -- php vendor/bin/pest --test-directory=. --configuration phpunit.xml Unit/Core/Rrd/RrdProxyWriteRegressionTest.php
mise exec php@8.1.34 -- php vendor/bin/pest --test-directory=. --configuration phpunit.xml Unit/DataCollection/Rrd/RrdProxyChannelTest.php
```

The new tests are registered in `phpunit-spikekill.xml` and discovered by the
complete unit runner. The native coverage collector includes `lib/rrd_process.php`.
Full CI, coverage measurement, Windows one-shot behavior, deployed RRDproxy and
installed browser/database workflows remain unverified. The local test sandbox
blocked TCP binding in one run; rerunning with local socket access passed without
changing assertions. These checks establish focused regression behavior, not
production readiness or a throughput improvement.

The tested LTS production source is identified by SHA-256:

```text
lib/rrd.php          82d4f2effdbaa0163ce23d2599b363144ec2dfe54a68788965468e56ced092fd
lib/rrd_process.php  a4211bde2623042cfd8a11ac8d264b17347be7219a830687c2735c629838525c
```

## Deployment and recovery

Deploy `lib/rrd.php` and `lib/rrd_process.php` together. No schema or dependency
migration is required. Revert both files together for application rollback.
Timeouts leave command outcomes unconfirmed: a remote mutation may already have
occurred. Preserve existing pending-sample recovery and do not interpret a timeout
as proof that no remote write happened. No production device or RRD was used in
verification.

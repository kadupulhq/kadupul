# Coverage matrix

What the behavioral harness characterizes today, and what it does not.

Characterized means a golden file exists and a fresh run reproduces it. Nothing
in this table is marked characterized on the strength of a test that was
written but never run.

Priorities: **P0** blocks Kadupul compatibility, **P1** is important behavior,
**P2** is useful, **P3** is obscure or legacy.

## Characterized

| Subsystem | Operation | Characterized | Happy path | Failure path | Edge cases | Golden fixture | Priority |
|---|---|---|---|---|---|---|---|
| Database | Fresh schema import | yes | yes | no | schema, columns, seeded rows | `database/fresh-schema` | P0 |
| Install | CLI install, mode 1 | yes | yes | no | exit status, full stdout | `upgrade/install` | P0 |
| Auth | Admin login | yes | yes | n/a | post-login layout | `auth/login-admin` | P0 |
| Auth | Invalid password | yes | n/a | yes | error text, form fields retained | `auth/login-invalid` | P0 |
| Auth | Missing CSRF token | yes | n/a | yes | POST with no token | `auth/missing-csrf` | P0 |
| UI | Device list page | yes | yes | no | status, title, form inputs | `ui/devices` | P1 |
| API/AJAX | `utilities.php` host search | yes | yes | no | JSON body preserved whole | `api/ajax-hosts` | P0 |
| CLI | `add_device.php --help` | yes | yes | n/a | exit status, usage text | `cli/device-help` | P1 |
| CLI | `add_device.php` missing args | yes | n/a | yes | exit status, stderr | `cli/device-missing` | P0 |
| CLI | `add_datasource.php` non-numeric id | yes | n/a | yes | `--host-id=oops` | `api/datasource-invalid` | P0 |
| Devices | Create via CLI | yes | yes | no | response plus resulting host row | `devices/create` | P0 |
| Devices | Delete via CLI | yes | yes | no | resulting `data_local` and `graph_local` rows | `devices/delete` | P0 |
| Graphs | Data source create | yes | yes | no | response plus `data_local` | `graphs/datasource-create` | P0 |
| Graphs | Graph create | yes | yes | no | response plus `graph_local` | `graphs/create` | P0 |
| RRD | Graph definition generation | yes | yes | no | full rrdtool graph command, captured after the poll creates the RRD | `graphs/definition` | P0 |
| SNMP | v2c get, known and custom OID | yes | yes | no | deterministic snmpd fixture | `snmp/get` | P0 |
| Plugins | Install | yes | yes | no | `plugin_config`, `plugin_hooks` | `plugins/install` | P0 |
| Plugins | Enable | yes | yes | no | status transition | `plugins/enable` | P0 |
| Plugins | Hook dispatch, enabled | yes | yes | no | 7-value type matrix, callback count | `plugins/hook` | P0 |
| Plugins | Hook dispatch, disabled | yes | n/a | yes | zero callbacks, hooks still registered | `plugins/hook-disabled` | P0 |
| Plugins | Disable | yes | yes | no | status transition | `plugins/disable` | P0 |
| Plugins | Uninstall | yes | yes | no | rows removed | `plugins/uninstall` | P0 |
| Plugins | Callback order and arguments | yes | yes | no | full ordered lifecycle log | `plugins/callbacks` | P0 |
| PHP types | Coercion through helpers | yes | yes | yes | null, false, true, `''`, `'0'`, 0, `'1'`, 1, numeric, malformed, array, unexpected key | `api/type-coercion` | P0 |
| PHP types | `get_request_var` memoization | yes | n/a | n/a | stale read after superglobal change | `api/type-coercion` | P0 |
| PHP types | Empty result-set shapes | yes | n/a | yes | `db_fetch_row`, `_cell`, `_assoc` | `api/type-coercion` | P0 |
| Diagnostics | Warnings, notices, deprecations raised outside Cacti's own handler | yes | n/a | yes | grouped and counted, suppression flagged | `api/php-errors` | P0 |
| Diagnostics | Visible prepend PHP events | yes | yes | yes | fatal and suppression masks | `diagnostics/visible-php-errors` | P0 |
| Diagnostics | Application-handler PHP log | yes | yes | yes | calibrated warning, ordered duplicate events | `diagnostics/application-log` | P0 |
| Diagnostics | Handler calibration | yes | n/a | yes | `E_USER_WARNING`, `TypeError` | `api/warning-calibration` | P1 |
| Poller | Full run against a device with data sources | yes | yes | no | exit status, stats line, poller cache, rrdtool argv | `poller/run-reachable` | P0 |
| Poller | rrdtool exits non-zero mid-run | yes | n/a | yes | poller still exits 0; fwrite notice on the broken pipe | `poller/rrd-failure` | P0 |
| Poller | Device at an unroutable address | yes | n/a | yes | availability method 1, status transition | `poller/device-unreachable` | P0 |
| RRD | create and update argument capture | yes | yes | yes | pipe-mode stdin, DS and RRA definitions, template order | `poller/run-reachable` | P0 |
| Faults | RRD file deleted underneath a data source | yes | n/a | yes | rrdtool argv, exit status | `faults/missing-rrd-file` | P1 |
| Faults | CLI against an unreachable database | yes | n/a | yes | exit status, stdout | `faults/database-unreachable` | P1 |

## Not characterized

Ordered by priority. Nothing here has a golden file.

| Subsystem | Operation | Happy path | Failure path | Edge cases | Priority |
|---|---|---|---|---|---|
| Poller | Counter rollover, large integers, null result | no | no | numeric edges not yet driven | P0 |
| Poller | Malformed and partial SNMP responses | no | no | the snmpd fixture answers correctly only | P1 |
| Devices | SNMP v1, v3, IPv6, duplicate, bad credentials | no | no | per-version credential handling | P0 |
| Auth | Disabled account, expired session, realm denial | no | no | admin against non-admin | P0 |
| Auth | Permission enforcement per page and endpoint | no | no | representative matrix | P0 |
| Boost | Queue, batch, `LOAD DATA`, duplicates, retries | no | no | ordering, partial failure, empty batch | P1 |
| Upgrade | 1.2.x fixture upgraded forward | no | no | repeated and partial migration | P1 |
| Logging | Normalized log assertions per operation | no | no | severity, subsystem, ordering | P1 |
| Fault injection | SQL error, SNMP timeout | no | no | permission denied | P1 |
| UI | Pagination, sorting, filtering | no | no | across list pages | P1 |
| Templates | Import, export, apply, reapply | no | no | graph and host templates | P1 |
| Automation | Discovery, network rules, tree rules | no | no | — | P2 |
| CLI | The remaining 39 scripts | no | no | per-script exit status and side effects | P2 |
| Plugins | Failure and malformed return handling | no | no | hook that throws or returns wrong type | P2 |
| Threshold | Event behavior | no | no | requires a thold install; none seeded | P3 |

## Gaps worth naming

RRD is now characterized at create and update level. Cacti drives rrdtool in
pipe mode, so the commands arrive on stdin rather than in argv; the image shim
records both. Graph rendering is still asserted as a definition rather than as
pixels, which is deliberate.

Measured values are normalized out of the recorded rrdtool updates: a process
count, a load average and free memory differ on every run and are the machine,
not the behavior. The number of values and their template order are preserved,
because those are the contract.

Boost remains uncharacterized, as do templates, automation, and the remaining
39 CLI scripts. Authorization is characterized only for login and CSRF, not for
the per-page and per-endpoint permission matrix.

The PHP matrix has run on 8.2 only. Goldens are stored per version, so adding
8.1, 8.3, 8.4 and 8.5 is a capture per version rather than new scenarios, and
the deprecation counts in `api/php-errors` are expected to differ between them.
That difference is the point and must not be normalized away.

## Guards on the capture itself

Two scenarios once recorded a name rather than a behavior. `graphs/definition`
targeted a device that was never polled, so it captured "RRD file does not
exist" with an empty source while appearing to characterize graph generation.
`faults/database-unreachable` captured a shell quoting error from the harness
itself rather than the application's response.

Both now refuse to record: the graph capture raises unless the probe returns a
non-empty rrdtool source, and the database fault raises if the command failed to
start. A hollow contract is worse than a missing one, because every green run
makes it look more trustworthy.

## Diagnostic scopes and recording completeness

`api/php-errors` retains every event observed by the prepend recorder, including
suppressed events and its calibration warning. `diagnostics/visible-php-errors`
selects fatal events and events enabled by both recorded reporting masks.
Neither changes the application's error-reporting policy.

`include/global.php` replaces the prepend handler. The separate
`diagnostics/application-log` contract captures PHP diagnostics emitted by the
application's own handler, preserving subsystem, severity, message and duplicate multiplicity. Records
are sorted by subsystem and message to tolerate cross-process log interleaving while normalizing the log timestamp, known environment paths, PHP
diagnostic source-line locations (including backtrace frames), and failed-write
byte counts. Error numbers, diagnostic text and unrelated numeric values remain
part of the comparison.
A warning emitted after application bootstrap calibrates this path; recording
fails if it is missing. This is scoped PHP diagnostic coverage, not an assertion
that every possible application log message is covered.

A recording requires all 34 named scenarios. Empty, missing or unexpected
observations, missing selected scenarios and orphan golden files fail before
any golden is written. Failed runtime probes also leave an incomplete manifest.
The committed PHP 8.2 baseline was recaptured and reproduced against application
revision `6ce3572dab3264be563b765f25dcadd8cc046252` using the updated harness.
The durable `test/behavior-baseline-1.2.31` branch retains this application
revision; use this commit with the harness from the current test branch.
Two complete manifests and their comparison are retained under
`tests/behavior/evidence/historical-baseline/`. The first run recorded all 34 contracts; the second verified that recording
and produced identical scenario observations. A self-test compares both retained
manifests with every committed golden, including diagnostic ordering.
The historical refresh changes the calibration warning’s harness
line number (86 to 79), and removal of ten `config_settings` callbacks produced
by seeded network-discovery workers. It also separates RRDtool acknowledgement
counts in the four poller command contracts, as described below. Fixture setup
now disables that unrelated
discovery network before polling. The two diagnostic scopes are new. This is the documented
modified Kadupul baseline, not a claim of upstream parity. Other runtime baselines
need their own explicit capture and repeat run. Orphan checks inspect every
existing runtime directory for the selected target.

Poller command contracts retain exit status, stderr, and the exact order of
non-acknowledgement stdout lines. Complete RRDtool `OK u:... s:... r:...`
acknowledgements are counted separately: child writes can interleave with the
parent statistics line in either order. Missing acknowledgements still change
the contract. The four poller goldens explicitly adopt this representation;
other output and diagnostic records are not sorted or discarded.

The manifest `complete` flag means the evidence passed all completeness and
inventory validation, including the target's golden inventory across runtimes.
An orphaned golden is a validation failure even when every runtime probe ran.
Temporary candidate goldens are local comparison artifacts, not baseline inputs.

Failed `fwrite()` diagnostics normalize only the requested byte count to
`<BYTES>`: buffer length depends on which poller commands reach the broken pipe.
The errno, failure description, severity, source location, order and duplicate
records remain part of the contract. Other diagnostic numbers are preserved.


### Historical harness provenance

The retained manifests record the application revision separately from the
executing `harness_revision` and `harness_sha256`. `harness_inputs_sha256` hashes
the actual mounted behavior helpers, plugin/SNMP fixtures, compose file and
Dockerfile. Current captures also hash `.dockerignore` in each checkout. Dirty flags include untracked files; they are evidence, not a claim
that all captured working trees are clean.

The historical application revision predates the harness. For these two runs,
its tracked application files were unchanged and the test directories were
overlaid from the recorded harness commit. Thus `application_dirty` is true,
while the controller checkout has `harness_dirty: false`. All 34 observations
from the two fresh runs are identical. The retained `comparison.json` is the
unaltered output of the comparison command, with hashes of the exact retained
`first.json` and `repeat.json` manifests.

Comparison output records `contracts`, `controller` provenance,
`manifest_sha256` for baseline/candidate/repeat, and each capture's application
revision, schema hash, and provenance in `captures`. Missing provenance or build
input hashes are rejected. Older captures without these fields must be recaptured
with the current harness. Repeat runs must also match the candidate runtime and
provenance.

Comparison requires format 2, a target, a valid PHP version, pinned PHP/database
image references, package/runtime details, and no capture error. Both input hash
maps must contain every required helper, plugin/SNMP fixture, Dockerfile, compose
file and `.dockerignore`; the controller hash must agree with its file entry.
Bootstrap records final provenance after writing golden files, so a clean
checkout's first capture and its verification repeat report the same dirty state.
Failure to record that final state leaves the capture incomplete.

`application_images` records the content-addressed image IDs inspected from the
actual web, SNMP, and database containers. This covers all files Docker copied into each
application build, including dirty and untracked application files that are not
in the mounted-helper inventory. Candidate and repeat image IDs must agree even
when their Git revisions, dirty flags, and scenario observations are identical.
Different application images between baseline and candidate are expected; the
repeat check is what establishes that the candidate ran the same build twice.
A rebuild with changed layers or image configuration requires another repeat.
Missing, ambiguous, or invalid image identities leave a capture incomplete.
Format-1 captures cannot satisfy this contract and must be captured again; do not
add image IDs retrospectively to retained manifests.

To reproduce the historical runs, create separate clean checkouts of
`captures.baseline.revision` and
`captures.baseline.provenance.harness_revision` recorded in `comparison.json`.
Overlay the harness checkout's tracked `tests/Support/Behavior`,
`tests/Fixtures/plugins/compatibility_test`, `tests/Fixtures/snmp`,
`tests/behavior/compose.yml`, `tests/behavior/Dockerfile`, and
`tests/Golden/cacti-1.2.31` paths onto the application checkout. Keep its tracked
application files unchanged. Verify that `.dockerignore` is byte-identical in
both checkouts before building; it is a hashed build input. Stop if it differs
rather than changing the historical application. Run these commands from the clean controller:

```sh
cmp .dockerignore /path/to/application/.dockerignore || exit 1
mkdir -p /path/to/results/first /path/to/results/repeat
mise exec python@3.12.12 -- python tests/Support/Behavior/harness.py run \
  --application-root /path/to/application --target cacti-1.2.31 --update-golden
cp /path/to/application/tests/behavior/results/cacti-1.2.31/observations.json \
  /path/to/results/first/observations.json
mise exec python@3.12.12 -- python tests/Support/Behavior/harness.py run \
  --application-root /path/to/application --target cacti-1.2.31
cp /path/to/application/tests/behavior/results/cacti-1.2.31/observations.json \
  /path/to/results/repeat/observations.json
mise exec python@3.12.12 -- python tests/Support/Behavior/harness.py compare \
  --results-root /path/to/results --baseline first --candidate repeat \
  --repeat /path/to/results/first/observations.json \
  --output /path/to/results/comparison
```

The second run verifies the first run's captured golden observations. The first
capture serves as both baseline and repeat control in this historical self-comparison;
there are two independent captures, not three. Keep the
manifests and generated comparison together; no hand-edited provenance or
import-time root override is required. This establishes historical repeatability,
not a claim that a candidate application is equivalent or superior.

Before touching Docker, the runner requires a Git application checkout with
`cacti.sql` and a complete harness overlay matching the controller input hashes.
Both inventories remain recorded; mismatched mounted helpers or build inputs
fail setup. Baseline/candidate controller input changes require review. Application
diagnostics retain duplicates but sort records to tolerate process interleaving.

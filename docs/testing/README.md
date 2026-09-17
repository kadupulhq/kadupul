# Behavioral characterization harness

This harness records what Cacti 1.2.31 actually does, so a later Kadupul
rewrite can be checked against it. It is a specification captured by
observation, not a correctness suite. Where Cacti behaves oddly, the harness
records the oddity and marks it. It does not change it.

The working order is observe, capture, assert, preserve.

## Requirements

- Docker with Compose v2
- `mise`, providing Python 3.12
- GNU Make

Nothing else. The application, database, SNMP agent and RRDtool all run in
containers built from this checkout, so no local PHP, MariaDB or net-snmp
install is involved and no developer database is ever touched.

## Running it

```sh
make test-characterization    # verify observed behavior against committed goldens
make test-update-golden       # re-record goldens, then review the diff by hand
make test-keep                # leave the containers up to inspect a failure
make clean                    # drop results and stop stray compose projects
```

Scope the report to one group while still running the full pass:

```sh
make test-api
make test-plugins
make test-auth
make test-devices
make test-poller
```

Every scenario always executes, because later ones consume fixtures the earlier
ones create. The scoping flag decides what is verified, not what runs.

Pick a PHP version with `PHP_VERSION`. Goldens are stored per version, so a
capture on one version never overwrites another.

```sh
PHP_VERSION=8.3 make test-bootstrap-golden
```

## What a run does

1. Starts MariaDB, an Apache/PHP container built from this checkout, and an
   snmpd fixture that answers with fixed values.
2. Imports `cacti.sql` and records the fresh schema.
3. Runs `cli/install_cacti.php` and records its output and exit status.
4. Replaces the admin password and the tool paths, then records scenarios
   across auth, UI, CLI, devices, data sources, graphs, SNMP, plugins and PHP
   diagnostics.
5. Writes `tests/behavior/results/<target>/observations.json`, then compares
   each scenario against `tests/Golden/<target>/php-<version>/<name>.json`.

Containers are torn down afterwards unless `--keep` is passed.

## Golden files

Goldens are the compatibility contract. They never update as a side effect of
a normal run: missing scenarios fail the inventory check with
`Runtime goldens are missing observations`. Only explicit `make test-update-golden`
or `make test-bootstrap-golden` writes them; both refuse to
run scoped, so a partial capture cannot leave the rest stale.

Normalization is deliberately narrow. Filesystem roots become `<APP>` and
`<HARNESS>`, and the base URL becomes `<BASE>`. Known diagnostic log clocks and installer/poller timestamps are
normalized. Dates in database values, UI output and plugin messages are preserved. Identifiers, row counts, scalar types, ordering and message
text are all preserved, because a change in any of them is a behavioral change.

When a golden changes, read the diff. A legitimate change is approved
explicitly through the differential runner, never by re-recording silently.

## Differential runs

The point of the harness is comparing a baseline against a candidate.

```sh
make test-bootstrap-golden TARGET=cacti-1.2.31
make test-bootstrap-golden TARGET=kadupul
make compare BASELINE=cacti-1.2.31 CANDIDATE=kadupul
```

For captures produced with a separate application checkout, select its results directory:

```sh
make compare BASELINE=cacti-1.2.31 CANDIDATE=kadupul RESULTS_ROOT=/path/to/application/tests/behavior/results
```

The underlying `tests/bin/compare` accepts `--results-root` too. The default report is written into that directory; `--output` can select a different report prefix. A repeat manifest remains an explicit path passed with `--repeat`.

`make compare` writes `comparison.json` and `comparison.md` under the selected
results directory (by default `tests/behavior/results/`), classifying each scenario as `IDENTICAL`,
`INTENTIONAL_CHANGE`, `REGRESSION`, `NONDETERMINISTIC` or `NEEDS_REVIEW`.

A difference counts as intentional only when an approvals file names the
scenario, carries the digest of that exact baseline-candidate pair, and gives a
reason. Changing either side invalidates the approval.

```json
{
  "auth/login-invalid": {
    "digest": "<digest printed in comparison.json>",
    "reason": "Login error text reworded; see ADR-014."
  }
}
```

```sh
make compare BASELINE=cacti-1.2.31 CANDIDATE=kadupul APPROVALS=tests/Contracts/approvals.json
```

Pass `--repeat` a second candidate run to have scenarios that differ between
two runs of the same code classified `NONDETERMINISTIC` rather than blamed on
the candidate.

## Layout

| Path | Contents |
|---|---|
| `tests/Support/Behavior/harness.py` | Scenario driver. Standard library only, imports nothing from the application. |
| `tests/Support/Behavior/probe.php` | In-process adapter for behavior not reachable over HTTP or the CLI. A rewrite supplies its own. |
| `tests/Support/Behavior/errors.php` | PHP diagnostic capture. Chains to the handler it replaces. |
| `tests/Support/Behavior/inventory.py` | Regenerates the lexical surface inventory. |
| `tests/behavior/compose.yml` | Database, web, SNMP and poller services. |
| `tests/Fixtures/plugins/compatibility_test/` | Synthetic plugin exercising the public plugin API. |
| `tests/Fixtures/snmp/` | Deterministic snmpd configuration. |
| `tests/Golden/<target>/php-<version>/` | Recorded contracts. |
| `tests/behavior/results/` | Per-run observations and diffs. Not committed. |

## The probe boundary

Most scenarios go through HTTP or the CLI, which any implementation must
support. Some behavior, such as type coercion in helper functions and plugin
hook dispatch, has no external surface. Those go through `probe.php`.

`probe.php` is the one place coupled to Cacti's internal function names. A
Kadupul implementation supplies its own probe exposing the same observations.
The goldens stay unchanged. Nothing else in the harness names an internal
function, so replacing every class and function in the application leaves the
suite meaningful.

## Recording new behavior

1. Add a scenario in `Harness.scenarios()`, capturing both the application's
   response and the resulting database state. Add its exact name to
   `EXPECTED_SCENARIOS` in `harness.py` as part of the same change.
2. To add scenarios to an existing inventory, run
   `make test-bootstrap-golden TARGET=cacti PHP_VERSION=8.2`
   and read the new files. Use your intended target label in place of `cacti`.
   Repeat capture for every existing PHP runtime using its corresponding
   container configuration. Bootstrap permits missing entries during capture
   and writes only the current runtime; it still rejects orphaned scenarios.
   A bootstrap manifest remains incomplete and cannot be compared until every
   runtime inventory is complete. Normal verification also rejects an absent
   current-runtime directory.
   Use `make test-update-golden` for updates to an already complete inventory.
   If a new file contains a value
   that varies between runs, normalize it in `normalize()` or stop recording
   it. Do not normalize a value that carries meaning.
3. Run `make test-characterization` twice and confirm both pass, which is what
   distinguishes a stable contract from a flaky one.
4. Commit the scenario and its golden together.

If a scenario reveals behavior that looks wrong, keep it and mark it. The tags
used in this tree are `@legacy-behavior` for behavior that is odd but relied
upon, `@suspected-bug` for behavior that looks defective, `@security-behavior`
for anything security-sensitive, `@de-facto-api` for undocumented behavior real
plugins depend on, and `@compatibility-contract` for behavior a rewrite must
reproduce exactly.

## Known captured oddities

`sanitize_search_string(null)` reaches `preg_replace()` with a null subject and
emits a deprecation on PHP 8.1 and later, inside that function in `lib/functions.php`. The
harness records the deprecation rather than suppressing it.

Main has renamed Cacti to Kadupul in visible output, so six scenarios no longer
match these 1.2.31 goldens: `upgrade/install`, `cli/device-help`,
`auth/login-invalid`, `auth/missing-csrf`, `faults/database-unreachable` and
`graphs/definition`, whose default watermark still reads Cacti. A seventh,
`poller/rrd-failure`, differs because the warning it records moved from
`lib/rrd.php` line 334 to 327. The goldens stay the 1.2.31 contract, so a run
against main reports these seven until they are approved as intended changes.

`get_request_var()` memoizes each name into the `$_CACTI_REQUEST` global. Once
a name is read, later changes to `$_REQUEST` are ignored for the rest of the
request. Callers rely on this, so it is recorded as a contract.

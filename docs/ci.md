# CI architecture and modernization policy

Audit baseline: `ee000fd3ce5c10a872adb1d7bc7f20b30e7d20f4` on `main`.
This is a PHP 8.4 application migrating from Cacti to Symfony 7.4. Application
Composer dependencies live in `include/vendor`; PHPUnit 10 runs through
`composer test`. Pest 4 has its own locked `tests/composer.json` and vendor tree.
Node 22 builds compatibility assets and runs native JavaScript tests. Python 3.12
coordinates Docker, HTTP, database, browser, and coverage harnesses. `mise.toml`
selects local runtimes. `make test` means ordered behavioral characterization,
not an aggregate of every CI check. Other languages/frameworks are not assumed.

## Workflow audit

All existing third-party Actions have full SHA pins. Checkout credentials are
disabled for repository-owned source checkouts. The pinned organization release
workflow has an external checkout that retains credentials; changing that shared
workflow requires a separate review. Workflow defaults grant only `contents: read`.
Matrix jobs remain independent; database and CodeQL matrices retain their
existing `fail-fast: false` so compatibility failures are fully observed.

| Workflow | Events and purpose | Security, caches, artifacts, and dependencies |
| --- | --- | --- |
| `ci.yml` | Main pushes and all PRs; PHP boundary/syntax/tests, formatting, Node build/tests, gettext, Windows storage, six database versions, CDEF, installed application smoke, changelog | Read-only; disposable database credentials only. Node download cache in the JavaScript job. Smoke/CDEF retain observations. Independent jobs run in parallel. |
| `security.yml` | Main/PR/weekly/manual; Semgrep new-finding PR gate and dependency review | SARIF upload alone needs `security-events: write`; dependency-review comments need `pull-requests: write`. Pinned Semgrep image; failed report retained. No provider secret. Existing differential/full-scan behavior preserved. |
| `codeql.yml` | Main/PR/weekly/manual; public-repository JavaScript and Actions analysis | Job-scoped code-scanning permissions; PHP remains covered by Semgrep. |
| `snyk.yml` | Main/PR/weekly/manual; complete Composer/npm/Python dependency discovery | `SNYK_TOKEN` configuration job precedes secret-dependent scanning. Five-project completion guard and safe receipt retained. No policy relaxation. |
| `security-proof.yml` | Main/stack PR/manual; sink, architecture, entry-point and branding inventories | Read-only baselines; private advisory proof is manual, uses the job token, and retains artifacts. No baseline expansion. |
| `lint.yml` | Workflow/action changes and manual; actionlint and zizmor | Digest-pinned actionlint image and version-pinned zizmor; job token supports native metadata queries. |
| `theme-e2e.yml` | Main/stack PR; actual theme/browser and compiled-asset compatibility | npm E2E cache; browser-library checksum checks and failure artifacts retained. |
| `csp-e2e.yml` | Relevant PR paths; independent unit, HTTP, and Docker/browser CSP checks | npm E2E cache; cleanup and browser reports retained. No provider secrets. |
| `rrd-proxy-e2e.yml` | Relevant main/PR paths; real proxy settings, RRD I/O and template journeys | Pinned actions; browser failure artifacts. |
| `offline-bundle.yml` | Main/stack PR and reusable call; dependency-complete archive, installed authentication, network-isolated verification | Archive and SHA-256 artifact; no publishing permission. Reused by release. |
| `release-rehearsal.yml` | Main/PR; installed LTS upgrade, RRD preservation and rollback | Pinned upstream source; failure/complete observations retained. No publishing permission. |
| `release.yml` | Version tags; reusable offline build then organization release, then archive attachment | Pinned organization workflow. Publishing, package, OIDC and attestation permissions remain scoped to publishing jobs. Attachment depends on both release and bundle. |
| `claude-review.yml` | PR updates, authorized comments and manual review | `ANTHROPIC_API_KEY`; trusted-default-branch configuration; scoped review/comment write permissions. Existing review job concurrency retained. |
| `digitalocean-runner-smoke.yml` | Manual; single-job ephemeral runner contract | Read-only; ten-minute timeout. Does not provision or delete droplets. |
| `sonarcloud.yml` | Main/all PR/manual scheduling; selected complete multi-language coverage/analysis | Only scanner and credential preflight receive `SONAR_TOKEN`; npm and Composer download caches, full history, no cached vendor tree. Analysis has read-only contents/PR permissions. |

`DO_RUNNERS_ENABLED` selects the existing eligible DigitalOcean runner paths;
its current repository value is false. Fork jobs retain GitHub-hosted execution.
Runner provisioning and cleanup are outside this repository's workflow changes.

Missing job timeouts are now bounded: cheap configuration checks use 5–20
minutes; normal tests/guards use 20–60; complete Sonar coverage uses 180. Recent
full-coverage Unit stages alone took 45–86 minutes, so a short generic timeout
would reject legitimate work. Existing bounded integration jobs retain their
limits. Called release workflows own their internal limits; caller jobs cannot
set `timeout-minutes` on a reusable-workflow call.

Lint/theme PR updates cancel obsolete runs. Offline bundles cancel only obsolete
PR runs; tag/reusable release builds are not interrupted. Release concurrency is
per tag and never cancels a running publication. Existing Claude review
concurrency stays scoped to the reviewed PR; independent comment replies are
bounded but are not cancelled merely because another reply arrives.

## Required correctness and remaining opportunities

Recommended required checks are the existing applicable PHP/runtime, database,
CDEF, installed application smoke, JavaScript, formatting, gettext, workflow,
security inventory, Semgrep, dependency review, Snyk completion, and offline/
upgrade validation checks. Their actual names come from the workflow jobs and
matrix expansions, for example `PHP 8.4`, `JavaScript`, `Workflow files`,
`Composer and npm dependencies`, `bundle`, and `Upgrade, RRD preservation and rollback`.
Path-filtered optional E2E jobs should not be added as unconditional required
checks without an always-reporting wrapper. This change does not alter rulesets
or branch protections. At audit time the default ruleset requires a PR, but has
no required status checks; documentation recommendations are not enforcement.

Coverage executes some tests already present in correctness CI. This repetition
establishes physical coverage and subprocess provenance; cached historical
coverage cannot replace it. Both application and Pest Composer installs are
necessary because their PHPUnit versions differ. Separate browser, database,
CSP and installed/offline harnesses verify different environments; collapsing
these would lose evidence. Selective Sonar removes unnecessary whole-pipeline
repetition on normal development PRs without deleting these checks.

Composer download caching elsewhere and reusable setup steps are future
opportunities, not reasons to cache installed vendor trees or mix the two
framework dependency graphs. Python coverage requirements are version-pinned
but not hash-locked; container service tags and some setup runtimes remain
mutable. These pre-existing supply-chain gaps need their own supported-platform
review and lock updates. No security scan, dependency pin or test assertion was
weakened to simplify this change.

## Sonar execution and configuration

The existing provider is **SonarQube Cloud**, project `kadupulhq_kadupul`,
organization `kadupulhq`. Keep `sonar-project.properties`; no new identifiers or
host URL are needed. The required secret is the existing `SONAR_TOKEN`, scoped
to this project. Do not copy it into files, comments, artifacts, or logs.

Set repository variable **`ENABLE_SONAR=true`** to enable selection. Absent or
false intentionally skips analysis. It is absent at audit time; repository code
does not silently create variables. `Sonar analysis scope` runs cheaply and
reports the decision; ordinary CI also runs its regression tests even when
Sonar is disabled. No path filter suppresses the Sonar PR workflow.

| Context | With `ENABLE_SONAR=true` |
| --- | --- |
| Push to default branch (`main` today) | Full analysis |
| Same-repository PR source `sonar/*` | Full analysis |
| Same-repository PR source `release/*` | Full analysis |
| `feature/*`, `fix/*`, `refactor/*`, `chore/*`, `docs/*`, `experiment/*`, consolidation stacks | Intentional skip |
| Manual dispatch, `run_sonar=true` | Full analysis of the selected repository branch |
| Manual dispatch, `run_sonar=false` | Intentional skip |
| Fork PR or Dependabot actor (any event) | Secret-dependent analysis skipped; ordinary CI continues |
| Any context with `ENABLE_SONAR` absent/false | Analysis skipped |

The Dependabot actor restriction also applies to default-branch pushes and manual
dispatch; the full-analysis entries above require an eligible actor.

Push triggers retain the discovered default branch `main`; update that trigger
if the default branch is renamed. Prefix selection follows GitHub's case-insensitive [`startsWith` semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/expressions#startswith).
Selection verifies the event's default branch
and PR **source** ref, not the PR base or synthetic `refs/pull/.../merge` ref.
Manual dispatch must be available on the default branch before GitHub offers
it; select the desired repository branch in Actions → SonarQube Cloud → Run
workflow. There is no arbitrary checkout-ref input and no `pull_request_target`.
Same-repository branches are the existing trusted contributor boundary. Fork
code never gets a provider token through this integration.

Selected runs check for missing `SONAR_TOKEN` before installing dependencies.
A missing token fails with an understandable configuration error. Scanner,
authentication, coverage, and actual quality-gate failures remain visible:
`sonar.qualitygate.wait=true` waits up to ten minutes for the backend result.
There is no `continue-on-error`. These analysis checks are **selective and not
recommended as required merge checks during modernization**; a failure is still
reported and investigated, rather than relabeled as success.

Clover reports cover PHP; LCOV combines native Node and actual browser execution;
Python coverage covers the offline builder. Every existing strict subprocess
registration, completion marker, report-completeness check, scenario and source
hash remains intact. Missing coverage stops the job before the scanner. Coverage
reports are generated by existing workflow commands; do not substitute a partial
local test result for the complete hosted pipeline. Generated/vendor exclusions,
profile rules and quality thresholds are unchanged.

For failures, inspect `Sonar analysis scope` first, then credential preflight,
the first failing coverage stage, scanner authentication/configuration, and the
matching backend analysis revision. A skipped run is not a passing gate. An old
PR or main gate is not evidence for the current head. Already-running analyses
continue under the workflow revision that started them.

## Promote Sonar later

When replacement code and coverage stabilize, set `ENABLE_SONAR=true` and
**`SONAR_ALL_PRS=true`**. The latter admits every trusted PR source branch and
activates the aggregate check named exactly **`Sonar Quality Gate`**. This check
requires both successful scheduling and successful completed analysis; disabled,
skipped, cancelled, credential-failed, test-failed, scanner-failed or backend-
failed analysis cannot satisfy it. There is no workflow redesign or threshold
change. Before adding it to a default-branch ruleset's required status checks,
verify a fresh successful run and retain all existing correctness/security
requirements. Only an authorized maintainer changes those rules.

Forks remain secret-ineligible. In strict mode their aggregate gate fails closed;
requiring this check therefore holds fork PRs until maintainers establish an
approved safe analysis path. Do not enable required enforcement for fork merges
by switching to `pull_request_target`, copying secrets into fork jobs, or treating
a manual run on another revision as a same-head gate. Manual dispatch with
`run_sonar=false` also cannot pass strict enforcement. Leaving `SONAR_ALL_PRS`
false/absent keeps the aggregate intentionally skipped during modernization.

# PHP Sonar analysis

The `kadupulhq_kadupul` and `kadupulhq_kadupul-lts` projects use the
project-specific **Kadupul PHP safety and conventions** quality profile.
It combines Sonar way core with all 20 rules from the previous PSR-2 profile
(116 active rules when configured). The organization default remains unchanged.

Function naming rule `php:S100` uses `^_*[a-z][a-zA-Z0-9_]*$` to accept
the documented snake_case procedural helpers and existing camelCase helpers.
No reliability or security rule is disabled for naming compatibility.

After changing a profile, analyze the base branch and the PR again. A result
from the former style-only profile does not demonstrate that the core safety
rules passed. Record newly exposed pre-existing issues separately from changes
introduced by a PR. Inspect taint flows and caller authorization before
classifying a security finding; a passing style gate is insufficient evidence.

Credentials come from runtime secrets or Keychain, never this repository.

## Native coverage composition

The legacy and application jobs use their existing locked coverage libraries
(12 and 10 respectively). Each merger exports measured line data and original
test identities to a mandatory `.native.json` sidecar before writing Clover.
The sidecars retain every filtered source, including unexecuted files. Artifact
receipts seal them alongside the original reports at the same source SHA/tree.

After receipt verification, the analysis job installs the existing locked
dependencies and uses the tests library's native `ProcessedCodeCoverageData`
merge API to compose both producers. Source hashes, dependency locks, producer
library fingerprints and tooling hashes must match the checkout. Missing or
stale sidecars, unsupported branch coverage, unbound hits and conflicting test
identities fail the job. No foreign PHP serialization crosses library versions.

Only then does Clover expand uncovered files once. Sonar and the unchanged 100%
changed-line gate consume `var/native-composed-coverage.xml`. This avoids treating
different static parsers' structural-line classifications as independent
unexecuted statements. The original Clover artifacts remain available; they
are not edited or used to fabricate execution data.

## Analysis scheduling

Sonar is selective during modernization. With `ENABLE_SONAR=true`, full analysis
runs on main pushes, same-repository `sonar/*` and `release/*` PR sources, and
manual dispatch with `run_sonar=true`. Normal development and consolidation
branches skip it. Fork and Dependabot PRs retain ordinary CI without provider
secrets. Disabled analysis is an intentional skip, never a passing quality gate.

The existing complete coverage pipeline, source registrations, exclusions and
profile are unchanged. Requested analyses fail visibly on missing credentials,
coverage failures, scanner failures and backend quality-gate failures. The
standalone content classifier remains available for diagnostics; its prose-only
result does not override an explicitly selected branch or manual analysis.

See [the CI architecture and configuration guide](../ci.md) for the workflow
audit, execution matrix, secret setup, fork limitations, and the future
`SONAR_ALL_PRS` / `Sonar Quality Gate` enforcement path. No branch protection is
changed automatically. Existing running analyses keep their original revision
and applicability; this policy does not turn an old result into current proof.

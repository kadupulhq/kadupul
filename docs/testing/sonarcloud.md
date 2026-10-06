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

## Analysis scheduling

The workflow always runs the inexpensive **Sonar analysis scope** check on
eligible pull-request updates and `main` pushes. It compares the complete tested
merge tree against the event's base commit, or the before/after trees for a main
push. A documentation commit after a code commit does not hide the earlier code
change in that pull request.

Only ordinary, non-executable Markdown in the explicit `ORDINARY_DOCUMENTS`
allowlist can skip the full coverage/analysis job: the root `README.md`,
`CHANGELOG.md`, and `CONTRIBUTING.md`, plus reviewed prose guides named in
`tests/security/sonar_change_scope.py`. New or unrecognized Markdown requires
full analysis until its consumers and provenance have been reviewed.
Verification provenance, generated evidence, fixtures, schema, rendered HTML,
source, tests, dependencies, configuration and CI inputs remain applicable.
Renames involving those inputs, symlink/executable changes, empty diffs,
unavailable history, unexpected checkouts and unrecognized events require full
analysis. This policy changes no Sonar exclusions, profile, thresholds, test
selection or coverage completeness checks.

A documentation-only decision means **not applicable**, not a new passing Sonar
quality gate. No old analysis or coverage is relabeled as current evidence. All
other applicable CI and review rules still apply; relevant changes need the
current revision's full analysis. Classification failures block the workflow.

Keep related local fixes in commits until their regression tests and separate
final review are complete, then publish the finished batch. Preserve independent
PR boundaries and dependency order. Avoid repeated intermediate pushes and
unchanged-head reruns without a specific new diagnostic reason. Already-running
relevant analyses remain useful and must not be skipped merely to speed a merge.

# OpenSSF adoption and verification

Kadupul uses [OpenSSF Scorecard](https://github.com/ossf/scorecard) to assess
repository practices. A score is evidence about those practices; it is not a
security certification or proof that the application has no vulnerabilities.

## Enforced repository controls

On 2026-10-08, GitHub's native API confirmed these settings:

| Control | Enforcement | Verification |
| --- | --- | --- |
| Reviewed changes | `Default Protections` covers main and `lts/*`; one approval, stale approvals dismissed, last push approved, conversations resolved | Ruleset `22963667` |
| Protected history | Force pushes and deletion prohibited; no ruleset bypass actors | Ruleset `22963667` |
| Current main validation | PRs must be tested against current main; checks originate from GitHub Actions | Ruleset `24757532` |
| Workflow dependency identity | Actions require full commit SHA pins | Repository Actions permissions API |
| Least privilege | Default workflow token is read-only and cannot approve PRs | Repository workflow permissions API |
| Secret protection | Secret scanning and push protection enabled | Repository security settings API |
| Dependency and code analysis | Dependabot, dependency review, Semgrep and CodeQL configured | `.github/dependabot.yml` and workflow definitions |

The required main checks are `PHP 8.4`, `JavaScript`,
`Gettext source and compiled catalogs`, `Semgrep`,
`Dependency review`, and `Code style`. These checks run for all
pull requests, without changed-path filters. LTS retains its separate test
contracts; main-only check names are not required on LTS.

Snyk additionally scans the installed Composer, npm and Python projects on
trusted PRs with its configured secret. It can skip fork PRs, so that
secret-dependent job is not used as a required check for every main PR.

One independent reviewer must approve future changes. Automation and a PR
author cannot supply that approval. Administrative controls were applied
through GitHub's API; merging this document does not apply them to another fork.

## Assessment and remaining work

The published assessment for commit
`2416a594bb7b800cc92c390b57d1aec51e6cb0dd` scored **6.3/10** on 2026-10-08,
before the control changes above. The existing Scorecard workflow runs on main
pushes, weekly, and on request; its SARIF assessment is also retained as an
Actions artifact. A fresh run after the settings change published **6.8/10**
at `2026-10-09T00:05:17Z` for the same source commit. Branch protection increased
from 3/10 to 8/10. Check the report's source SHA and date before comparing scores.

| Requirement | Status | Remaining acceptance evidence |
| --- | --- | --- |
| New protection settings | verified | GitHub API readback on 2026-10-08 and subsequent published Scorecard assessment |
| Code review history | planned | Accumulate independently approved changes; new settings cannot retroactively approve old commits |
| Continuous fuzzing | planned | Introduce a reproducible fuzz target, corpus, crash triage and continuous runner; ordinary unit tests are not fuzzing |
| OpenSSF Best Practices badge | planned | Complete the project's assessment with evidence and obtain the badge; no badge is claimed |
| Signed release artifacts | planned | Verify provenance/signatures with the first supported release |
| Maintenance history | not assessed | Scorecard reports that this project is younger than 90 days; inherited contributors alone do not establish ongoing maintenance |

Some checks returned unavailable (`-1`), including workflow/token analysis and
packaging. Unavailable results are not passing findings. Investigate later
reports rather than treating the aggregate score as complete coverage.

## Rechecking and recovery

Inspect repository settings using GitHub's native APIs:

```sh
gh api repos/kadupulhq/kadupul/rulesets/22963667
gh api repos/kadupulhq/kadupul/rulesets/24757532
gh api repos/kadupulhq/kadupul/actions/permissions
gh api repos/kadupulhq/kadupul/actions/permissions/workflow
gh api repos/kadupulhq/kadupul --jq '.security_and_analysis'
```

Fetch the published assessment from
<https://api.scorecard.dev/projects/github.com/kadupulhq/kadupul> or inspect
the Scorecard workflow's artifact and code-scanning results. Re-run analysis
after repository settings change.

Before changing required checks, verify that they run on every applicable PR.
Rename requirements with their producer workflows to avoid blocking merges on
a check that can never run. Restore prior settings only through an authorized,
reviewed GitHub administration change; do not bypass protections to merge a PR.

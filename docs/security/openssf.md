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
| Continuous fuzzing | implemented | `fuzz.yml` runs fast-check against the shipped vendor asset synchronizer on PRs, main pushes and weekly; 2,000 generated cases per property, shrinking and replay details retained |
| OpenSSF Best Practices badge | pending enrollment | Complete the [evidence checklist](openssf-best-practices.md) in a maintainer's authenticated badge account; no badge is claimed |
| Signed release artifacts | implemented; release evidence pending | The offline archive is checksummed, attested and uploaded with its Sigstore bundle; verify the first published release before claiming signed release history |
| Maintenance history | not assessed | Scorecard reports that this project is younger than 90 days; inherited contributors alone do not establish ongoing maintenance |

The published assessment used archive file mode. `.gitattributes` deliberately
excludes `.github` and `tests` from source archives, so workflow/token analysis
was unavailable and dependency analysis omitted test images. The Scorecard
workflow now uses `file_mode: git`, preserving release archive exclusions while
assessing the complete tracked tree.

Full-tree validation with Scorecard 5.5.0 confirms 10/10 for Dangerous-Workflow,
Token-Permissions, Pinned-Dependencies and Fuzzing on the updated source. These
are targeted checks, not an updated published aggregate score. Every selectable
PHP 8.1–8.4 harness base and the three fixed PHP test images use verified
multi-platform registry digests. Harness manifests obtain the PHP base identity
from the running container's inherited label; a floating tag cannot supply it.
The upgrade rehearsal verifies the archived baseline recipe, pins its owned
execution copy to the selected runtime, and records both archived and executed
recipe hashes. The historical archive remains unchanged. The provenance change
can require a reviewed baseline recapture. No goldens
were automatically updated.

Packaging remains unavailable: the detector does not recognize the pinned
reusable release workflow, which already publishes an archive and GHCR image.
Signed-Releases remains unavailable until a release exists. Maintained is zero
because the repository is younger than 90 days; Code-Review needs independent
review history. Branch-Protection is 8/10: two approvals and mandatory code-owner
review need additional eligible maintainers; only the author account was listed
as a collaborator during this audit. Existing protections remain enforced.

Unavailable results are not passing findings. Reassess after these changes merge
and verify the report's source SHA before comparing aggregate scores.

## Fuzzing and release verification

Install the locked development dependencies and run the property fuzzer:

```sh
mise exec node@22.22.2 -- npm ci --ignore-scripts
FUZZ_RUNS=2000 mise exec node@22.22.2 -- npm run test:fuzz
```

It exercises checksum rejection at every batch position, arbitrary binary
inputs, LF conversion and literal patch replacement through the real
`syncAssets` function. Downloads and writes use owned memory fixtures. This is
property fuzzing of the supply chain boundary; it does not fuzz the entire PHP
application. Each run records its seed. Failures include a shrunk counterexample
and replay path; rerun the named failing test with `FUZZ_SEED` and `FUZZ_PATH`,
then add a permanent regression before fixing the defect.

Download the release archive, checksum and `.sigstore.json` bundle together.
Verify the signed provenance against this repository and workflow, not merely
the unsigned checksum:

```sh
sha256sum --check kadupul-offline.tar.gz.sha256
gh attestation verify kadupul-offline.tar.gz --repo kadupulhq/kadupul \
  --bundle kadupul-offline.tar.gz.sigstore.json \
  --signer-workflow kadupulhq/kadupul/.github/workflows/release.yml
```

The workflow attests only after checksum validation succeeds and uploads only
after attestation succeeds. Actual keyless signing requires GitHub's release
job identity; no release was fabricated to improve a Scorecard result.

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

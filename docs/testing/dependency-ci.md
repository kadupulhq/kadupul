# Dependency maintenance and CI

The default branch's `.github/dependabot.yml` is the active configuration for
both main and `lts/1.2`. It covers the two Composer projects, the root and E2E
npm projects, Python coverage tooling, GitHub Actions, and every tracked
Dockerfile directory. LTS entries cover only manifests actually present there.

Default-branch entries omit `target-branch`: GitHub applies their options to
security updates as well as version updates. Security updates have separate
groups and bypass the seven-day version-update cooldown. Routine minor and
patch updates are grouped per ecosystem; major updates remain individual PRs.
Five open version-update PRs per entry limit review and CI load without
limiting security-update PRs. The live maintenance branch is `lts/1.2`, not
the historical `1.2.x` name. GitHub security updates target the default branch;
maintainers must still assess and backport applicable security fixes to LTS.

Docker runtime families and named PHP harness stages are compatibility
contracts. Dependabot refreshes pinned digests and permits patch updates;
major/minor runtime-family changes need deliberate configuration changes and
the corresponding compatibility checks. Other manifest ecosystems do not
ignore major updates. SHA-pinned Actions remain pinned when Dependabot updates
them. No automatic merge or review bypass is configured for dependency PRs.

CI restores Composer package downloads and npm downloads, then still performs
locked installs and builds on each tested revision. It does not cache installed
vendor trees, node_modules, application outputs, credentials or acceptance
results. Cache misses are normal and never skip installation. Composer download
caches use OS, architecture and both lockfiles; their fallback restores package
downloads only. npm cache keys include every lockfile used by the job.

Superseded PR fuzz runs are cancelled; release publication remains
non-cancellable. Scorecard assessments are serialized and bounded by a timeout.
Diagnostic and intermediate artifacts expire after 14 days. Published GitHub
release assets have a separate lifecycle. Docker harnesses select digest-pinned
base images themselves; redundant pulls of a floating PHP tag are unnecessary.

Required check names, supported database/runtime matrices, security scans,
authentication backends and release/rollback acceptance remain intact. These
changes reduce repeated downloads and artifact retention; no measured runner
minute reduction is claimed before observing comparable CI runs.

Validate workflow syntax and policy with `actionlint` and the pinned CI zizmor
command. Workflow lint also validates the Dependabot configuration, including
its schema and cooldown settings. GitHub's update jobs remain authoritative for
dependency resolution; inspect their logs and proposed diffs before merging.

## Authenticated container mirrors

Docker builds, Compose fixtures and workflow services use the repository's
`ghcr.io/kadupulhq/kadupul-mirrors` packages. Every reference retains its reviewed
upstream SHA-256 digest. The 19 admitted image manifests are recorded in
`tools/containers/mirrors.json`; runtime families and database health checks are
unchanged. CI no longer changes Docker daemon mirror settings or depends on a
public registry during application builds and tests.

The Container mirrors workflow copies all platforms with Skopeo's
`--all --preserve-digests`, then hashes the manifest fetched from GHCR and
requires the original digest. Only this publisher needs `packages: write`.
It requires a successful copy from Google's public cache, ECR's official-image
mirror or Docker Hub for the same pinned identity, and records the exact
`copied_from` source in the receipt. Existing GHCR bytes cannot replace that
upstream provenance check. If no admitted upstream serves the digest, or the
manifest differs, publication fails even when GHCR already holds the image.
Application and test pulls of already published mirrors remain available. Verified receipts
expire after 14 days; GHCR images have a separate lifecycle.

Consumer jobs grant `packages: read` and pass their short-lived `GITHUB_TOKEN`
to `tests/tools/login_ghcr.sh` through the environment. The helper sends the
password through standard input and fails before invoking Docker if credentials
are missing. Job services use GitHub's native `services.credentials` because
GitHub pulls them before workflow steps. The release caller grants the reusable
offline workflow read access; the pinned shared image release workflow already
logs into GHCR before starting its mirrored BuildKit daemon and uses the
mirrored SBOM generator. Both helper images are digest-pinned. A disposable
amd64/arm64 build verifies generated SPDX attestations without publishing a
release. The caller sets `hosted-runners-only: true`, keeping every shared release
job on `ubuntu-latest` even if `DO_RUNNERS_ENABLED` is enabled later. This
preserves the earlier hosted release boundary for package-write and OIDC
credentials. No personal token is stored in repository files or workflow secrets.

For local builds, provide `GHCR_USERNAME` and a classic token with
`read:packages` as `GHCR_TOKEN` through your existing secret manager or shell
environment, then run `bash tests/tools/login_ghcr.sh` before Docker or Compose.
Do not paste tokens into commands, documentation or logs. Docker retains the
credential through its configured credential store; use `docker logout ghcr.io`
to remove it when finished. The existing GitHub CLI login does not necessarily
include package access.

Publishing through the workflow token links the packages to this repository.
The workflow tests every admitted image with a separate read-only job token and
checks the actual pulled repository digest. Packages keep GitHub's default
private visibility. Other repositories and contributors need explicit package
read access; fork-specific access has not been verified. Dependabot uses the
same repository package-access grant automatically, without a registry token in
`dependabot.yml`. See GitHub's [automatic registry access documentation](https://docs.github.com/en/code-security/concepts/supply-chain-security/automatic-dependabot-access-to-github-registries)
and [Container registry authentication](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).

To refresh an image, review its upstream digest and add or update the admitted
inventory entry. Run Container mirrors on that trusted branch and require every
publication and read-only pull to pass before changing consumers. On main, the
workflow runs automatically when the inventory or publisher changes; it can
also be dispatched manually. Version-family tags are published only after their
admitted digest is verified, so Dependabot can propose mirrored digest updates.
The mirror does not automatically track unreviewed upstream tags. Preserve old
inventory entries while consumers still need their digests. Roll back reference
changes by reverting the consumer commit; never delete a package digest still
referenced by supported revisions. This work changes main only; LTS keeps its
existing registry configuration.

Actionlint 1.7.12 and Semgrep 1.179.0 run as native, pinned tools. The scanner
lock includes patched PyJWT 2.15.1 and uses mise's Python 3.12.12 with
`tests/tools/requirements-semgrep.txt`. Regenerate the lock from
`requirements-semgrep.in` using pip-tools 7.5.1 with
`--generate-hashes --allow-unsafe --strip-extras`. The CSP stack uses the native
Docker builder. Assertions and required check names remain unchanged.

The MCP SDK update is blocked as of 2026-10-19, tracked in
[#950](https://github.com/kadupulhq/kadupul/issues/950). The lock contains
`mcp==1.29.0`, affected by
[GHSA-84m7-p3x7-pcfv](https://github.com/modelcontextprotocol/python-sdk/security/advisories/GHSA-84m7-p3x7-pcfv).
The patched 1.x SDK starts at 1.30.0, but Semgrep 1.179.0 requires exactly
1.29.0. Published Semgrep 1.180.0 and 1.181.0 retain that pin. An isolated
Python 3.12.12 pip dry run rejected Semgrep 1.179.0 plus MCP 1.30.0 with
`ResolutionImpossible`. [Upstream #11992](https://github.com/semgrep/semgrep/issues/11992)
tracks the dependency update. The lock and Dependabot alert 17 remain unchanged;
this assessment is not remediation.

The security workflow runs `semgrep scan`; it does not start the stateful MCP
Streamable HTTP server described by the advisory. This limits the demonstrated
CI exposure, without removing the affected installed package. Do not force the
SDK upgrade past Semgrep's declared dependency or weaken hash verification.
After a compatible scanner release is published, regenerate the lock with the
versions and flags above, review every changed version and hash, and require a
clean hash-checked install, `pip check`, scanner version check, real scan/report
verification and current-head CI. Confirm that Dependabot marks alert 17 fixed
after the replacement lock reaches main. LTS was not assessed for this advisory.

## Coverage and analysis

Sonar coverage runs in two independent jobs: legacy unit/poller coverage, and
Symfony/HTTP/offline/browser coverage. Both keep their complete test suites,
coverage self-tests and production-source checks. The final scanner waits for
both jobs to succeed; neither producer uploads a partial run.

The serial run on [PR #829](https://github.com/kadupulhq/kadupul/actions/runs/37916951018)
completed all tests but reached its 180-minute timeout just as scanning began.
Legacy unit coverage alone took 128 minutes on that runner. Parallel producers
remove the application coverage phase from that critical path. This is a workflow
change; it does not relax test assertions or claim a measured speedup before the
replacement run completes.

Artifacts contain only the five final XML/LCOV reports and receipts binding
their checksums to the exact checkout commit and tree. The consumer checks the
complete report sets before remapping source paths between runner workspaces.
It rejects stale revisions, changed reports, empty coverage and foreign paths.
Artifacts expire after 14 days and are downloaded by immutable IDs. Attempt-specific
names support a full rerun; GitHub's **Re-run failed jobs** can reuse successful
producer artifacts when only verification or scanning failed.

The legacy producer has a 180-minute budget, the application producer 90 minutes,
and the scanner 15 minutes. Stale PR runs still cancel. A missing or failed
producer prevents analysis and cannot supply a passing quality gate.

Runtime whitelist cases import actual child-process PCOV coverage through the
existing source/scenario/checksum/completion receipt checks. Their behavioral
assertions also run without coverage; missing or stale child evidence fails a
coverage run. The upstream pager input remains byte-identical, with its MIT
license, at `include/js/jquery.tablesorter.pager.source.js`. The builder verifies
its manifest checksum before applying the reviewed compatibility patches. This
keeps upstream vendor input in the existing vendor tree and project-owned build
and compatibility code in the measured source set.

References: [GitHub Dependabot options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
and [security-update configuration](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/configure-security-updates).

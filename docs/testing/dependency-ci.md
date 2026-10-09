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

References: [GitHub Dependabot options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
and [security-update configuration](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/configure-security-updates).

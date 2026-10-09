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

## Public image pulls

Hosted CI hit Docker Hub HTTP 429 limits during image builds, service startup
and scanner pulls, including retries. Linux jobs that use Docker configure
Google's [public Docker Hub cache](https://docs.cloud.google.com/artifact-registry/docs/pull-cached-dockerhub-images)
before building or pulling. Original image references and digest verification
remain intact; cache misses use Docker Hub. The configuration is validated and
the daemon [reloads registry mirrors with SIGHUP](https://docs.docker.com/reference/cli/dockerd/#configuration-reload-behavior)
without restarting running services. Self-hosted and local daemons are unchanged.

GitHub starts job services before steps can configure that cache. Database
services therefore use the Docker Official Images published at
`public.ecr.aws/docker/library`, with the same matrix version families and
unchanged health checks. The service manifests are pinned by digest, including
the Compose database and Nginx fixtures. All six MariaDB/MySQL matrix manifests
and Nginx were verified by immutable lookup and Linux/amd64 availability before
switching. Runtime database provenance reads the actual running container's
image, avoiding a second lookup of a floating tag.

Actionlint 1.7.12 and Semgrep 1.176.0 run as native, pinned tools instead of pulling
uncached tool images. The Semgrep version matches the previous pinned image;
installation asserts the executable's version before preserving the same scan
options. Its isolated environment uses mise's Python 3.12.12 and the hash-locked
`tests/tools/requirements-semgrep.txt`, covered by the existing Dependabot pip
entry. Regenerate that lock from `requirements-semgrep.in` under Python 3.12.12
using pip-tools 7.5.1 with `--generate-hashes --allow-unsafe --strip-extras`.
The CSP stack uses
the native Docker builder instead of pulling a separate BuildKit container.
No registry credentials are required for these public pulls; scanners,
assertions and required check names remain unchanged.

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

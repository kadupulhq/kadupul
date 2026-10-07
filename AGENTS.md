# Agent instructions

## GitHub metadata

- Always add appropriate metadata when creating or updating pull requests and bug
  issues. Use existing labels for the target branch, affected technology, and
  change type; bug issues must include `bug`.
- Add applicable existing milestones and projects, and link related issues or
  pull requests. Do not invent milestones, project membership, or ownership.
- Main migration work uses `branch:main`; leave LTS unchanged unless explicitly
  requested.

## GitHub review feedback

Always address pull-request feedback using GitHub's native review workflow,
including feedback from people, Copilot, Sonar, and other automated checks.

- Read the current review threads, review summaries, and check results before
  making changes. Investigate findings rather than accepting or dismissing them
  automatically.
- For valid findings, add focused regression coverage where practical, implement
  the fix, run relevant checks, and push the fix to the PR branch.
- Reply in the original review thread with the fixing commit and verification
  evidence. For feedback without a thread, comment on the PR and link the finding
  or failed check. A chat summary is not a substitute for a GitHub response.
- Resolve a review thread only after its fix is pushed and verified, or after
  providing concrete evidence that the finding is stale or inapplicable. Leave
  uncertain findings open and ask a focused question in the thread. Never
  blanket-resolve threads or dismiss scanner alerts merely to obtain green checks.
- Let new scans confirm scanner fixes. If a false-positive dismissal is warranted,
  record the specific rationale in the scanner's native review mechanism when
  available; do not treat resolving a PR conversation as resolving a scan alert.
- Request re-review through GitHub's reviewer controls/API after addressing the
  feedback. Recheck new reviews and CI on the latest commit and iterate as needed.
- Merge only when authorized, required approvals are satisfied, and applicable CI
  and quality gates pass on the latest head. Do not bypass protections or
  self-approve. Report pending reviews, failed checks, and remaining findings
  honestly.

## Current main architecture and verification

Main combines Symfony 7.4 services in `src/`, routes/configuration in `config/`,
and browser entry points in `public/` with legacy PHP web, poller and CLI
contracts. Preserve adapter/compatibility boundaries during migration; a modern
service's presence does not authorize deleting its legacy counterpart.
`mise.toml` pins PHP, Node and Python. Never merge Cacti upstream wholesale into
main; inspect/cherry-pick only relevant compatible changes. LTS is a separate
maintenance boundary and is unchanged unless the task names it.

Run `mise exec -- composer install` for locked PHP dependencies,
`mise exec -- composer test` for the root Symfony test suite, and
`mise exec -- npm ci`, `mise exec -- npm test`, `mise exec -- npm run build`
for managed frontend assets. Install/test operations can rebuild generated
assets; do not stage dependency trees or incidental output. Read `Makefile`
before behavioral tests: `mise exec -- make test-harness-selftest` tests the
harness; `make test-characterization` needs disposable Docker/database fixtures.
Review golden changes explicitly rather than regenerating them to hide drift.

Preserve session/resource authorization, remote-poller scope, database/RRD
integrity, backups and TLS. Never run installers, migrations, pollers or restores
against production during routine checks. Local source scans and generated
framework/configuration assets do not prove runtime acceptance. Keep existing
review-thread, DCO, formatting-migration and required-check rules intact.

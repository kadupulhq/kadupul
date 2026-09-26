# Contributing

Discuss substantial changes in the issue tracker before implementation. Keep each
pull request focused on one purpose and target the Kadupul repository.

## Changes and validation

Match the surrounding code and repository formatting rules. Preserve public
interfaces unless a change includes a documented migration. Avoid unrelated edits.

On `main`, PHP files move to PHP-FIG PER-CS 2.0 one file at a time. A file that
is already PER-CS formatted and every new PHP file outside `include/vendor` and `tests/Fixtures` must stay formatted. A small
change to a file that is not converted yet may keep its current formatting;
converting a file is its own whitespace-only change, which must convert it
completely. `tests/tools/check_php_style.sh` applies these rules. The `lts/1.2`
branch keeps upstream Cacti formatting.

Reproduce a bug before fixing it, verify the changed behavior, and include the
validation commands and results in the pull request.

### Local Git hooks

Enable the repository-owned hooks once per clone:

```sh
.githooks/install
```

The pre-commit hook validates staged content only: whitespace errors, PHP
syntax under PHP 8.4.25 selected through `mise`, and the same PER-CS migration
policy used by CI. It does not rewrite the index or working tree. During a
merge it ignores files copied unchanged from the incoming parent and checks
only locally authored or conflict-resolved content.

The commit-message hook enforces Conventional Commit subjects and a DCO
`Signed-off-by` trailer. Use `git commit -s`; merge commits are exempt because
Git generates their messages and the merged commits retain their attestations.

Install php-cs-fixer 3.95.27 on `PATH`, or set `PHP_CS_FIXER` to that pinned
executable. GitHub CI remains authoritative and must pass before merge. Local
AI-review tools are intentionally not required by these hooks.

## Commits

Use Conventional Commits and sign off every commit with `git commit -s` under the
[Developer Certificate of Origin](https://developercertificate.org/).

## GitHub workflow

Use GitHub's documented workflow and this repository's conventions for issues,
pull requests, reviews, discussions, releases, and project boards. Check the
contribution guide, templates, labels, branch protections, code-owner rules,
and release guidance before changing GitHub records. Use GitHub's normal UI,
CLI, or API paths so changes retain their review and audit trail.

Make issues and pull requests actionable and fully described. Use a specific
title and the applicable template; include the affected branch or release,
reproduction steps and expected versus actual behavior for bugs, verification
evidence for code changes, and links to related issues and pull requests. Apply
existing labels that accurately describe type, subsystem, security relevance,
and target branch. Preserve valid metadata; do not guess assignees, milestones,
projects, or release commitments.

Review the current diff, head commit, checks, and review threads before acting
on a pull request. Address actionable feedback with evidence and request
re-review after changes. Merge only when current-head checks pass, actionable
threads are resolved, and required independent approvals and repository rules
are satisfied. Do not bypass protections or self-approve. Use least-privilege
permissions and never put secrets in GitHub records, logs, or commits.

Before handing off or merging a GitHub change, verify its title, description,
labels, issue links, head commit, checks, review state, and thread-resolution
state using GitHub's records.

## Documentation and security

Verify documentation against the implementation and use material whose license
permits its inclusion. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

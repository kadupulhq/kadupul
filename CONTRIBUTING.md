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

Install php-cs-fixer 3.95.25 on `PATH`, or set `PHP_CS_FIXER` to that pinned
executable. GitHub CI remains authoritative and must pass before merge. Local
AI-review tools are intentionally not required by these hooks.

## Commits

Use Conventional Commits and sign off every commit with `git commit -s` under the
[Developer Certificate of Origin](https://developercertificate.org/).

## Documentation and security

Verify documentation against the implementation and use material whose license
permits its inclusion. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

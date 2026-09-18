# Contributing

Discuss substantial changes in the issue tracker before implementation. Keep each
pull request focused on one purpose and target the Kadupul repository.

## Changes and validation

Match the surrounding code and repository formatting rules. Preserve public
interfaces unless a change includes a documented migration. Avoid unrelated edits.

On `main`, PHP files move to PHP-FIG PER-CS 2.0 one file at a time. Every PHP
file a change touches outside `include/vendor` and `tests/Fixtures` must end up
PER-CS formatted. A file that is not converted yet is converted first, in its
own whitespace-only commit; `tests/tools/convert_php_style.sh <file>...` makes
that commit and reformats your pending edit to match. Do not convert files the
change does not otherwise edit. `tests/tools/check_php_style.sh` applies these
rules. The `lts/1.2` branch keeps upstream Cacti formatting.

Reproduce a bug before fixing it, verify the changed behavior, and include the
validation commands and results in the pull request.

## Commits

Use Conventional Commits and sign off every commit with `git commit -s` under the
[Developer Certificate of Origin](https://developercertificate.org/).

## Documentation and security

Verify documentation against the implementation and use material whose license
permits its inclusion. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

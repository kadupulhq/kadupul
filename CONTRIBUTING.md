# Contributing

Discuss substantial changes in the issue tracker before implementation. Keep each
pull request focused on one purpose and target the Kadupul repository.

## Changes and validation

Match the surrounding code and repository formatting rules. Preserve public
interfaces unless a change includes a documented migration. Avoid unrelated edits.

On `main`, PHP files move to PHP-FIG PER-CS 2.0 one file at a time. A file that
is already PER-CS formatted, and every new PHP file, must stay formatted. A small
change to a file that is not converted yet may keep its current formatting;
converting a file is its own whitespace-only change, which must convert it
completely. `tests/tools/check_php_style.sh` applies these rules. The `lts/1.2`
branch keeps upstream Cacti formatting.

Reproduce a bug before fixing it, verify the changed behavior, and include the
validation commands and results in the pull request.

## Commits

Use Conventional Commits and sign off every commit with `git commit -s` under the
[Developer Certificate of Origin](https://developercertificate.org/).

## Documentation and security

Verify documentation against the implementation and use material whose license
permits its inclusion. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

# Contributing

Discuss substantial changes in the issue tracker before implementation. Keep each
pull request focused on one purpose and target the Kadupul repository.

## Changes and validation

Match the surrounding code and repository formatting rules. Preserve public
interfaces unless a change includes a documented migration. Avoid unrelated edits.

On `main`, PHP files a change edits follow PHP-FIG PER-CS 2.0. Reformat each one
in its own whitespace-only commit and check the result with
`tests/tools/check_php_style.sh`. The `lts/1.2` branch keeps upstream Cacti
formatting.

Reproduce a bug before fixing it, verify the changed behavior, and include the
validation commands and results in the pull request.

## Commits

Use Conventional Commits and sign off every commit with `git commit -s` under the
[Developer Certificate of Origin](https://developercertificate.org/).

## Documentation and security

Verify documentation against the implementation and use material whose license
permits its inclusion. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

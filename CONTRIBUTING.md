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

### Generated files that follow a PHP change

Several checked-in files are derived from the PHP source. A change that moves lines
or touches the files below fails CI until they are refreshed, and a rebase over a
change to the same files needs them regenerated, not merged by hand. Run these from the
repository root with the PHP and Python versions the repository selects through `mise`.

| Changed | Refresh | Verify |
| --- | --- | --- |
| Lines move in a file listed in `tests/security/baselines/sink_inventory.baseline.tsv` or `architectural_hotspots.baseline.tsv` | Build with `tests/security/build_sink_inventory.sh` or `build_architectural_helper_report.sh --hotspots`, sort with `LC_ALL=C sort`, and replace only the changed file's lines | `tests/security/verify_sink_inventory.sh`, `verify_architectural_hotspots.sh` |
| A page, include or other file whose gate is classified in `tests/security/baselines/entry_points.baseline.tsv` | Read the diff, then regenerate with `tests/security/build_entry_point_inventory.py`. For `lib/auth.php` the reviewed hash is in `tests/security/classify_entry_points.php`. | `tests/security/verify_entry_point_inventory.sh` |
| A translatable string, or any line move in a file that has one | `locales/build_gettext.sh` (not `update-pot.sh`, which drops historical identities). Revert catalog changes that only touch `POT-Creation-Date`. | `tests/security/verify_gettext_catalogs.py` |
| A function in `lib/functions.php` | `php tools/functions-inventory.php` | `php tools/functions-inventory.php --check` |
| A new test file | Register it in `tests/phpunit-spikekill.xml`, or `tests/phpunit-database.xml` if it needs a database. Write the path relative to `tests/` with no leading `./`. | CI runs `tests/tools/verify_pest_inventory.php`, which compares the registered files with the recorded runs and fails on a mismatch |

The entry-point classifier can run out of memory under the default `memory_limit`
when run locally; set `memory_limit=-1` for that command. A reviewed hash records that a
person read the file, so update it only after reading the diff, and say so in the pull
request. Report the PHP version a result came from.

### Local Git hooks

Enable the repository-owned hooks once per clone:

```sh
.githooks/install
```

The pre-commit hook validates staged content only: whitespace errors, PHP
syntax under PHP 8.4.25 selected through `mise`, and the same PER-CS migration
policy used by CI. It does not rewrite the index or working tree. During a
merge it syntax-checks eligible staged PHP from every incoming parent too.
Whitespace and formatting checks apply to locally authored or conflict-resolved
content; files copied unchanged from an incoming parent retain their existing
whitespace and format. Syntax checks read the index and do not rewrite files.

The commit-message hook enforces Conventional Commit subjects and a DCO
`Signed-off-by` trailer. Use `git commit -s`; merge commits are exempt because
Git generates their messages and the merged commits retain their attestations.

Install php-cs-fixer 3.95.27 on `PATH`, or set `PHP_CS_FIXER` to that pinned
executable. GitHub CI remains authoritative and must pass before merge. Local
AI-review tools are intentionally not required by these hooks.

## Pest profiling and isolated parallel feedback

Install the locked application and test dependencies before running these tools.
Install the Python XML parser with
`mise exec -- python -m pip install --require-hashes -r tests/tools/requirements.txt`.
`mise exec -- python tests/tools/run_pest.py profile --junit /tmp/pest-profile.xml`
runs the full serial suite and prints the slowest cases. `parallel` runs the
reviewed inventory in `tests/phpunit-parallel.xml` with two workers by default.
Use a fresh JUnit path: the runner refuses to overwrite earlier evidence.

Run `mise exec -- python tests/tools/test_run_pest.py` after changing the runner
or inventory. It checks serial/parallel discovery parity, empty selections,
failure outcomes and report preservation. The parallel feedback lane does not
replace full serial coverage, database, browser or deployment checks.
The manually dispatched `Pest profiling` workflow retains its report and log.

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

# Kadupul — Contributor notes for AI coding assistants

This file is for AI coding assistants (Claude Code, Cursor, Copilot, Gemini,
etc.) working against this repository.  It is also a concise reference for any
human contributor who wants to know the house conventions at a glance.

## Project identity

Use Kadupul in project-facing content. Keep licensing information and one brief
fork statement in the README. Do not add optional individual-credit lists or
project-origin narratives. Preserve required copyright, license, and warranty
notices, including display constants. Preserve compatibility identifiers.

## Do not commit

The following directories are local developer tooling state.  They are in
`.gitignore` and **must never appear in a commit**, a diff, a PR, or a push:

- `.claude/` — Claude Code session state, sub-agent worktrees, hook cache
- `.omc/` — oh-my-claudecode orchestration state
- `.worktrees/` — local `git worktree` trees used for parallel experiments
- `notepad.md` — ad-hoc scratch file

If any of these ever show up in `git status` as "Changes not staged for commit"
inside a PR branch, stop and investigate before pushing.  The usual cause is
an agent that staged them explicitly with `git add -f` and it should be backed
out with `git rm --cached`.

## PHP runtime

- Target PHP 7.4 on the `1.2.x` / `feat/*-1.2.x` branches.  Do **not** use
  PHP 8-only syntax on those branches.  That means:
  - no `str_contains`, `str_starts_with`, `str_ends_with` — use `strpos`
  - no `match` expressions — use `switch`
  - no named arguments, no enums, no readonly props, no constructor promotion
  - no `Stringable` / `ReturnTypeWillChange` reliance
- `develop` targets PHP 8.1+.  PHP 8 syntax is fine there.

## Application idioms

Use the house wrappers instead of raw equivalents:

| Prefer | Avoid |
|---|---|
| `cacti_sizeof($x)` | `count($x)` / `sizeof($x)` |
| `cacti_count($x)` | same |
| `html_escape($s)` / `htmle($s)` | `htmlspecialchars($s)` |
| `html_escape_request_var('k')` / `htmlerv('k')` | manual escape of `$_REQUEST['k']` |
| `get_filter_request_var('id')` / `gfrv('id')` | `(int) $_REQUEST['id']` |
| `get_request_var('k')` / `grv('k')` | `$_REQUEST['k']` |
| `get_nfilter_request_var('k')` / `gnrv('k')` | unfiltered `$_REQUEST['k']` |
| `is_request_var_set('k')` / `isrv('k')` | `isset($_REQUEST['k'])` |
| `CACTI_SERVER_OS` | `DIRECTORY_SEPARATOR` / platform sniffing |
| `CACTI_PATH_BASE` (develop) / `$config['base_path']` (1.2.x) | hardcoded repo paths |
| `db_fetch_cell_prepared(...)` | string-interpolated SQL |
| `db_qstr_rlike($s)` | manual `'RLIKE ' . db_qstr(...)` |
| `cacti_escapeshellarg($s)` | `escapeshellarg($s)` |
| `cacti_escapeshellcmd($s)` | `escapeshellcmd($s)` |

## Style

- On `main`, PHP files move to PHP-FIG PER-CS 2.0, as configured in
  `.php-cs-fixer.php`, one file at a time.  Files already on PER-CS and new
  files the Finder includes (not `include/vendor` or `tests/Fixtures`) must
  stay formatted.  A small change to a file that is not converted
  yet may keep its current formatting; the style check skips it.  No drive-by
  reformat.
- Convert a file in its own commit, separate from any behaviour change.  That commit changes whitespace only; `array()` to `[]`, trailing
  commas and other token changes go in separate commits.
- Run `tests/tools/check_php_style.sh` before pushing.  It checks the PHP files
  changed since `origin/main` that were already formatted at the merge base,
  new files, and whitespace-only conversions, and CI runs the same script.  A few files
  need `php-cs-fixer fix` twice before the check passes.
- `lts/1.2` keeps upstream Cacti formatting (tabs, same-line function braces)
  so upstream fixes cherry-pick cleanly.  Do not reformat files there.
- Don't rewrite `api_aggregate.php`, `lib/aggregate.php`, or any other
  file you are not explicitly touching.  Multiple past PRs have been rejected
  because an AI assistant "tidied" unrelated code.
- One blank line after a function closing brace.
- Long lines in `locales/po/*.po` are intentional.  Do not rewrap at 80 cols.

## Security

- Never `include`/`require` a path that came from request data without routing
  it through `validate_path_within` or `validate_relative_path_within`.
- Never compose a shell command by string concatenation.  Use `cacti_exec()`,
  `cacti_exec_string()`, or per-arg `cacti_escapeshellarg()`.
- Never compose an LDAP filter by string concatenation.  Use
  `cacti_ldap_filter($template, $vars)`.
- Every `ORDER BY` clause that references a request value must pass through
  `cacti_validate_sort_column($col, $allowed, $default)` with an explicit
  per-page allowlist.
- Every `RLIKE` clause must use `db_qstr_rlike($s)`; do not build RLIKE
  strings manually.
- Every HTTP fetch of an outbound URL must go through `cacti_http()` (which
  forces TLS peer verification, disables redirects, and optionally enforces
  a host allowlist).

## Commit messages

- Conventional Commits format: `fix(area): short description`,
  `feat(area): ...`, `security(area): ...`.
- One commit = one intent.  Do not bundle style, test, and security fixes in a
  single commit.
- Do **not** list every bullet point you can think of in the commit body.
  Human commits on this repo use 1-3 body lines max.  Anything with 9
  bulleted "fixes" in one commit reads as AI-generated.
- Do not attribute commits to AI assistants.
- Match the existing style of `git log --oneline -30` before writing your
  message.

## PR hygiene

- One clear purpose per PR.  If you are tempted to say "while I was in there I
  also fixed …", that is a separate PR.
- Do not include `include/vendor/**` in a PR diff unless the PR is an
  intentional dependency bump.  If vendor files appear, they came from a
  dirty `composer install` or a stale worktree; back them out with
  `git checkout upstream/develop -- include/vendor/`.
- Rebase cleanly before pushing.  Force-pushes are fine with
  `--force-with-lease`; never use bare `--force`.
- Respond to review comments with one sentence.  Long justifications read as
  AI output.

## Common failure modes (avoid)

1. Branch contains `.claude/`, `.omc/`, `.worktrees/` — see top of this file.
2. Branch contains `include/vendor/**` edits — stale `composer install` leaked
   into the diff; run `git checkout upstream/develop -- include/vendor/` and
   re-push.
3. Commit touches `lib/api_aggregate.php` or `lib/aggregate.php` when the PR
   title does not mention aggregates — an assistant rewrote it by mistake.
4. `str_contains(` appears on a 1.2.x branch — not PHP 7.4 compatible.
5. PR description has "Summary / Test plan / Impact" headers with bullets — a
   dead giveaway of AI authorship.  Write a short paragraph instead.
6. 9 commits pushed within 30 seconds — pace commits, or squash them before
   the first push.

## What lives where

- `lib/functions.php` — core helpers (escaping, redaction, session, path)
- `lib/auth.php` — session and lockout logic; `cacti_authorize_resource`,
  `cacti_authorize_has_realm` live here
- `lib/database.php` — `db_qstr`, `db_qstr_like`, `db_qstr_rlike`
- `lib/ldap.php` — LDAP helpers; `cacti_ldap_filter` at the bottom
- `lib/import.php` — package import; the path-traversal hardening lives here
- `lib/html_utility.php` — `validate_redirect_url`, `get_order_string`
- `lib/html_form.php` — form rendering (careful: tooltips render HTML by
  design on some fields, see maintainer feedback on #7054)

## Contact and review

- Project contacts and review: https://github.com/kadupulhq/kadupul
- Follow this repository's CODEOWNERS and contribution guidance for reviews.
- When in doubt, open a draft PR and ask before you rebase across 60 files.

<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Repository Git hooks

Enable them once per clone:

```sh
.githooks/install
```

The script refuses rather than overwrite an existing `core.hooksPath`, because
Git holds only one value for it and replacing a global hooks directory would
drop whatever checks it applies without saying so. If your global hooks already
run `.githooks/pre-commit` for you, you do not need to run this at all.

Disable them with `git config --local --unset core.hooksPath`.

## What runs

`pre-commit` validates staged content only. It never rewrites the index or the
working tree, and it reads each file from the index rather than from disk, so
unstaged edits neither hide a fault nor get inspected.

- whitespace errors, through `git diff --cached --check`
- PHP syntax, on the staged blob of every changed `.php` file

During a merge it skips any file whose staged blob is identical to the incoming
parent's, since that content was validated on the branch it came from, and
checks only what was authored here or produced while resolving a conflict.
`include/vendor/`, `tests/vendor/`, `tests/fixtures/` and `node_modules/` are
skipped entirely.

`commit-msg` requires a Conventional Commits subject and a Developer
Certificate of Origin sign-off. Merge commits, reverts, and `fixup!`/`squash!`
subjects are exempt.

## Why PHP 8.1

Linting runs under `mise exec php@8.1`, which is the oldest version in this
branch's CI matrix. A newer interpreter accepts syntax that 8.1 rejects, so
linting with one would pass a file that breaks for part of the supported range:
`readonly class`, for instance, parses on 8.2 and fails on 8.1.

Install it with `mise install php@8.1` if the hook reports it missing.

## Why there is no style stage

This branch keeps Cacti's original formatting and ships no `.php-cs-fixer.php`.
The `main` branch's copy of `pre-commit-checks` has a PER-CS stage because it
has that configuration to check against. Adding one here means adding the
configuration first, which is a decision about the branch rather than about
these hooks.

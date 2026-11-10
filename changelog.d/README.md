<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Changelog fragments

Each pull request with a user-visible change adds one file here instead of
editing `CHANGELOG.md`, so pull requests no longer conflict with each other in
the changelog. The release preparation folds these files into `CHANGELOG.md`
with `python3 tools/changelog.py release <version>` and deletes them.

Name the file `<slug>.<category>.md`. The slug is lower-case letters, digits
and hyphens: the issue or pull request number, or a short name for the change.
The category is one of `added`, `changed`, `deprecated`, `removed`, `fixed`,
`security` or `tests`.

The file holds one or more Markdown list items, written as they should appear
in the release notes. Continue a long item on lines indented by two spaces.
Headings are not allowed. For example, `changelog.d/1357.fixed.md`:

```markdown
- Remove temporary files that a killed data input whitelist update left beside
  its target (#1357).
```

`python3 tools/changelog.py check` validates the fragments and
`python3 tools/changelog.py preview` prints what the next release will gain.
See [CONTRIBUTING.md](../CONTRIBUTING.md#changelog).

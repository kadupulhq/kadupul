<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# lib/functions.php inventory

One JSON record per function declared in `lib/functions.php`, plus `index.json`. The records track
the decomposition of that file into modules under `src/`: where each function is going, how far it
has got, and what it does today. Plugins call these functions by name, so every row also records how
widely each one is referenced.

The generator writes arrays and objects on one line when they fit in 100 columns and expands larger ones one element per line, which keeps the generated diff reviewable. The data is unchanged; only whitespace differs.

## Files

| File | Written by | Purpose |
| --- | --- | --- |
| `<function>.json` | generator (`static`), people (`human`) | One record per top-level function. |
| `index.json` | generator | Sorted summary, counts, and the baseline for the freeze test. |
| `dead-code-candidates.md` | generator | Functions with two or fewer core references. Candidates only. |
| `seed.json` | people | Initial `target_module` and `wave` for new records. The groupings were assigned by hand, by function name and purpose, when the inventory was introduced; they are a starting point, not an approved design. Read only when a record is first created. |
| `README.md` | people, plus the counts block | This file. The block between the `inventory-counts` markers is generated. |

## Record schema

```json
{
    "schema": 1,
    "name": "title_trim",
    "static": { },
    "human": { }
}
```

### `static`: generated, never edit by hand

| Field | Meaning |
| --- | --- |
| `file`, `start_line`, `end_line`, `line_count` | Location of the declaration, from the `function` keyword to the closing brace. |
| `signature.parameters[]` | `name`, `type`, `nullable`, `by_ref`, `variadic`, `has_default`, `default` (source text). |
| `signature.return_type`, `signature.returns_reference` | Declared return type or `null`; `true` for `function &name`. |
| `docblock` | A doc comment immediately precedes the declaration. |
| `fan_out` | Word-boundary reference counts over tracked PHP, definition excluded: `core`, `core_outside_functions_php`, `tests`, `plugins`. |
| `body.uses_global` | Names in `global` statements. |
| `body.static_variables` | Names declared with `static $x`. |
| `body.superglobals`, `body.session` | Superglobals used; `$_SESSION` reference count and literal keys. A non-literal key is `(dynamic)`. |
| `body.db_calls` | Each `db_*` function called, with its call count. |
| `body.headers`, `body.exits`, `body.output` | `header()` and cookie calls; `exit`/`die`; `echo`, `print`, inline HTML, `printf`-style calls. |
| `body.file_functions`, `body.process_functions` | Builtin file and process functions called. |
| `body.plugin_hooks[]` | `api_plugin_hook` and `api_plugin_hook_function` calls with `name`, `resolved`, `expression` and `line`. A hook name that is not a plain string literal has `resolved: false`, `name: null`, and the argument source in `expression`. |
| `calls.functions`, `calls.recursive` | Other functions declared in `lib/functions.php` that this one calls directly, and whether it calls itself. |
| `called_by` | The inverse of `calls.functions`. |

`fan_out` counts every word match, so string callbacks and hook names that equal a function name are
included, and so are comments and unrelated identifiers. Treat it as an upper bound on use. The
`core` count includes callers inside `lib/functions.php`; `core_outside_functions_php` does not.
`tests/` counts under `tests`, `plugins/` under `plugins`, everything else under `core`.
`include/vendor/`, `tests/vendor/`, the generator, its tests and `tests/Support/Behavior/` are not
scanned, so editing test-support files that list functions by name does not change the static facts.

`calls.functions` sees direct calls only. A function reached through `call_user_func`, a callback
array, a variable function name or a plugin hook is visible in `fan_out` but not in `called_by`.

### `human`: preserved across regeneration

| Field | Values |
| --- | --- |
| `target_module` | Destination module, a module path such as `Platform/Domain/Text`, `legacy-retained` for functions that stay until their pages migrate, or `unassigned`. |
| `wave` | `0` to `5`, or `unassigned`. |
| `status` | `not assessed`, `planned`, `implemented`, `verified`. |
| `lts_status` | `not assessed`, `not on lts`, `unchanged on lts`, `diverged on lts`. Tracks whether a fix to the shared function needs a hand port. |
| `behavior_record` | Path to the captured behavior record, or `null`. The layout is `tests/Golden/functions/<target>/php-<ver>/<function>.json`; the `kadupul/php-8.4` golden is the canonical record. The file must sit under `tests/Golden/functions/` and its `function` field must equal the record name. |
| `notes` | Free text. |

The generator reads `human` from the existing record and writes it back unchanged. It only fills it
in from `seed.json` when a record does not exist yet. An invalid `human` block fails both the
generator and the check; the generator does not repair it.

## Commands

Run from the repository root with the project runtime:

```sh
mise exec -- php tools/functions-inventory.php            # regenerate records and index
mise exec -- php tools/functions-inventory.php --check    # fail if committed static facts are stale
mise exec -- php tools/functions-inventory.php --external-plugins=/path/to/plugins
```

`--check` regenerates in memory and compares with the committed files. It prints the first differing
line of each stale file, and names missing and orphaned files. It never writes. It compares declaration
facts (name, lines, signature, body facts, `calls` and `called_by` within `lib/functions.php`, human-field
validity and the file set) but not `fan_out`, because those counts change when any tracked PHP file
mentions a function name. It reuses the committed `fan_out` values, so the index fan-out column and
`dead-code-candidates.md` are compared as committed too. Plain generation rescans and refreshes
`fan_out`. The same comparison
runs in `tests/Symfony/FunctionsInventoryTest.php`, which `composer test` and
`./tests/bin/run symfony` discover, so a stale inventory fails CI.

Regenerate and commit whenever `lib/functions.php` changes. Regenerate when references to its
functions change elsewhere in tracked PHP only if you want the `fan_out` counts and the dead-code
report refreshed; the check does not require it. Output is deterministic: no timestamps,
revisions or absolute paths, and the files are sorted. Running the generator twice produces
identical bytes.

When a function is removed from `lib/functions.php`, regenerating deletes its record. Do that in the
same change that removes the function.

`--external-plugins=DIR` is read only. It scans a plugin checkout and prints, for each dead-code
candidate, how many times the name appears there. Nothing is written, because plugin checkouts differ
between installations and would make the committed files non-deterministic.

## Status gates

| Transition | Required |
| --- | --- |
| `not assessed` to `planned` | A `target_module` and `wave`. Not checked by tooling. |
| `planned` to `implemented` | A `behavior_record` under `tests/Golden/functions/` that exists, is valid JSON and names this function in its `function` field, and an assigned `target_module` and `wave`. Checked by the generator and `--check`. |
| `implemented` to `verified` | The golden fixtures named by the behavior record pass unchanged against the old function and against the delegate left in `lib/functions.php` after extraction. |

The tooling checks only what a static read can see: a function marked `implemented` or `verified`
must point to a behavior record that exists and names the function. It cannot see whether the fixtures ran. Whoever sets
`verified` is stating that the extraction PR ran them through the wrapper, with nonzero executed
counts, and the PR description should say so. A `verified` row without that evidence is a defect in the
review, not something this check catches. The behavior records themselves, and the harness that
produces them, are outside this directory.

## Freeze guard

`tests/Symfony/FunctionsFreezeTest.php` fails when `lib/functions.php` declares a function that
`index.json` does not list. The guard counts conditional and nested declarations too, and ignores
methods. It lets functions be removed or moved out, because that only shrinks the file.

New code goes in a module under `src/` (Domain, Application or Infrastructure by layer) and the
legacy page calls it. The generator refuses to add an unlisted function to the index unless run with
`--accept-new-functions`, so the guard cannot be satisfied by regenerating. If a reviewer sees a new
record file in a pull request, the function was added to the frozen file and the PR needs a reason.

## Reading the numbers

A `^function ` line count gives 229 functions. The tokenizer finds the same 229 top-level
declarations plus one conditional declaration, `posix_kill`, defined inside an `if` block for Windows.
`index.json` lists it under `conditional_definitions`; it has no record, because it is only defined on
Windows when the POSIX extension is absent.

An earlier count of 24 functions with "2 or fewer references" included each
function's own definition and the `tests/` directory. With the definition excluded, those 24 are the
functions with one or no other reference in all tracked PHP. The dead-code report uses the wider
threshold the inventory defines: core references of two or fewer, definition excluded.

## Plugins

The repository tracks only `plugins/index.php`. Third-party plugins are not visible, so a zero in the
`plugins` count is not evidence that no plugin calls a function. Names in `lib/functions.php` are a
public contract until the plugin migration says otherwise. Before deprecating or removing any
function in `dead-code-candidates.md`, run `--external-plugins` against the plugins in use and record
the result in the function's `notes`.

## Relation to the lexical inventory

`tests/Support/Behavior/inventory.py` writes `docs/testing/behavioral-inventory.json`: a regex scan of
the whole repository for functions, hooks, actions and queries, stamped with a revision. It finds
where behavior is declared. This inventory parses one file with the PHP tokenizer and records what
each function does and where it is going. The two do not share output and neither edits the other's
files. Cross-check by function name if the lexical scan reports a hook this one marks unresolved.

## Counts

<!-- inventory-counts:start -->
| Target module | not assessed | planned | implemented | verified | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Alerting/Application/Port/MailSender | 10 | 0 | 0 | 0 | 10 |
| Collection/Application | 1 | 0 | 0 | 0 | 1 |
| DataInput/Application | 9 | 0 | 0 | 0 | 9 |
| DataInput/Domain | 15 | 0 | 0 | 0 | 15 |
| GraphDefinition/Infrastructure | 8 | 0 | 0 | 0 | 8 |
| Graphing/Domain | 9 | 0 | 0 | 0 | 9 |
| IdentityAccess | 13 | 0 | 0 | 0 | 13 |
| Inventory/Application | 8 | 0 | 0 | 0 | 8 |
| Navigation | 17 | 0 | 0 | 0 | 17 |
| Platform/Application/Port/ConfigurationSettings | 7 | 0 | 0 | 0 | 7 |
| Platform/Application/Port/Filesystem | 7 | 0 | 0 | 0 | 7 |
| Platform/Application/Port/FlashMessages | 11 | 0 | 0 | 0 | 11 |
| Platform/Application/Port/NameResolver | 3 | 0 | 0 | 0 | 3 |
| Platform/Application/Port/ProcessRunner | 6 | 0 | 0 | 0 | 6 |
| Platform/Application/Port/ProductVersion | 8 | 0 | 0 | 0 | 8 |
| Platform/Application/Port/UserSettings | 11 | 0 | 0 | 0 | 11 |
| Platform/Domain/Collection | 5 | 0 | 0 | 0 | 5 |
| Platform/Domain/Network | 8 | 0 | 0 | 0 | 8 |
| Platform/Domain/Security | 18 | 0 | 0 | 0 | 18 |
| Platform/Domain/Text | 13 | 0 | 0 | 0 | 13 |
| Platform/Domain/Time | 9 | 0 | 0 | 0 | 9 |
| Platform/Infrastructure/Legacy | 17 | 0 | 0 | 0 | 17 |
| Platform/Infrastructure/Persistence | 1 | 0 | 0 | 0 | 1 |
| Platform/Presentation/Http | 13 | 0 | 0 | 0 | 13 |
| legacy-retained | 2 | 0 | 0 | 0 | 2 |
| **Total** | 229 | 0 | 0 | 0 | 229 |

| Wave | not assessed | planned | implemented | verified | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1 | 51 | 0 | 0 | 0 | 51 |
| 2 | 38 | 0 | 0 | 0 | 38 |
| 3 | 68 | 0 | 0 | 0 | 68 |
| 4 | 53 | 0 | 0 | 0 | 53 |
| 5 | 19 | 0 | 0 | 0 | 19 |
| **Total** | 229 | 0 | 0 | 0 | 229 |

<!-- inventory-counts:end -->

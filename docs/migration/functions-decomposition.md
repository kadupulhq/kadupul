<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Decomposing lib/functions.php

`lib/functions.php` holds 229 functions in about 9,300 lines and is loaded by `include/global.php`
on every legacy request. Core pages, the poller, the command-line tools and third-party plugins call
them by name. This document describes how those functions move into modules under `src/` without
changing what their callers see, and records where that work stands.

The per-function facts are in the [inventory](functions-inventory/README.md). How behavior is
recorded is in [function-level behavior capture](../testing/function-behavior.md). This page covers
the approach, the decisions behind it, and the current state.

## Approach

The file is replaced by delegates, one function at a time. A function moves in three steps:

1. **Record.** Capture what the function does today by calling the real function, including inputs
   nobody intended. The result is a golden file under `tests/Golden/functions/`.
2. **Extract.** Move the logic into the owning module. The old function stays in `lib/functions.php`
   with its original name, argument order and return shape, and calls the new code. Callers and
   plugins do not change.
3. **Retire.** When no core caller is left and a plugin audit is clean, mark the delegate deprecated,
   then delete it in a later release. Migrated code never calls a delegate.

Call sites are not bulk-renamed. Rewriting thousands of `cacti_sizeof()` calls changes no behavior.
A call is replaced only when its file is being migrated for another reason.

A defect found while recording is not fixed during extraction. It is recorded, filed as an issue, and
fixed in its own change so the extraction stays a pure move.

### Rules that hold the approach in place

| Rule | Where it is enforced |
| --- | --- |
| `lib/functions.php` may shrink but not grow. A new top-level function fails the build. | `tests/Symfony/FunctionsFreezeTest.php` against `docs/migration/functions-inventory/index.json` |
| Every function has an inventory record, and its static facts match the file. | `tests/Symfony/FunctionsInventoryTest.php`, `php tools/functions-inventory.php --check` |
| A function is `implemented` only with a behavior record, and `verified` only when its goldens pass through the delegate. | [Status gates](functions-inventory/README.md#status-gates) |
| Recorded behavior does not drift. | The install, login and poller smoke job runs `harness.py functions` against the committed goldens on every pull request. |
| Goldens are never edited to make a test pass. A changed golden needs a stated reason. | Review |

## Constraints

- **Legacy pages do not boot the Symfony kernel.** A delegate cannot take a service from the
  container. Pure logic becomes a class with no dependencies that the delegate calls directly.
  Stateful logic gets a small accessor in `Platform/Infrastructure/Legacy`, built from the same trusted
  configuration the legacy page already loaded. Booting the kernel on every legacy request is a separate
  decision that needs a measured latency number.
- **Plugins call these functions.** Names, argument order and return shapes are a public contract until
  the plugin migration says otherwise.
- **The Symfony session bag is disabled on purpose**, because a second session payload format is a risk.
  Flash messages and session helpers need a port with a legacy-session adapter, not `FlashBag`.
- **Some groups are security boundaries**: authentication, sessions, cookies, process execution, paths
  and secrets. They are not mechanical moves. Each gets its own change, with abuse-case tests and an
  independent review.

## Decisions

| Question | Decision | Reason |
| --- | --- | --- |
| Doctrine DBAL or ORM for the 54 functions that query the database | DBAL. 51 of the 54 functions still call `db_*` directly. | `doctrine/dbal` is installed and 36 files under `src/` use it. The legacy schema is not shaped for entity mapping. `doctrine/orm` is not installed and is not worth adding for this work. An ORM can be reconsidered later for aggregates such as devices or templates. |
| Rewrite the `db_*` helpers in `lib/database.php` | Not now. | About 5,600 call sites in 260 files depend on their retry, reconnect and silent-error behavior. Moving the 54 functions does not require touching them. To be decided on data after the function moves. |
| Logging | A PSR-3 `LoggerInterface` that keeps the exact `cacti.log` line format, with `cacti_log()` as a thin wrapper. | `psr/log` is already installed transitively. Monolog is not added. Audit events, `debug_log_*` and `dsv_log` stay separate from operator logging. |
| Where Symfony components fit | Clock: `symfony/clock`. Mail: `symfony/mailer`. Processes: `symfony/process`. Files: `symfony/filesystem`. Request and response: `symfony/http-foundation`. Outbound HTTP: `symfony/http-client`. Debounce: `symfony/lock`. CSRF: `symfony/security-csrf`. | Use the component where the function does what it does. Domain classes, flash messages, settings reads and DBAL itself are not Symfony components. |
| PHP idioms in new `src/` code | `declare(strict_types=1)`, `final readonly`, `#[\Override]`, `#[\SensitiveParameter]`, enums, `array_any()` and `array_find()`. No property hooks. | Delegates keep the original untyped signatures, because adding types would change coercion for existing callers. |

## State

Wave 0 is complete. Wave 2 has started with settings storage.

| Wave | Scope | State |
| --- | --- | --- |
| 0 | Inventory, freeze guard, behavior capture harness, goldens | Done. 229 records. 78 functions and 1,344 cases are held by goldens and verified in CI. |
| 1 | Pure leaves into `Platform/Domain`: strings, arrays, network values, version and hash helpers | Partly recorded, not yet moved. Two defects found while recording were fixed in place (`padleft`, `build_where_from_array`). |
| 2 | Settings and logging | Settings: the statements of the eight settings functions run through ports and DBAL adapters. Logging: recorded, not yet moved. |
| 3 | Boundary services: mail, process execution, paths, request and response | Not started. |
| 4 | Domain logic: data sources, ordering, device state, data input execution | Not started. |
| 5 | Page chrome and retirement of delegates | Not started. |

### Settings storage

`read_config_option`, `set_config_option`, `config_value_exists`, `prime_common_config_settings`,
`read_user_setting`, `set_user_setting`, `user_setting_exists` and `clear_user_setting` send their
statements through two ports in `Platform/Application/Port`, `ConfigurationSettings` and `UserSettings`.
Their DBAL adapters, `DbalConfigurationSettings` and `DbalUserSettings`, are in
`Platform/Infrastructure/Persistence`.

`Platform/Infrastructure/Legacy/Dbal` runs DBAL on the same PDO session as the `db_*` helpers
(`SharedPdoDriver` and its connection, statement and result classes). A statement joins the caller's
transaction, follows a switch to the main database, and leaves the handle's silent error mode alone. It
never opens a connection or reads `config.php`. `LegacySettingsStorage` is the accessor the delegates
use, and `LegacyLockRetry` keeps the helpers' bounded retry on lock conflicts.

The functions keep their caches, defaults, hooks and the remote-collector write. The goldens pass
unchanged through them.

Differences from the helpers:

- The `db_*` helpers still run before Composer's autoloader is loaded, which includes the bootstrap's own
  `prime_common_config_settings()`, and for argument types outside the port (null, booleans, floats,
  arrays). Loading DBAL earlier broke the check that Composer loads after the CSRF boundary.
- No reconnect on a dropped connection (errors 2002 and 2006).
- Lock conflicts outside a transaction retry up to 30 times, 200 ms apart, on errors 1213, 1205 and 1020.
  After 31 conflicts the log and `db_error()` text is the generic failure line, not "Too many
  Lock/Deadlock errors occurred!".
- The `DEBUG_SQL_*` echo is not reproduced.
- Uncached reads were measured about 12% slower in the implementation run (10,000 calls: 196 to 204 ms
  before, 221 to 228 ms after). Cached reads are unchanged. This was not re-measured afterwards.
- A real deadlock (error 1213) is covered by a unit test only. The adapter and bridge database
  contracts run in the database job on all six supported engines.

What remains for settings: loading DBAL safely at bootstrap, `save_user_settings`,
`user_setting_value_allowed`, the guest-user lookup inside `read_user_setting`, and the remote-collector
write through a collector-scoped connection.

## Moving a function

1. Open its inventory record. Check the target module and wave. If it is `unassigned`, decide that first.
2. Make sure it has cases in `tests/Support/Behavior/function_cases/` and a golden. If not, add them as
   described in [Adding a function](../testing/function-behavior.md#adding-a-function), in their own
   commit before the extraction.
3. Run `make test-functions` and confirm the goldens pass against the unchanged function.
4. Move the logic. Leave a delegate with the original signature. Do not add a function to
   `lib/functions.php`; the freeze test rejects it.
5. Run `make test-functions` again. The goldens must pass unchanged. A changed golden needs a stated,
   reviewed reason in the pull request.
6. Run `php tools/functions-inventory.php`, then set the record's `status` and `notes`. Line numbers move
   when the file shrinks, so also refresh the security baselines (only the `lib/functions.php` entries)
   and, if the change touches translatable strings, run `locales/build_gettext.sh` and
   `tests/security/verify_gettext_catalogs.py`.
7. In the pull request, report the version of PHP used and the counts the goldens ran with.

Fixes to shared code land on `lts/1.2` first and are carried to `main`. A fix on `main` alone is
assessed for LTS immediately. Set the record's `lts_status` accordingly.

## Defects found by recording

Recording behavior turned up defects in the legacy code. They are kept in the goldens as they are, filed,
and fixed separately.

| Function | Finding | Issue |
| --- | --- | --- |
| `padleft` | Never returned for a non-numeric pad; a numeric pad could stop short of the minimum. Fixed. | #860 |
| `build_where_from_array` | A field name with a trailing newline passed the name check. Fixed. | #861 |
| `cacti_log` | A newline in `$environ` started a new log record; control bytes reached the file. Fixed: a newline in `$environ` becomes a space, and other control characters, U+2028 and U+2029 are written as visible escapes. `LegacyLogText` shares this rule with Symfony's `LegacyOperatorLog` and loads before Composer. | #870, #899 |
| `cacti_is_sensitive_key`, `cacti_redact_value` | The key list misses common credential names and matches some unrelated ones. Fixed: the list now covers the common credential names. The substring match still redacts some unrelated names, which is left on purpose because it fails closed. | #871 |
| `set_user_setting`, `save_user_settings` | A non-numeric user id was stored as user 0. Fixed: a user id that is not an integer or a digit string, or is outside the 0 to 16777215 range of `settings_user.user_id`, is rejected and logged. | #872 |
| `save_user_settings` | A rejected user id left no error, so `user_admin.php` reported "Save Successful." Fixed: the rejection flags the `id` field and the page reports a save failure. | #891 |
| `get_associated_rras` | `$sql_where` is concatenated into the query. Core now selects one RRA through a bound `$rra_id`; the fragment argument stays for plugin callers. | #873 |
| `clear_user_setting`, `read_user_setting`, `user_setting_exists` | A non-numeric user id deleted, read or counted user 0's settings. Fixed: the same user id check as #872 applies. | #887 |

Several of these exist on `lts/1.2` as well.

## Limits of the evidence

- Goldens hold 78 of 229 functions. A function without a golden has no recorded behavior and cannot be
  `implemented`.
- A golden records one implementation on one PHP version (8.4) and one database. Per-engine differences
  for database-backed functions are covered only where a database contract test exists.
- The differential report against Cacti `develop` covers 27 functions: 529 cases the same and 99
  different. Every difference is still `unreviewed`.
- The module and wave of 52 functions were assigned by hand from their names and purpose, and have not
  been reviewed by the people who will move them.
- This repository cannot see third-party plugins. Retiring a delegate needs a separate plugin audit.
- The golden run starts a Docker stack and installs the schema. On a heavily loaded host the install step
  can exceed its 180 second limit and the run fails before comparing anything. That is not a mismatch;
  run it again.

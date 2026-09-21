# Main: PHP 8.4 modernization audit

Date: 2026-09-21. Scope: `main`, based on `f4e8a056c` plus the site-editor and
PHP-floor changes in PR #170. LTS is excluded and retains its existing runtime.

Main now requires PHP 8.4 in Composer, the early legacy/Symfony runtime checks,
installer diagnostics, CI, local tooling and test images. Dependency resolution
uses PHP 8.4.0; local verification used PHP 8.4.25 through mise. The existing
production image digest already contains PHP 8.4.25.

## Required compatibility work, still open

PHP 8.4 deprecates `E_STRICT`, callback-style session-handler registration and
changes to certain session INI values. See the
[official migration guide](https://www.php.net/manual/en/migration84.deprecated.php).
These are application findings, not vendor warnings:

| Priority | Location | Finding and next change |
| --- | --- | --- |
| High | `include/session.php:125` | Six-callback `session_set_save_handler()` registration. Replace with a `SessionHandlerInterface` adapter; retain the shared session format and database behavior. Test read/write/destroy, garbage collection, login/logout and session rotation in both legacy and Symfony routes. The interface's `gc()` result must be a deleted-row count or `false`, not the existing callback's boolean success value. |
| High | `lib/aggregate.php:274`, `lib/boost.php:133`, `lib/dsdebug.php:61`, `lib/dsstats.php:584`, `lib/functions.php:6239`, `lib/rrdcheck.php:665` | Six active `E_STRICT` references in error maps/handlers. Remove obsolete cases and verify warning/error logging paths. These references can emit diagnostics when the handler executes even though ordinary page tests pass. |
| Conditional | `include/global.php:422`, `src/IdentityAccess/Infrastructure/Legacy/SharedSession.php:58` | Cookie-only/session transport settings are written at runtime. Reapplying the default does not itself warn; changing a non-default configuration does. Require secure deployment settings and validate them before opening a session. Do not remove cookie-only protection just to silence warnings. |

`include/global_arrays.php:472` also references `E_STRICT`, but its PHP <8.4
condition is unreachable under the new floor. Remove that obsolete branch as
cleanup rather than treating it as an active warning.

A second pass matched the deprecated internal functions reported by reflection
on the selected PHP 8.4 runtime against application source. It found four
`libxml_disable_entity_loader()` calls in `lib/import.php:347,355,473,481`, all
behind `LIBXML_VERSION < 20900`. These are dormant on the tested libxml runtime,
and belong in the compatibility-branch cleanup. Keep XXE regression coverage
when removing them; do not replace parser safety with untested assumptions.
The [PHP function reference](https://www.php.net/manual/en/function.libxml-disable-entity-loader.php)
explains the deprecation and libxml-dependent parser behavior.

## Test dependency debt

The isolated `tests/composer.json` still uses Pest 1 / PHPUnit 9. Under PHP 8.4,
Pest's implicit nullable declarations can stop bootstrap before tests run.
The pre-existing CSV/spike-removal jobs already suppress runner deprecations;
the database-contract and legacy coverage jobs now use the same temporary
`error_reporting=24575` policy (E_ALL without E_DEPRECATED).

This also suppresses application E_DEPRECATED in that runner process: those
jobs **do not prove deprecation-free application execution**. Upgrade the test
runner and coverage tooling together, remove these masks, then run with strict
deprecation reporting. Do not patch installed vendor files. The Symfony PHPUnit
suite has no new suppression, and the isolated production CSV probes explicitly
use E_ALL. Existing legacy coverage exclusions remain a separate test debt.

## Idiomatic PHP and architecture work

These are modernization opportunities, not PHP 8.4 deprecations:

1. Remove dead runtime compatibility paths. Examples: PHP 5.3 checks in
   `lib/functions.php:1132`, PHP 7.3 cookie fallbacks in that file and
   `include/global.php`, PHP 5.5.4 ping branches in `lib/ping.php`, and PHP 8.0
   gettext branches in `include/global_languages.php` / `include/global_arrays.php`.
   Audit behavior first, especially cookies, authentication and localization.
   Keep the new runtime guard and genuine external-version checks.
2. Replace untyped mutable state at module boundaries. `lib/ping.php` and
   `lib/spikekill.php` contain 31 `var` declarations in total. Establish actual
   value types and callers before introducing typed properties. Prefer explicit
   constructor dependencies, typed commands/results and immutable value objects
   as functionality moves into DDD modules.
3. Move global configuration/database access behind application ports and
   infrastructure adapters. The scan counted 941 `global $...` matches; adding
   type hints to procedural globals would not establish domain boundaries.
4. Use short arrays and explicit signatures when touching migrated code.
   There are 8,339 `array(...)` pattern matches. Mechanical formatting should be
   separate from behavior changes. Avoid a repository-wide `strict_types` or
   property-hooks rewrite: validate legacy coercion contracts first, and use
   Symfony for transport/forms/services while keeping domain behavior independent.

The pattern counts are search results, not counts of bugs or exhaustive AST
classifications. PHP 8.4 property hooks are optional; conventional typed,
encapsulated objects remain appropriate for the domain model.

## Evidence and limits

- A targeted AST scan covered 415 tracked application PHP files, excluding tests
  and installed dependencies. It found no implicit-nullable declarations or
  missing escape arguments in direct procedural CSV calls. It also checked
  selected deprecated functions/constants/signatures. Dynamic calls, method
  receivers, inherited/magic properties and runtime argument values require
  further analysis; this is not a complete compatibility proof.
- The initial syntax scan covered 655 application/test/tool PHP files, including the new
  preflight and excluding fixture/golden trees. The executable was explicitly
  resolved with `mise which php` and verified as **8.4.25**: zero syntax errors or
  compile-time deprecations. An initial child-process run resolved host PHP 8.5;
  its `$http_response_header` diagnostics were excluded from this PHP 8.4 audit.
- Symfony unit/kernel/architecture suite: 101 tests, 4,172 assertions passed on
  PHP 8.4.25. Complete HTTP suites passed with file and database sessions on
  the PHP 8.4 test image. Passing these suites does not mean all legacy paths
  were executed or all runtime deprecations were rejected.
- Legacy and Symfony bootstraps reject PHP 8.3 before database/configuration
  access; PHP 8.4 passes the preflight. `tests/tools/runtime_floor.py` now
  verifies both CLI and HTTP behavior in CI on PHP 8.3 (rejection only) and
  PHP 8.4. Both Composer manifests/locks validate.
- The complete legacy unit suite could not run locally on the case-insensitive
  macOS worktree: a `HandOff`/`handoff` exclusion resolves differently and loads
  the already-excluded missing `lib/type_secure.php` dependency. Linux CI is the
  authoritative full-suite check; focused compatible suites can run locally.

Reproduce the source searches with `rg -n 'E_STRICT|session_set_save_handler'`
and `rg -n 'version_compare\(PHP_VERSION|PHP_VERSION_ID|\bvar \$'`, excluding
installed dependencies. Run PHP commands through mise and verify subprocess
runtime selection rather than assuming child `PATH` matches the parent.

Recommended order: modernize the session adapter and error handlers first,
upgrade the legacy test runner, remove dead compatibility branches, then apply
strict typing and dependency injection module by module during the Symfony
migration. No LTS code or dependency constraints are changed by this audit.

# Twig completion plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every remaining legacy web page on `main` to Symfony controllers and Twig templates, move every legacy process entry point to Symfony Console, and retire the global state those paths own, with every changed line covered and every migrated route profiled against its legacy predecessor.

**Architecture:** Phase 0 adds the shared gates (entry-point inventory, request profiling, patch-coverage gate, deduplicated shims and action templates). Phases 1 to 7 then migrate complete workflows in batches of about ten, each through the same slice recipe: characterize, read model, write path, controller, template, shim, profile, retire. Each batch after Phase 0 gets its own detailed plan written from this document when it starts, because the batch plans depend on what the previous batch extracted.

**Tech Stack:** PHP 8.4 (`mise.toml` pins 8.4.25), Symfony 7.4 (FrameworkBundle, TwigBundle, Form, Security CSRF, Console, Translation), Doctrine DBAL 4, PHPUnit 10.5 and Pest, pcov, the Python behavioral harness in `tests/tools`.

**Spec:** The request on 2026-10-27: "move everything to Twig using idiomatic Symfony, have code profiled, 100% test coverage or as close to it as possible, convert the whole workflow, remove globals as applicable, use PHP 8 to 8.4 idioms, keep it DRY, keep it Kadupul idiomatic." Read with `docs/symfony-migration.md`, `docs/architecture.md` and `docs/migrations/twig-global-state-audit.md`.

## Starting point

Measured on `origin/main` at `e66f25cf50` on 2026-10-27.

| Measure | Value | Source |
| --- | --- | --- |
| Root PHP entry points | 87 files, 55,966 lines | `wc -l *.php` |
| Already Symfony shims | 19 (`host.php`, `sites.php`, `cdef.php`, `vdef.php`, `color*.php`, `gprint_presets.php`, `data_input.php`, `data_source_profiles.php`, `automation_templates.php`, `links.php`, `about.php`, `help.php`, `permission_denied.php`, `rrdcheck.php`, `index.php`, `app.php`, `csp_report.php`) | files of 7 to 25 lines |
| `global` statements | root 210, `lib/` 539, `include/` 33, `cli/` 58, `src/` 9 | `rg '^\s*global\s+\$'` |
| `$GLOBALS` uses | `lib/` 14, `cli/` 2, `src/` 15 | `rg '\$GLOBALS'` |
| Line coverage | 60.9% (49,717 of 127,057 lines uncovered) | SonarCloud `kadupulhq_kadupul` |
| Condition coverage | 88.0% | SonarCloud |
| Duplicated lines | 6.3% | SonarCloud |
| Twig templates | 71 | `find templates -name '*.twig'` |
| Application profiling | none; `pest-profile.yml` profiles the test suite only | `composer.lock` has no `symfony/stopwatch` or `symfony/web-profiler-bundle` |

The native Twig layers already have no `global`, `$GLOBALS` or superglobal access (`twig-global-state-audit.md`). The remaining work is the legacy writers, the 68 unconverted root scripts, and the `lib/` functions they call.

## Global Constraints

- `main` only. `lts/1.2` and `1.2.x` are unchanged by every task in this plan.
- PHP floor 8.4. Run everything through `mise exec --`; a result from another PHP version is not evidence.
- Namespace `Kadupul\<Module>\...`, layout `src/<Module>/{Domain,Application/{Command,Query,Port,ReadModel},Contract,Infrastructure/{Symfony/Controller,Symfony/Form,Persistence,Legacy}}`.
- Controllers are `final readonly class` with `__invoke`, `#[Route]` attributes and action-argument injection of `Environment`, `TranslatorInterface` and ports, matching `AdministrationListController`. Do not extend `AbstractController`.
- Every controller resolves the actor through `ConsoleAccess` (or the module's access port) before reading protected data, and every response sets `Cache-Control: private, no-store`.
- Templates live in `templates/<module>/`, extend `templates/base.html.twig`, reuse the legacy IDs and classes listed in `docs/symfony-migration.md` "Console layout and list templates", and use `_list.html.twig` macros and `_list_filter.html.twig` for lists.
- Every visible string is a message key in a per-module domain with both `config/translations/<domain>.en.yaml` and `<domain>.fr.yaml`.
- Persistence is explicit DBAL with bound values and allowlisted sort columns. Use Doctrine ORM only where entity lifecycle justifies it (none of the batches below require it).
- Twig pages request only local assets. A new browser dependency is pinned in `package.json`/`package-lock.json` and added to `tools/verify-offline.php` first.
- New files carry SPDX `GPL-3.0-or-later` with the Kadupul copyright line; files derived from Cacti code keep `GPL-2.0-or-later` and both copyright lines.
- PHP idioms (8.0 to 8.4): `readonly` classes and promoted properties, backed enums for screens/actions/states, `match`, first-class callables, `#[\Override]`, typed class constants, `array_find`/`array_any`/`array_all`, `new Foo()->bar()` without wrapping parentheses, property hooks and asymmetric visibility only where they replace a getter/setter pair. No new `global`, `$GLOBALS` or superglobal access in `src/` (enforced by `ArchitectureTest` and `GlobalStatementInventoryTest`).
- PER-CS conversion of an existing file is its own whitespace-only commit; run `tests/tools/check_php_style.sh` before every push.
- Plugins remain trusted in-process code under the existing include contract. A Twig page never claims to sandbox a plugin.
- A legacy writer is deleted only in the batch's retirement task, after its Symfony replacement has shipped in a release and the shim forwards the old URL.
- Hard-stop domains (authentication, sessions, permissions, CSRF, secrets) are delegated, get a dedicated security review, and an opus-tier architect verification.
- Commits: Conventional Commits, `git commit -s`, one intent per commit, at least 60 seconds apart; batches go out as their own PRs with the merge order stated in each body.

## Review Focus

1. **Plugin chrome on Twig pages.** A plugin that adds menu entries or tabs through `config_arrays`, `top_header_tabs` or `draw_navigation_text` disappears on a migrated page. Expected: menu entries still appear. Task 0.5 handles `config_arrays` menu entries only (`PluginMenuParityTest`). `top_header_tabs` and `draw_navigation_text` remain open: Twig pages still render neither, and each batch that migrates a page a plugin decorates through them must close that gap or record it.
2. **Subdirectory installs.** `url_path` of `/cacti/` with the repository-root front controller. Expected: every link, form action and redirect stays under `/cacti/`. Owning task: slice recipe step R6 (`url_path` fixture in every route test).
3. **Bulk action at the page-size maximum.** Selecting every row at the largest supported `rows` value and confirming a delete. Expected: one confirmation page, bounded queries, no per-row worker. Owning task: Task 0.4 query-count budget plus recipe step R5.
4. **Offline or recovering remote collector.** A Twig page served on a collector whose `poller_id` is not the primary and whose main database is unreachable. Expected: the four-entry offline menu and a controlled 503, never a stack trace. Owning task: recipe step R6 (`collector_offline` case).
5. **French locale through user preference.** `user_auth.lang` set to `fr`, not a test-forced locale. Expected: translated strings and pluralization on the migrated page. Owning task: recipe step R6 (`InventoryLocaleSubscriber` path).

---

## Phase 0: Shared gates and building blocks

Phase 0 ships before any further page migration. Each task is one PR.

### Task 0.1: Legacy web page ratchet

Main already classifies every entry point in `tests/security/build_entry_point_inventory.py` and `classify_entry_points.php`, with the result in `tests/security/baselines/entry_points.baseline.tsv` (shims show `symfony:forward`). This task adds only the ratchet.

**Files:**
- Create: `tests/security/check_legacy_page_ratchet.py`, `tests/security/test_check_legacy_page_ratchet.py`
- Modify: `.github/workflows/security-proof.yml`

**Interfaces:**
- Produces: `python tests/security/check_legacy_page_ratchet.py --base REF`. Counts root `*.php` rows whose gate is not `symfony:*`, `cli-only` or `web-server-denied`, at HEAD and at REF, and fails naming any new legacy page when HEAD's count is higher. A baseline missing at REF is a failure.

- [ ] **Step 1: Write the failing tests:** count falls, count equal, new `realm:5` root page fails and is named, new shim passes, non-root rows ignored, missing base baseline fails.
- [ ] **Step 2: Run** `mise exec -- python tests/security/test_check_legacy_page_ratchet.py` and confirm FAIL.
- [ ] **Step 3: Implement** the script and wire it into `security-proof.yml` beside the entry-point inventory steps.
- [ ] **Step 4: Run** the new tests and `tests/security/test_entry_point_classifier.py`. Expected: PASS.
- [ ] **Step 5: Commit** `test(security): ratchet legacy web pages in the entry-point inventory`.

### Task 0.2: One compatibility shim

Main already had `Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder::run()`. Six shims used it; ten (`automation_templates.php`, `cdef.php`, `color.php`, `data_input.php`, `data_source_profiles.php`, `gprint_presets.php`, `host.php`, `links.php`, `sites.php`, `vdef.php`) inlined a copy of its request duplication. This task converts those ten instead of adding a second entry helper, so no `config/legacy_entry.php` exists and the entry-point classifier needs no new shape.

**Files:**
- Modify: the ten shims above
- Test: `tests/Symfony/LegacyPageForwarderTest.php`

**Interfaces:**
- Uses: `LegacyPageForwarder::run(KernelInterface $kernel, string $projectDir, string $fixedUri)`. A shim is the SPDX header, `$kernel = require __DIR__ . '/config/bootstrap.php';` and one `run()` call naming its `app.php/.../legacy` route. `vdef.php` keeps its non-GET 409 guard ahead of the call.

- [ ] **Step 1: Write the failing test:** every `symfony:forward` root entry calls `LegacyPageForwarder::run()` and none duplicates the request inline.
- [ ] **Step 2: Run** and confirm FAIL for the ten inline shims.
- [ ] **Step 3: Convert** the ten shims.
- [ ] **Step 4: Run** `LegacyPageForwarderTest`, `tests/security/verify_entry_point_inventory.sh` and `tests/tools/check_all_pages.sh`. Expected: PASS, baseline unchanged.
- [ ] **Step 5: Commit** `refactor(platform): forward every legacy shim through LegacyPageForwarder`.

### Task 0.3: Request profiling

Profiling uses the framework's own collectors: WebProfilerBundle and Stopwatch in dev and test, and DoctrineBundle's `DebugDataHolder` for query counts. DoctrineBundle attaches its debug middleware only to connections profiled under `kernel.debug`, so a Symfony query count exists only when the kernel runs with debug; without it the count is `null`, never 0. No custom DBAL middleware or counter was written.

**Files:**
- Modify: `composer.json`, `composer.lock` (require-dev `symfony/web-profiler-bundle` and `symfony/stopwatch`, 7.4)
- Modify: `config/bundles.php` (`WebProfilerBundle` for dev and test)
- Create: `config/packages/web_profiler.yaml` (`when@dev`, `when@test`), `config/routes/web_profiler.yaml`
- Create: `src/Platform/Infrastructure/Profiling/RequestProfileLog.php`, `src/Platform/Infrastructure/Symfony/ServerTiming.php`
- Create: `include/request_profile.php`; modify `include/global.php` to include it and `lib/database.php` to add `db_statement_count()` at `db_execute_prepared()`, the single statement-execution choke point
- Test: `tests/Symfony/ServerTimingTest.php`, `tests/Symfony/LegacyRequestProfileTest.php` with `tests/Fixtures/legacy-request-profile-native.php`

**Interfaces:**
- Produces: `final readonly class RequestProfileLog` with `append(string $id, string $path, float $ms, ?int $queries, int $peakBytes): void`, writing one JSON line `{"id","path","ms","queries","peak_bytes"}` to `var/profile/requests.jsonl` or the path in `KADUPUL_PROFILE_LOG`. `id` is the `X-Kadupul-Profile-Id` request header (1 to 64 of `[A-Za-z0-9-]`); no valid header, no record.
- Produces: `final readonly class ServerTiming`, defined only for `#[When('dev')]` and `#[When('test')]`, listening on `kernel.response` (adds `Server-Timing: app;dur=<ms>, db;desc="<n> queries", mem;desc="<peak bytes>"`, with `db` omitted when there is no count) and `kernel.terminate` (calls `RequestProfileLog::append`). The production container has no profiler and no listener.
- Legacy pages stream their output, so a header cannot carry their final timing. With `KADUPUL_PROFILE=1` only, `include/request_profile.php` registers a shutdown function that appends the same record with `db_statement_count()`, or `null` when the counter is not loaded. Legacy counts are a lower bound: `db_cacti_initialized()`, `lib/graph_data_removal.php` and `lib/api_aggregate.php` prepare statements directly.

Justification for the new dev dependencies (record in the PR body): the profiler and stopwatch are the framework's own collectors, are dev/test only, and are excluded from `tools/build-offline.py` by its `--no-dev` install.

- [ ] **Step 1: Write the failing tests:** Server-Timing in the test env, two DBAL queries reported as 2, no count without debug, no profiler or listener in the prod container, one JSON line per request carrying the id, nothing without the id, the legacy writer recording its count with `KADUPUL_PROFILE=1`, `null` without the counter, and nothing when `KADUPUL_PROFILE` is `0` or absent.
- [ ] **Step 2: Run** and confirm FAIL.
- [ ] **Step 3: Implement** the listener, log, legacy writer and configuration.
- [ ] **Step 4: Run** the tests plus `mise exec -- php tools/verify-offline.php` on an offline build to prove no dev bundle ships. Expected: PASS.
- [ ] **Step 5: Commit** `feat(platform): profile requests in dev and test`.

### Task 0.4: Route profile and performance budgets

**Files:**
- Create: `tests/tools/profile_routes.py`, `tests/tools/test_profile_routes.py`, `tests/tools/route-pairs.json`
- Create: `tests/performance/route-budgets.json`, `tests/performance/route-baseline.json`
- Create: `tests/e2e/docker-compose.profile.yml` (passes `APP_ENV`, `APP_DEBUG` and `KADUPUL_PROFILE` to the stack)
- Create: `.github/workflows/route-profile.yml` (manual dispatch, or a PR when the `performance` label is added or a labelled PR is updated; same pinned actions and `register_ci_php.sh` steps as `pest-profile.yml`)

**Interfaces:**
- Produces: `profile_routes.py --base-url URL --pairs PAIRS.json --pass time|counts --out FILE` and `profile_routes.py --compare TIME.json COUNTS.json [--budgets FILE]`. Two passes on the `tests/e2e` stack, because the debug container that counts queries is slower than production and would penalize the Symfony side:
  - time: `APP_ENV=prod`, no `KADUPUL_PROFILE`; client wall time to the last byte, median and p95, after 3 warm-up runs.
  - counts: Symfony in `APP_ENV=test` with `APP_DEBUG=1`, legacy with `KADUPUL_PROFILE=1`. Every request carries a fresh `X-Kadupul-Profile-Id`; query count and peak memory come from Task 0.3's JSON lines, not from `Server-Timing`. The tool waits up to 2 s for records written in `kernel.terminate` after the response, and sums the records of every redirect hop, since urllib resends the header. A missing or `null` count is a failure, not zero.
  - Both passes alternate legacy and Symfony requests so host drift falls on both sides.
- Budget rule (from `docs/enterprise-readiness.md`, "Performance and capacity"): a migrated route fails if its median is more than 10% and more than 2 ms above the legacy median, or if it makes more queries than legacy; 0 Symfony queries against a counting legacy page also fails as a counter that is not recording. `route-budgets.json` stores accepted exceptions with a reason.
- Known limit: Symfony reads through `Platform\Contract\DatabaseConnection::get()` (raw PDO) are invisible to `DebugDataHolder`, so those routes undercount.

- [ ] **Step 1: Write failing Python tests** for median/p95, the time and query rules, the profile-log join, redirect hops, late records, and that a missing count is a failure, not zero.
- [ ] **Step 2: Run** `mise exec -- python tests/tools/test_profile_routes.py` and confirm FAIL.
- [ ] **Step 3: Implement** the tool and workflow.
- [ ] **Step 4: Record the first baseline** for the 16 forwarding shims against their direct `app.php` routes as `tests/performance/route-baseline.json`, stating the runner and fixture in the file. Re-record it after any change to how records are joined.
- [ ] **Step 5: Commit** `test(performance): profile legacy and Symfony routes`.

### Task 0.5: Patch-coverage gate and plugin menu parity

**Files:**
- Create: `tests/tools/patch_coverage.py`, `tests/tools/test_patch_coverage.py`
- Modify: `.github/workflows/sonarcloud.yml`
- Create: `bin/legacy-console-menu.php`, `src/Platform/Contract/PluginConsoleMenu.php`, `src/Platform/Infrastructure/Legacy/LegacyPluginConsoleMenu.php`, `src/Platform/Infrastructure/Legacy/ConsoleMenuRuntime.php`
- Modify: `src/Navigation/Infrastructure/Legacy/LegacyConsoleMenu.php`, `src/Platform/Infrastructure/Legacy/LegacyBrowserWorker.php`, `config/packages/cache.yaml`
- Test: `tests/Symfony/PluginMenuParityTest.php`, `tests/Fixtures/plugins/menu_parity/`, `tests/Symfony/plugin_menu_scenarios.py`

**Interfaces:**
- Produces: `python tests/tools/patch_coverage.py --base REV --clover tests/coverage.xml --clover var/symfony-coverage.xml --require 100 --paths 'src/**/*.php' 'config/**/*.php' 'lib/**/*.php' 'include/**/*.php' 'bin/**/*.php' 'cli/**/*.php' 'scripts/**/*.php' 'install/**/*.php' 'tools/**/*.php' '*.php' --exclude 'include/vendor/**'`. Fails when a changed executable line is uncovered in every report, when a changed line carries `@codeCoverageIgnore`, when a file inside a PHPUnit `<source>` include appears in no report, or when a report is missing or empty. `var/offline-coverage.xml` is not passed: it is Cobertura for Python files, and the offline PHP tools already merge into `var/symfony-coverage.xml`.
- Placement: a step in the `analyze` job of `sonarcloud.yml`, the only job holding both Clover reports, after the Sonar scan so a coverage failure still publishes Sonar results. It runs on `pull_request` (base `HEAD^1` of the merge checkout) and `merge_group` (base `github.event.merge_group.base_sha`). Because it lives in `analyze`, it runs only where the Sonar analysis runs: merge groups when `SONAR_ALL_PRS` is true, and PRs when `SONAR_ALL_PRS` is true or the branch is `sonar/*` or `release/*`. Ordinary PRs are not gated until that policy changes.
- Produces: `interface PluginConsoleMenu { entries(int $actorId, list<string> $sections): list<array{section, label, path, realm}> }`. `LegacyPluginConsoleMenu` starts `bin/legacy-console-menu.php` through `LegacyBrowserWorker` with the session cookie only, a 3-second budget, and caches the validated answer in the `cache.plugin_console_menu` pool for five minutes. The key holds the user ID, a fingerprint of enabled `config_arrays` hooks, `plugin_config`, `plugin_realms` and the user's realms, the sections, the collector state, and the request, preferred and `Accept-Language` catalogue locales. A failed, timed-out or invalid answer is cached for 60 s under the same key. No enabled non-internal hook, no worker. `LegacyConsoleMenu` keeps entries that are not built in, carry a realm the user holds, and point at a relative app or plugin script.
- Twig templates have no line coverage. They are covered by rendering tests that assert DOM nodes for every branch (`{% if %}`/`{% for %}` empty and non-empty).

- [ ] **Step 1: Write failing tests** for the gate (uncovered changed line fails, unchanged uncovered line passes, ignore annotation on a changed line fails, missing report fails, unmeasured source file fails) and for menu parity (a fixture plugin's `config_arrays` entry appears for a user with its realm, through the real worker launcher).
- [ ] **Step 2: Run** and confirm FAIL.
- [ ] **Step 3: Implement** both.
- [ ] **Step 4: Run** both suites. Expected: PASS.
- [ ] **Step 5: Commit** each separately: `ci(coverage): require full coverage of changed lines`, then `fix(navigation): show plugin console menu entries`.

### Task 0.6: Shared action and delete templates

Each migrated family had its own confirmation and delete template. Before more families copy them, extract the parts that are actually shared.

**Files:**
- Create: `templates/_action_confirm.html.twig`, `templates/_item_delete.html.twig`
- Modify: `automation_template_delete`, `cdef_action`, `data_source_profile_action`, `gprint_preset_delete` and `palette_color_delete` (bulk confirmation); `cdef_item_delete`, `color_template_item_delete` and `data_source_profile_rra_delete` (item deletion)
- Test: `tests/Symfony/ActionTemplateRenderingTest.php` with fixtures in `tests/Symfony/Fixtures/action-rendering/`

**Interfaces:**
- `_action_confirm.html.twig`: variables `domain`, `cancel_url`, `heading`, `intro`, `used`, `used_message`, `form` (hidden `selection` and `revisions`); blocks `items` and `extra_fields`. The CDEF and profile duplicate pages add their `title_format` row through `extra_fields`.
- `_item_delete.html.twig`: variables `domain`, `cancel_url`, `heading`, `intro`, `blocked`, `blocked_message`, `form`; blocks `details` and `fields`.
- Callers pass the texts as `t('...', {}, '<domain>')` so `translation:extract` finds them.
- Not built: a `BulkAction` contract, `ActionSelection`, `BulkActionType` or `_edit_form.html.twig`. The families have no action enums, no form type builds action choices, and their title fields differ in name (`pattern`, `title`, `title_format`) and block prefix, which the submitted form must keep; a shared type would save about two lines per family. The edit templates share only `form_start`, errors and submit. `vdef_action`, `vdef_item_delete`, `color_template_action`, `site_action`, `link_action` and `data_input/action` keep their own templates because their markup differs.

- [ ] **Step 1: Capture fixtures** of the current output of every page above.
- [ ] **Step 2: Write the rendering tests** against those fixtures.
- [ ] **Step 3: Extract** the two shared templates and convert the eight pages.
- [ ] **Step 4: Run** `ActionTemplateRenderingTest`, `ListTemplateRenderingTest` and the families' route tests. Expected: fixtures unchanged.
- [ ] **Step 5: Commit** `refactor(platform): share bulk action and delete templates`.

---

## Slice recipe (Phases 1 to 7)

Every page in every batch goes through these steps. The batch plan lists, per page, the routes, ports, forms, templates and tests with exact names; this recipe fixes what each step must produce.

- **R1. Characterize.** Record the legacy page's GET and every POST action as goldens under `tests/Golden/` through the behavioral harness: status, redirects, persisted rows, plugin hook calls, flash messages. Include the empty, invalid-ID, denied, and maximum-selection cases.
- **R2. Read model.** `Application/Query/<Verb><Noun>` invokable plus a `Port/<Noun>Catalog` and `Infrastructure/Persistence/Dbal<Noun>Catalog`. Lists reuse the `ListScreen`/`ViewAdministrationList` machinery or the management-catalog reader when the page fits them; a new reader is justified in the batch plan.
- **R3. Write path.** `Application/Command/<Verb><Noun>` with a `Port/<Noun>Editor`. The first implementation may be `Infrastructure/Legacy/Legacy<Noun>Editor` calling the existing `api_*` function through the isolated worker, as CDEF and VDEF do; the batch's retirement task replaces it with a DBAL editor. Plugin hooks fire with the same names, arguments and order as the golden.
- **R4. Controller and form.** One invokable controller per screen kind (list, edit, item, action), `#[Route]` with `methods`, Symfony Form types with Validator constraints and CSRF. An action form carries hidden `selection` and `revisions` fields as the existing families do; introduce a shared action contract only when a batch has real shared logic to put in it.
- **R5. Template.** Extends `base.html.twig`, uses `_list.html.twig`, `_action_confirm.html.twig` and `_item_delete.html.twig` where the page fits their shape; no inline handlers (CSP); translation keys in the module domain, en and fr, passed to the shared templates with `t()`.
- **R6. Tests.** Route test per controller covering: success, invalid input (400 before any write), unauthenticated (401), denied realm (403), missing record (404), stale revision, `url_path=/cacti/`, `user_auth.lang=fr`, collector offline. Use-case unit tests for each branch. A query-count assertion where a list or bulk action is involved. Changed lines must reach 100% under Task 0.5's gate.
- **R7. Shim.** Replace the root script with a `LegacyPageForwarder::run()` shim (Task 0.2) to the `.../legacy` compatibility route, which maps the old query parameters (`action`, `id`, `drp_action`, `selected_items`) onto the new routes. Task 0.1's baseline drops by one `ui` entry.
- **R8. Profile.** Add the page's pairs to `PAIRS.json`, run Task 0.4, commit the result. A budget breach blocks the batch unless `route-budgets.json` records why.
- **R9. Globals.** Rerun `global_statement_inventory.php` and lower the baseline by everything the slice removed. Do not remove a `lib/` global that another unmigrated caller still needs; record it instead.
- **R10. Document.** `docs/migrations/<batch>.md` in the format of `administration-lists-batch-10.md` (scope, authorization, behavior differences, verification, rollback), a section in `docs/symfony-migration.md`, and the affected rows of `docs/enterprise-readiness.md`.
- **R11. Retire (separate PR, one release later).** Delete the legacy page body and its now-unused `lib/` functions, swap `Legacy<Noun>Editor` for the DBAL editor, rerun goldens, lower both ratchets.

## Phases and batches

Order is by risk and by dependency: later batches reuse readers and forms from earlier ones. Line counts are from the starting point.

| Phase | Batch | Pages | Lines | Notes |
| --- | --- | --- | --- | --- |
| 1 | Templates | `graph_templates.php`, `graph_templates_items.php`, `graph_templates_inputs.php`, `data_templates.php`, `host_templates.php`, `aggregate_templates.php`, `data_queries.php` | 6,620 | Lists already native; this moves editors and actions. |
| 1 | Automation | `automation_graph_rules.php`, `automation_tree_rules.php`, `automation_networks.php`, `automation_snmp.php`, `automation_devices.php` | 4,861 | Shared rule-item editor between graph and tree rules. |
| 2 | Graphs and data sources | `graphs.php`, `graphs_items.php`, `graphs_new.php`, `data_sources.php`, `aggregate_graphs.php`, `data_debug.php` | 9,475 | Largest write surface; split into two batch plans if the first exceeds Copilot's 20,000-line review limit. |
| 2 | Trees | `tree.php` | 2,447 | Drag-and-drop ordering needs a pinned local script (Global Constraints). |
| 3 | Graph viewing | `graph_view.php`, `graph.php`, `graph_realtime.php` (Twig); `graph_image.php`, `graph_json.php`, `graph_xport.php` (controllers returning image, JSON, CSV) | 3,053 | Uses `docs/migrations/graphing-render-pipeline.md`; profile image latency separately. |
| 4 | Administration | `settings.php`, `pollers.php`, `managers.php`, `plugins.php` (management UI only), `rrdcleaner.php`, `spikekill.php`, `link.php`, `clog.php`, `clog_user.php`, `reports_admin.php`, `reports_user.php` | 5,868 | `settings.php` tabs come from `$settings` arrays and plugin hooks; build the form from a settings catalog port. |
| 4 | Utilities | `utilities.php` | 3,540 | Split by tab; diagnostics views exist already. |
| 4 | Packages | `templates_import.php`, `templates_export.php`, `package_import.php` | 1,826 | File upload and signature checks; path-containment rules in the root `AGENTS.md` apply. |
| 5 | Identity (hard stop) | `auth_login.php`, `login.php`, `logout.php`, `auth_changepassword.php`, `auth_profile.php`, `user_admin.php`, `user_group_admin.php`, `user_domains.php` | 7,081 | Moves to the Symfony Security firewall (decided 2026-10-27): `symfony/security-bundle` 7.4, a `UserProvider` over `user_auth`, custom authenticators for local, LDAP and Web Basic realms, Symfony remember-me with the existing revocation generation, login throttling, and Security CSRF replacing CSRF Magic on migrated forms. Starts with an ADR covering session sharing with unmigrated legacy pages during the transition, password-hash compatibility, and lockout parity. Delegated, security-reviewed, opus verification. |
| 6 | Process entry points | `poller.php`, `cmd.php`, `cmd_realtime.php`, `script_server.php`, `cactid.php`, `poller_automation.php`, `poller_boost.php`, `poller_commands.php`, `poller_dsstats.php`, `poller_maintenance.php`, `poller_realtime.php`, `poller_recovery.php`, `poller_reports.php`, `poller_rrdcheck.php`, `poller_spikekill.php`, `snmpagent_*.php`, `service_check.php` | 10,138 | Symfony Console commands, not Twig, following `docs/migrations/cli-symfony-console-roadmap.md`. Old filenames stay as wrappers because cron, systemd units and Spine call them. Profile poll-cycle duration, not request latency. |
| 6 | Remote agent | `remote_agent.php` | 652 | JSON controller; collector authentication is a hard-stop domain. |
| 7 | Globals and bootstrap | `lib/`, `include/`, `cli/` | n/a | Continue `docs/migration/functions-decomposition.md`. Replace `$config`, `$database_*`, `$plugin_hooks` and `$user_auth_realm*` reads with injected services once Task 0.1 shows no `ui` caller remains. Retire `include/global.php` from web paths last; the document root moves to `public/` only after this. |

## Coverage target

100% of changed lines in every PR (Task 0.5) is enforceable and is the gate. Whole-repository line coverage cannot reach 100% while `lib/` still runs under `include/global.php` without a database, and `tests/integration` subprocess coverage is not yet recorded. Track it instead: each retirement task (R11) deletes uncovered legacy lines, and Sonar's overall coverage is reported per batch. A file excluded from coverage must have a reason in `tests/phpunit-coverage.xml`, as today.

## Self-review notes

- Spec coverage: Twig for every web page (Phases 1 to 5, R4/R5); whole workflow including writes (R3, R11); profiling (Tasks 0.3, 0.4, R8); coverage (Task 0.5, R6); globals (R9, Phase 7); PHP 8.4 idioms and Kadupul idioms (Global Constraints); DRY (Tasks 0.1, 0.2, 0.6 and the reuse rule in R2).
- Coverage gate scope: Task 0.5's gate runs only where Sonar analysis runs. Whether ordinary PRs get the gate (by setting `SONAR_ALL_PRS` or moving the gate) is an open policy decision.
- Not converted to Twig by design: process entry points (Phase 6) and non-HTML endpoints, because they produce no HTML.
- Decided 2026-10-27: identity moves to the Symfony Security firewall. Until Phase 5 ships, unmigrated legacy pages must still accept the firewall's session, so the ADR defines one session owner and how the legacy bootstrap reads it.

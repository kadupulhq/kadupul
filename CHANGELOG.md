# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

- Complete Inventory site editing, sorting, duplication and deletion through Symfony; retire the procedural Sites page while retaining safe legacy URL compatibility.
Targeting `v1.3.0`, the first planned application release. See
[VERSIONING.md](VERSIONING.md).

### Fixed

- Accept only a number or `U` as a data source minimum, and only a number, `U` or an interface speed token as a maximum, refuse to create an RRD file whose stored minimum is anything else, and create realtime graph RRD files through the RRDtool pipe instead of a shell. A data source item that fails validation is no longer saved.

- When running as root, never change the owner or group of a new RRD file or directory through a symbolic link, or of one that resolves outside the RRA directory.

- Start RRDtool without a shell, so an RRDtool binary path containing a blank works for graphs, tuning and RRD writes. The path must name the executable alone; extra arguments or shell syntax in it now stop RRD writes as well.

- Send the RRD paths in exports and graphs to the RRDtool proxy bare and relative to the RRA directory, which is how the proxy reads them, so CSV export and other exports work through it. Graph images still fail against rrdproxy 54aad57; see `docs/migrations/graphing-rrd.md`.

- Clear each converted table from the installer's queue. It wrote a setting named `0` instead, so the queue was never cleared.

- Restore the RRDtool proxy client, which could not connect on phpseclib 4. It now checks the proxy's key fingerprint in constant time, gives up on a key exchange that is oversized or too slow, and never falls back to unencrypted frames. A default font path with a blank or a quote is no longer sent to the proxy. Fixes #399.

- Leave the data source type alone when tuning an RRD file with an empty or unknown type, instead of raising a PHP warning and, for an unknown type, sending RRDtool an empty type.

- Stop creating the structured-path directory for an RRD file when only showing its RRDtool create command.

- Pass `--y-grid` and `--units-exponent` to RRDtool once, quoted, instead of twice with the exponent once unquoted. The graph renders the same.

- Mark stacked areas as stacked in graph export metadata, and key that metadata, and the name given to an unnamed export column, by the column's own number. The flag compared against a type name no item has, and the numbering started after the count of every graph item, so no key matched a column.

- Show a blank line, not a NUL byte and `x27`, between the message and the file name in the graph error image for a missing or unwritable RRD file, and show a file outside the Kadupul directory as a custom RRA folder instead of its full directory.

- Quote RRD file paths, and data source maximums taken from device data, in the RRDtool commands that create, update, fetch, inspect, dump, restore, remove and archive RRD files, including Boost, RRD check and Data Source statistics, so a path with a space or a quote works and neither value can add arguments to the command.

- Quote the graph arguments Kadupul writes to RRDtool, such as data source paths in DEF clauses, legend, GPRINT and COMMENT text, axis options and font names, the way RRDtool reads them rather than the way a shell does. A single quote in one of these values made RRDtool reject the whole graph, and a pair of them left stray backslashes in the text. The quoting is now the same on Windows, where values used to be wrapped in double quotes with backslash escapes that RRDtool does not honour. `|host_*|` and `|query_*|` values in axis labels and the other graph options are now substituted before quoting, so a quote in them stays inside the argument. A value containing a NUL byte now produces the graph error image, and RRD tuning refuses one, instead of failing with a PHP error.

- Quote graph titles and vertical labels for RRDtool after substituting `|host_*|` and `|query_*|` values, not before. A quote in a substituted value, such as a device description, ended the argument early, so the graph failed to render or showed a mangled title.

- Match the whole filename against the rotation format before purging a log. Cleanup accepted any name containing the log basename plus an eight digit run, so a neighbouring file with an old date in its name was deleted.

- Clean each configured log once during rotation; a call after the loop re-scanned whichever log the loop left behind and counted it twice.

- Keep `.githooks/install` from replacing a `core.hooksPath` that is set to an empty value. Git reads an empty value as "run no hooks", so the installer treated a deliberate choice as though nothing were configured and silently turned hooks back on.

- Describe the selectors `cli/remove_graphs.php` actually accepts. Its help called `--graph-template-id` mandatory when any one of the four selectors is enough, and did not mention that an empty selection is refused without `--all` or that a bare `--list` lists every Graph.

- Pass `$rdatabase_retries` when a remote poller connects to the main server. Every other argument came from its `$rdatabase_` counterpart, so configuring the remote retry count alone had no effect and the local value governed the retry policy for a remote host.

- Read the current group of a newly created structured RRD directory with `filegroup()` rather than `fileowner()` in both `lib/rrd.php` and `lib/boost.php`, so a root-run poller no longer skips a needed `chgrp` when the directory UID happens to equal the target GID, nor runs one when the group is already correct.

- End the `--bulk_walk` case in `cli/change_device.php`, which fell through to the version branch so a valid size printed the version banner and exited without applying the change or reading later arguments.

- Map a numeric `--disable` in `cli/change_device.php` the way its help and `cli/add_device.php` do, with 1 disabling polling and 0 enabling it, and refuse a numeric value that is neither instead of reading every nonzero value as enable.

- Deny web access under Apache to the internal paths Nginx denies, and stop two command-line scripts from running over HTTP.

- Check every changed PHP file in the style check; a large file list could make it skip some.

- Commit through PDO rather than the MariaDB-only `@@in_transaction` variable, so device edits, creates, template assignments, collector moves and bulk state changes commit on MySQL instead of rolling back and reporting an uncertain outcome.

### Changed

- Bind the project directory once in the service configuration and share the command-line preflight with `kadupul:database:analyze`. Behaviour is unchanged.

- Run cli/convert_tables.php through kadupul:database:convert-tables, with --json and --dry-run. The flags are unchanged apart from the broken --installer. The command now requires an operator with the Console Access and Installation/Upgrades realms, and never sends DDL for a table name the server does not list. While nobody holds Installation/Upgrades, a direct Settings/Utilities grant counts for it, as on the web, without writing a realm row.

- Convert the install wizard's queued tables in-process instead of running cli/convert_tables.php, with no operator. On a remote collector it converts the collector's local database, which its queue describes, where the script converted main. A conversion that throws logs `Converting Table #N 'name' failed in-process:` with the exception class and leaves the table queued.

- Run cli/fix_mediumint.php through kadupul:database:widen-id-columns, with --json and --dry-run. Each table now gets its own statement, as the 1.2.17 upgrade does, and bigint or non-integer columns are no longer rewritten. The command requires the Console Access and Installation/Upgrades realms, with the same Settings/Utilities fallback while nobody holds Installation/Upgrades.

- Run cli/analyze_database.php through a Symfony command, and add kadupul:database:analyze with --json and an explicit operator. The flags are unchanged. The command now requires an operator with the Console Access and Settings/Utilities realms.

- Configure the `local`, `main` and `web` database connections through DoctrineBundle, which fills credentials from `include/config.php` when a connection opens. The Inventory reads now use the `web` connection. The bundle is added for idiomatic DBAL configuration. Its `doctrine:database:create`, `doctrine:database:drop` and `dbal:run-sql` console commands are removed, because the installer owns the schema.

- Normalize malformed device-removal worker acknowledgements to the safe uncertain-outcome response.

- Add Symfony device-removal confirmation with graph/data retention choices, shared-dependency protection and verified remote cleanup.

- Record structured audit events for site creation, editing, deletion and duplication, device creation, and device template assignment, using the correlation-aware contract that device edit introduced.

- Accept optional `$database_read_username` and `$database_read_password` so the Inventory DBAL read connection can use a SELECT-only MySQL user, rejecting a half-configured pair.

- Keep the Inventory DBAL connection lazy through unauthenticated requests and enforce the documented no-remote-assets boundary for migrated Twig pages.

- Use Doctrine DBAL behind the existing Inventory assignable-site read port while preserving installation TLS settings and application APIs.

- Read device-creation defaults and choices through Doctrine DBAL behind the existing port, keeping stored SNMP credentials out of the query.

- Read the device-site filter, device details and site catalog through Doctrine DBAL, with visibility policies read on the same connection and the locked write checks unchanged.

- Add a versioned, correlation-aware audit contract for migrated writes and record structured device-edit persistence decisions and outcomes without submitted fields or credentials.

- Stop device collector reassignment immediately when the legacy replication helper reports an unavailable collector, before further graph replication.

- Preserve four-byte device text in bulk-state workers and verify primary/remote SQL modes and encodings at runtime before enable and disable writes.

- Supply the required MIB identity in SNMP cache upserts so device status callbacks work with strict SQL mode.

- Add Symfony bulk device enable/disable confirmation with whole-selection authorization and revision checks, transactional primary writes and verified remote state.

- Require strict SQL mode on bulk-state collector connections and restore it on the primary after remote setup, rejecting writes if validation cannot be enabled.

- Share collector/template assignment bootstrap, ordered locking, subprocess protocol and form validation without changing their distinct write workflows.

- Add Symfony device-collector reassignment with verified replication, previous-collector cleanup, stale-form protection and explicit uncertain-outcome handling.

- Add a Symfony device-template assignment workflow with authorization, stale-form protection and collector association verification.

- Add Symfony SNMP device editing with explicit credential replacement and worker-only resolution of stored secrets.

- Add Symfony device polling-settings editing with shared domain validation, legacy sentinel preservation and stale-form protection.

- Add Symfony device site reassignment with stale-form detection, ordered site locks, explicit unassignment, and transactional cache invalidation.

- Add a Symfony row-cache maintenance command with read-only backlog inspection, JSON output and explicit bounded cleanup sharing the Scheduler worker lock.

- Add opt-in Symfony Scheduler ownership of primary-collector row-count cache cleanup through an IdentityAccess application handler, with bounded deletion, persisted checkpoints and worker locking.

- Keep configured SNMPv3 usernames out of the device-creation HTTP catalog; resolve defaults inside the authorized worker before persistence.

- Add Symfony Form/Twig device creation through an Inventory use case and isolated legacy adapter, preserving template and plugin behavior while keeping configured credentials out of the page.

- Verify required device template associations and remote collector association parity before confirming device creation; report partial replication as an uncertain outcome.

- Add site creation through Symfony Forms, Twig and an Inventory use case, with transactional persistence, legacy cache invalidation and full site-field validation.

- Route administrator notifications through a Symfony-owned Alerting use case, use Symfony Mailer for supported SMTP configurations, and retain legacy delivery compatibility without resending failed SMTP messages.

- Add `kadupul:mail:test`, a Symfony Mailer console command behind an Alerting application port, using existing SMTP settings with explicit TLS requirements and sanitized failures.

- Extend Symfony Translation to device list, details and edit screens, preserving device data and JSON/CSV representations.

- Translate the Symfony site list and editor with Symfony Translation, English/French catalogs and read-only compatibility with existing language preferences.

- Raise the minimum PHP version on main to 8.4, including Composer, development tooling, CI and offline/install verification. LTS keeps its existing runtime floor.

- Migrate site name and notes editing to Symfony Forms with domain validation, stateless CSRF, transactional writes and stale-edit protection.

- Add a Symfony/Twig site catalog with bounded search and pagination, realm-based administration access, and permission-filtered device counts.

- Export the current Symfony Inventory page as a permission-filtered CSV download with spreadsheet-safe text cells.

- Migrate device location and external ID editing to Symfony with Unicode-aware domain validation and stale-edit detection.

- Enable and disable device polling through the Symfony editor, including polling state in stale-edit protection and preserving legacy save effects.

- Add Symfony device editing for name, address and notes with domain validation, CSRF protection, stale-edit detection and an isolated legacy save adapter.

- Make Symfony own migrated HTTP requests and add an Inventory device list with Twig rendering, module ports, permission-filtered queries, and shared-session adapters.
- Require token-protected POST for plugin lifecycle, remote status, and ordering changes; retain uninstall confirmation.
- Require validated POST intent for interactive spike removal, including dry runs, and migrate its browser request to send a CSRF token.
- Bridge legacy authenticated sessions into a read-only Symfony identity query, with account and console-realm checks.
- Escape imported preview fields and restrict rich change-summary markup to safe formatting.
- Require validated POST requests for bulk-action confirmation and execution across core administration controllers; retain read-only navigation.
- Require token-protected POST requests for user/group policy, permission, and bulk mutations.
- Begin the Symfony 7.4 migration with a standalone kernel, console, routing and Twig foundation; require PHP 8.4 or later on main.
- Install generated dependencies with Composer and npm instead of tracking vendor trees, and provide a dependency-complete offline bundle build.
- Require validated POST requests for graph-template input mutations and allowlist graph-item columns across editing, XML import, duplication, rendering, and propagation before persistence or SQL construction.
- Restrict main installer PHP probes to a server-configured executable allowlist; leave LTS behavior unchanged.
- Require token-protected POST requests for installer JSON operations.
- Require POST and valid CSRF tokens before graph-template bulk mutations.
- Probe the configured PHP binary without a shell, reject failed probes, and bound installer probe execution time.
- Refresh bundled DOMPurify, D3, jQuery UI, tablesorter and HTML Purifier, pin Composer resolution to the branch PHP runtime floor, and verify browser-library provenance and compatibility in CI.
- Move main to phpseclib 4.x and PHP >=8.4; retain phpseclib 3.x and existing PHP support on `lts/1.2`.
- Refresh flag-icons to 7.5.0 while retaining every configured language flag, both aspect ratios and the existing CSS class/path API.
- Update PHPMailer to 7.1.1 while retaining the current PHP floor, include paths and local translations; regenerate its bundled autoloader without development dependencies.

- Require complete behavioral scenario inventories and capture application-handler PHP diagnostics separately from prepend-recorder events.

- Preserve reproducible behavioral baseline references and count RRDtool acknowledgements in reachable polling, failed writes, unreachable-device polling, and missing-file fault contracts.

### Fixed

- Restore the `.DS_Store` ignore rule that a committed merge marker had replaced.

- Preserve the legacy Error device status in Symfony Inventory filtering, display and CSV exports.

- Deny internal application paths in the Nginx deployment and reject HTTP execution of command-line tools before bootstrap.

- Resume Symfony's shared identity through native strict cookie handling and measure Symfony, HTTP worker, browser-build and offline-release coverage in Sonar.

- Require token-protected POST for device reindexing, execute its PHP worker without a shell, and report worker failures instead of success.
- Execute realtime graph polling without a shell, validate poller identifiers, and stop graph rendering when polling fails.
- Execute input-whitelist updates without a shell, enforce token-protected POST, and report subprocess failures correctly.
- Bind aggregate-item replacement values in prepared queries, including the parent-ID delete predicate.
- Enforce first-request realm authorization after Basic authentication and reject disabled, missing or locked accounts before creating authenticated sessions.
- Reject client-supplied Basic identity headers, recheck enabled accounts on persisted sessions and remember-me restoration, and revoke credentials after successful account-disable saves.
- Reset shared mail translations between messages and honor the configured sendmail executable after transport initialization.

- Reject array-valued actions and require CSRF-validated POST requests for form login, password changes, profile saves, setting resets and session revocation while preserving server-authenticated Basic login.
- Quote the installer's PHP executable as one shell argument when validating binary locations.
- Enforce enabled-group report realms and authorize report item saves, edits and moves against their existing parent (#111).
- Reject traversal, absolute paths and symlink escapes in package file writes and previews while preserving supported script, resource and plugin destinations (#108).
- Package import now rejects files for a plugin whose directory is a symlink; install such plugins as real directories under `plugins/` (#108).

- Validate comparison provenance, hash Docker build exclusions, and emit exact capture hashes in behavioral reports.

- Compare behavioral captures from an explicit results directory when the controller and application use separate checkouts; document the Linux native self-test PHP prerequisite.
- Stop Boost fetch preparation after writer initialization fails; restore caller error settings and release only owned writers on exceptions.
- Make unsafe poller queue diagnostics available for translation.
- Include measured poller and dependency-failure integration execution in Sonar coverage, rejecting stale or incomplete evidence.

- Preserve hyphenated RRD data sources, verify durable queues after legacy upgrades, and allow remote database upgrades without unrelated local storage.

- Refuse web upgrades when the poller queue is volatile or unreadable, before changing the database.
- Retain complete rejected RRD groups for replay after schema repair, preserve timestamp ordering, and report refused RRD repairs as failures.

- Retain realtime samples when their field mapping cannot be read.

- Guard installer test POSIX checks on Windows runtimes.

- Honor forced-local Windows cleanup policy and disable the obsolete volatile queue swap.
- Validate Windows storage access, fail on unreadable maintenance queues, and clarify exclusive queue migration/probe modes.

- Preserve retryable poller samples in InnoDB. Follow the [durable-queue upgrade procedure](docs/upgrading-rrd-storage.md). Before code-only deployment, stop collectors, back up the database, run `php cli/upgrade_database.php --migrate-poller-queue`, and run `--check-rrd-storage` under every web and poller service account.
- Back off failed drains, throttle repeated notifications, distinguish untrusted storage from lock contention, and classify native proxy sample errors.

- Finish poller post-run services before reporting failed writes, and release writer leases between collection batches so maintenance can proceed.

- Keep Windows local RRD cleanup explicitly manual, preserve queued requests during rescans, and discard partial splice dumps after command failure.

- Require explicit RRD service UID/GID trust before collection after code-only upgrades; refuse unsafe storage and notify the configured administrator before spawning poller workers. Configure shared stores as documented in `docs/testing/spikekill-safety.md`.

- Preserve pending realtime and repair samples on writer, child-process, heartbeat, and database-count failures; support explicitly trusted separate RRD service accounts.

- Make RRD maintenance failure messages available for translation.

- Coordinate spike removal with synchronous, queued and tuning RRD writes so
  replacement cannot discard samples written while maintenance prepares its dump.

- Restart the installer correctly when upgrading a completed older version, and
  keep repeated CLI installation checks out of web-session log rendering.
- Validate release upgrades and snapshot rollback with real polling, graph rendering,
  plugin callbacks and checks that RRD bytes and database identities are preserved.

- Preserve requested spike-removal recovery snapshots and repair dry-run statistics.
  Port the LTS filesystem and RRDtool failure safeguards to the main branch.

- Preserve page context in online help and translated login legends in midwinter.
  Repair malformed midwinter links and two Portuguese translations.
- Preserve meaningful dates in behavioral observations and record incomplete
  manifests reliably when test setup fails.

- PHP 8.4 no longer reports a deprecation notice for the device export or the
  basic auth mapfile, which now pass the CSV escape argument explicitly.
  Parsing and output are unchanged.
- PHP 8.5 no longer reports deprecation notices for three semicolon case
  terminators, the `(double)` cast in spikekill, or `imagedestroy()`,
  `xml_parser_free()` and `curl_close()` calls that have had no effect since
  PHP 8.0.
- SNMP timeout warnings show the configured timeout in milliseconds with both
  PHP's SNMP extension and the bundled SNMP class. With the bundled class the
  timeout was divided by 1000 twice, so a 500 ms timeout was logged as
  `Timeout (0.5 ms)` by walks and `Timeout (1 ms)` by gets.
- Theme stylesheets no longer carry declarations browsers discard: an invalid
  `#white` colour in midwinter, paw, paper-plane and sunrise, `word-break-wrap`
  in midwinter, placeholder `#TODO` rules in the midwinter compact layouts, and
  `background-color` values the following `background` shorthands replaced in
  modern and paw. The modern theme's
  navigation bar hover and visited link styles apply again.
- Tables that hide columns to fit the window sort the kept column indexes as
  numbers.
- Local page help looks for the HTML page it was asked for under `docs/`, as
  the Local Page Help Only setting describes, instead of a Markdown file.
- Run the theme browser suite against the themes shipped by this fork using
  its standalone Playwright configuration.
- Install end-to-end test dependencies without npm lifecycle scripts, and
  download supercronic in the container image over HTTPS only.
- Open the project website, Discussions and Issues links from the midwinter
  theme and the installer with `noopener`.
- `locales/build_gettext.sh` prints its error message and exits non-zero
  when realpath or a gettext tool is missing, instead of exiting silently.
- Parse installer start and finish times that fall on a whole second instead
  of stopping the installer with a fatal error.

### Added

- Add tests that pin the graph, export, create, tune and fetch commands `lib/rrd.php` sends to RRDtool, so moving that file into the Graphing module can be checked against current output.

- Add a generated inventory of HTTP entry points and their gates, verified in CI, and a real-install sweep that requires anonymous, revoked-realm and console-only callers to be refused.

- Add a Symfony device details page with permission-filtered metadata, site, status and escaped notes, preserving inventory navigation.

- Filter Symfony Inventory by site, with permission-aware site choices and preserved list/export/editor context.

- Show and search device location and external ID in Symfony Inventory, and append both fields to the current-page CSV export.

- Preserve the Symfony inventory view through device editing, validation errors and saves.

- Sort Symfony Inventory by name or hostname in either direction, with stable page boundaries and matching CSV order.

- Filter Symfony Inventory by displayed device status across Twig, JSON, pagination and current-page CSV.

- SonarQube Cloud analysis for main and same-repository pull requests.
- Unit test coverage reported to SonarQube Cloud from a PHP 8.1 run of the
  tests that pass without a database.
- Update vendored phpseclib to 3.0.57 and constant_time_encoding to 3.1.3, and lock runtime dependencies.
- Behavioral characterization harness recording 34 contracts from a running
  1.2.31 install, with a differential runner so a rewrite of the internals can
  be compared against what an administrator, plugin or script actually sees.
- Repository scaffolding: continuous integration for PHP 8.1 through 8.4 and
  for JavaScript, Dependabot, issue and pull request templates, and the
  security policy.
- Versioning policy and this changelog.
- Semgrep scanning as a blocking gate, and CodeQL for JavaScript and workflows.
- Releases publish an offline deployment tarball with runtime dependencies
  vendored in, plus a checksum, and a multi-architecture container image.
- Release artefacts carry build provenance, the image is signed with cosign,
  and buildx writes an SBOM into the published index.
- The fork import plan, and the decision that the rename stops at the plugin
  API boundary.
- A production container image: multi-stage, base images pinned by digest, the
  application baked in, and every process running unprivileged.
- The migration design, and `tools/migrate/assess.php`, which reports what a
  existing installation holds and what would survive the move. Read only, so it is safe
  against production.

### Changed

- Keep Kadupul's staged-source and commit-message checks in repository-owned,
  opt-in Git hooks that follow the checked-in PHP style migration policy.
- Update HTML Purifier to 4.19.1.
- PHP files a change edits on main move to PER-CS 2.0 formatting, and CI checks
  only those files. The `lts/1.2` branch keeps upstream Cacti formatting.
- File headers carry SPDX copyright and license tags instead of the GPL notice box.
  Inherited files stay GPL-2.0-or-later and files Kadupul created are GPL-3.0-or-later.
- Run SonarQube Cloud analysis on pushes to main and on pull requests.
- Document the 1.2 long-term support line on `lts/1.2`, which targets `v1.2.32`.
- Leave nosemgrep-suppressed findings out of the Semgrep code scanning upload.
- Report an installation exception to the CLI installer as well as to the web installer.
- Refresh localized product names and compiled catalogs, with source-text fallback
  for translations awaiting review.
- Update the shipped graph-watermark default through the shared installer while
  preserving custom values across supported database encodings.
- Show clear graph-rendering failure feedback and handle an unavailable logo.
- Validate branding migrations in CI and reject missing advisory-tooling branches.
- Use Kadupul branding and project contacts across the interface and documentation.
- Remove optional author lists and project-history prose while retaining licensing.
- Build menus and autocomplete items from DOM nodes or DOMPurify output, and
  refuse non-HTTP redirects taken from AJAX responses.
- Exclude vendored libraries from CodeQL and drop workflows that never ran.
- Sanitise AJAX, session-message and DOM-copied markup with DOMPurify before
  inserting it, and build graph images from attribute values.


[Unreleased]: https://github.com/kadupulhq/kadupul/commits/main

# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

- Add Symfony Inventory tree/report placement using owning Graphing and Reporting contracts, authorization, revisions and transactional confirmation.

- Complete Inventory site editing, sorting, duplication and deletion through Symfony; retire the procedural Sites page while retaining safe legacy URL compatibility.
Targeting `v1.3.0`, the first planned application release. See
[VERSIONING.md](VERSIONING.md).

### Changed

- Add Symfony device maintenance for reindexing, query diagnostics, polling cache refresh, debug controls and connectivity checks with secret-safe plain-text results.

- Migrate device data-query associations and reindex settings to Symfony, retaining graph data and verifying primary/remote cache cleanup.

- Add Symfony device graph-template association editing with legacy automation hooks, stale-association protection, remote verification and existing-graph retention.

- Add Symfony bulk SNMP settings with explicit credential replacement, per-device credential validation and secret-safe failure responses.

- Add Symfony bulk site, template and collector assignments with whole-selection validation, primary rollback and verified collector replication.

- Add Symfony bulk location and polling option edits with explicit field selection, domain validation, stale-option protection and verified collector writes.

- Migrate device template synchronization to a Symfony confirmation and Inventory use case with current template locks and verified collector associations.

- Migrate bulk device statistics reset to a Symfony confirmation page and Inventory use case, with authorized selection checks and primary/remote failure handling.

- Normalize malformed device-removal worker acknowledgements to the safe uncertain-outcome response.

- Add Symfony device-removal confirmation with graph/data retention choices, shared-dependency protection and verified remote cleanup.

- Use Doctrine DBAL behind the existing Inventory assignable-site read port while preserving installation TLS settings and application APIs.

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

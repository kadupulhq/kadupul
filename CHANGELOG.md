# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

Targeting `v1.3.0`, the first planned application release. See
[VERSIONING.md](VERSIONING.md).

### Changed

- Require token-protected POST for plugin lifecycle, remote status, and ordering changes; retain uninstall confirmation.
- Require validated POST intent for interactive spike removal, including dry runs, and migrate its browser request to send a CSRF token.
- Escape imported preview fields and restrict rich change-summary markup to safe formatting.
- Require token-protected POST requests for user/group policy, permission, and bulk mutations.
- Require validated POST requests for graph-template input mutations and allowlist graph-item columns across editing, XML import, duplication, rendering, and propagation before persistence or SQL construction.
- Restrict main installer PHP probes to a server-configured executable allowlist; leave LTS behavior unchanged.
- Require token-protected POST requests for installer JSON operations.
- Require POST and valid CSRF tokens before graph-template bulk mutations.
- Probe the configured PHP binary without a shell, reject failed probes, and bound installer probe execution time.
- Refresh bundled DOMPurify, D3, jQuery UI, tablesorter and HTML Purifier, pin Composer resolution to the branch PHP runtime floor, and verify browser-library provenance and compatibility in CI.
- Move main to phpseclib 4.x and PHP >=8.1; retain phpseclib 3.x and existing PHP support on `lts/1.2`.
- Refresh flag-icons to 7.5.0 while retaining every configured language flag, both aspect ratios and the existing CSS class/path API.
- Update PHPMailer to 7.1.1 while retaining the current PHP floor, include paths and local translations; regenerate its bundled autoloader without development dependencies.

- Require complete behavioral scenario inventories and capture application-handler PHP diagnostics separately from prepend-recorder events.

- Preserve reproducible behavioral baseline references and count RRDtool acknowledgements in reachable polling, failed writes, unreachable-device polling, and missing-file fault contracts.

### Fixed

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

# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

### Fixed

- Treat missing or invalid Host Resources allocation units and negative disk samples as unknown instead of reporting raw units, raising a type error, or guessing an unsigned wrap. Fixes #243.

- Add a reusable local write transaction helper with caller-owned savepoints, persistent InnoDB checks on the selected PDO connection, and native MariaDB/MySQL regressions.

- Replicate complete Data Source Profile definitions before collector references, retaining existing collector rows if delivery fails.
- Coordinate all Data Source Profile definition writers with deletion and preserve unchanged legacy references.

- Index RRD input-field references on fresh installations and through a registered schema upgrade from main 1.2.31 or LTS 1.2.32, keeping reference locks scoped to the selected fields.
- Allow user settings and credential metadata to store the full user account ID range on fresh and upgraded databases.
- Move External Links into the Navigation Symfony module with Twig forms, transactional viewing grants, stale-order protection and safe legacy redirects.

- Write device poll status back by device id, so devices that share a hostname no longer overwrite each other. Fixes #688.
- Refresh DOMPurify to 3.4.16 and retain the application's sanitizer compatibility patches and source verification.

- Add the CSRF token only to same-origin XMLHttpRequest, jQuery and form posts in the installed CSRF Magic browser script. Form targets are read from the `action` attribute, so a control named `action` cannot hide them; relative URLs resolve against the document base; and token fields are withheld when a submit button's `formaction` points to another origin. The submit check is installed when the script loads rather than from `CsrfMagic.end()`, so an unclosed `plaintext`, `textarea`, `title`, `xmp` or comment that keeps the end-of-page call from running cannot switch it off. Browsers without `SubmitEvent.submitter` send no token from a form that has any button with a cross-origin `formaction`, or a non-POST/invalid `formmethod` override, including the form's same-origin buttons. The legacy dependency installer now applies checksum-verified patches recorded in `legacy-files.json`.
- Stop the CSRF Magic output handler from adding the token to forms that post to another origin. Only forms with no action or a relative action get the field from the server; absolute and protocol-relative actions are left to the browser script, which checks their origin. Attributes are read as the browser reads them, so a quoted `>`, a second `action`, character references, backslashes or control characters cannot hide the target, and any `<base href>` that is not relative withholds the field from relative actions too, even when it names this origin, and GET forms no longer receive the token when another attribute contains `method="post"`. Form tags inside comments or the text of `textarea`, `title`, `script`, `style` and similar elements get no token, nor does a form opened while an earlier form is still open, since the browser would move the token into the outer form. A `<base href>` only counts where the browser would parse it, and a page the handler cannot read to the end gets no token in relative-action forms. Tag names, attribute names, the method and URL schemes are compared as ASCII, since Kadupul sets `LC_CTYPE` from the user's language and in a Turkish locale `strtolower()` on PHP 8.1 and the PCRE `/i` flag do not fold `I` to `i`: `ACTION`, `SCRIPT` and `XI:` were misread there, and upper-case `SCRIPT` or `TITLE` elements stopped legitimate forms from getting the token.
- Read the document base URL in the CSRF Magic browser script through `Node.prototype`, so an element named `baseURI` cannot make every same-origin request lose its token.
- Port the remaining CSRF Magic library checks from 1.2: refuse more than eight submitted tokens, token times that are not digits or exceed the 300-second future clock-skew allowance, and generate fallback secrets with `random_bytes()`. The optional CSRF debug log and the default failure page no longer record tokens, the secret, form values or query strings. Secret rotation generates before modifying the working file, then exclusively writes and verifies a replacement in its directory, preserves existing UID/GID with verified ownership before applying mode 0640 and renaming atomically; generation or publication failure preserves the working key. Symlink file destinations are refused.
- Keep inaccessible SNMP cache entries as navigation-only links and return `NONE` for direct reads without PHP 8.4 warnings.

- Hide the unused line-width field for fixed LINE1/2/3 graph items and clarify that the editable width applies to LINE:STACK. Fixes #229.
- Scope remote-agent host operations to the requesting main poller and the receiver's assigned devices; require an authenticated session user for remote graph rendering.
- Make `plugin_manage.php --allperms` grant existing plugin realms to the configured administrator and report failed grants. Fixes #224.
- Reject plugin installs whose `INFO` compatibility floor is missing, malformed, or newer than the running core. Enforce the gate before install callbacks and return failure from the CLI. Related to #223.
- Escape dynamic form ids and actions for their HTML attribute and JavaScript string contexts. Fixes #582.
- Read legacy current-page and browser URL values through Symfony HttpFoundation while retaining the existing helper signatures, server-variable precedence, and URI sanitization. Refactors #484.
- Remove the inert Poller Refresh Output Table setting; the queue is required to use InnoDB. Fixes #282.
- Bound PCRE work when tree automation applies saved replacement patterns. Fixes #591.
- Return a clean 404 for HTTP requests to the PHP Script Server under PHP-FPM. Fixes #377.
- Honor the script server's documented `--environ`, `-v`/`-V`, and `-h`/`-H` options. Fixes #375 and #376.
- Require PHP CS Fixer 3.95.27 consistently in the staged-content hook and CI. Fixes #486.
- Own persistent local RRDtool pipe processes in the Graphing `LocalRrdtool` adapter while retaining the legacy procedural entry points. Fixes #500.
- Move RRDtool graph option generation into the Graphing module while keeping its procedural wrapper and output unchanged. Part of #502.
- Resolve ordered graph-item consolidation references in a Graphing collaborator while preserving GPRINT association behavior. Part of #502.
- Reuse one RRDtool proxy session for the commands in a graph render, including consolidation-function lookups. Part of #502.
- Preserve negative integer `--units-exponent` values accepted by graph forms. Fixes #228.
- Complete Inventory site editing, sorting, duplication and deletion through Symfony; retire the procedural Sites page while retaining safe legacy URL compatibility.
- Refresh the Midwinter theme's bundled hotkeys-js to 3.13.15 and ua-parser-js to 1.0.41, matching the 1.2 LTS branch.
Targeting `v1.3.0`, the first planned application release. See
[VERSIONING.md](VERSIONING.md).

### Tests

- Exercise user-log cleanup against real MySQL and MariaDB, preserving each current account's latest login and token while removing failed and orphaned entries; collect coverage from the actual controller.

- Exercise local login, password changes, logout, user/group realm and permission changes, and report ownership/persistence through native production files with isolated SQL fixtures. Part of #699.

- Characterize `is_resource_writable()` for existing files, new files, directories, and permission-denied paths before changing the legacy filesystem check.

### Fixed

- Remove orphaned user-log entries even when no current user accounts remain.
- Stop token generation when the cryptographic random source fails instead of returning a predictable fallback. Fixes #580.
- Refresh generated Midwinter stylesheet import versions during the browser build so uncompiled installations invalidate changed child CSS.
- Return a failing CLI status and JSON `failed` status when any database table analysis fails, and use the correct `ANALYZE NO_WRITE_TO_BINLOG TABLE` syntax on main. Fixes #241.

- Build offline archives with the npm JavaScript CLI bundled with the selected Node runtime, avoiding shell-wrapper parse failures in CI. Related to #703.
- Preserve both existing audit baseline tables until a staged import is validated and atomically installed; report failed imports and repairs with a nonzero CLI status. Fixes #242.
- Invoke standard plugin upgrade callbacks during database audits and quote upgrade script paths and arguments.
- Bind graph-template and local graph item ordering filters as parameters and preserve the non-classic theme fallback when available. Related to #476.
- Use a stored or session UI theme only when it names an installed theme, and fall back to an installed theme otherwise. The configured default graph theme is checked the same way. An unset user no longer triggers a settings write during the fallback.
- Refresh the Midwinter stylesheet cache-busting hashes for the core, compact and jQuery UI files, so browsers and proxies fetch the current CSS after an upgrade.

- Stop the Midwinter ESC shortcut throwing a script error outside fullscreen, and drop the unused `c+F1` shortcut that opened an `[object KeyboardEvent]` alert. SHIFT+k now leaves fullscreen as well as entering it.

- Keep a manual Midwinter colour mode when the operating system switches between light and dark. After turning off the preferred colour theme in the same session, a system change still overrode the choice and reloaded the graphs.

- Stop the Midwinter theme adding another copy of its keyboard shortcuts, menu search highlighting and menu click handlers on every page change, so one shortcut press no longer loads a page once per earlier navigation. A double-click anywhere no longer toggles fullscreen; use SHIFT+k.

- Mark the Midwinter `CactiColorMode` cookie `Secure` only over HTTPS. Over plain HTTP the browser dropped it, so graphs ignored the dark or light colour set and reloaded on every page change.
- Close an open select menu when its page or panel scrolls, so the detached list no longer floats over other fields. Forward-ported from lts/1.2 (issue #7506).

- Fix theme script defects on page reloads: window resize handlers no longer pile up, the classic theme no longer removes the handler that closes open menus on an outside click, and filter search icons are added once. Select menus are sized through the widget, so a plugin field id with `.` or `:` no longer stops the theme setup.

- Replace Font Awesome 4 icon names that render blank: the paper-plane scroll-to-top button and the sunrise logo now show their icons, and paper-plane and paw no longer turn delete icons into an undefined class. The paw theme also shows its logo on the logout page.
- Make the offline bundle check fail when a font named in the Font Awesome stylesheet is missing. It checked only that `all.css` existed, so a bundle whose icons all drew as missing glyphs passed.

- Center the About link logo in the classic, dark and modern themes, where it was clipped on the right. Add the missing semicolons that dropped the page-load progress bar glow in classic, paper-plane and paw and the graph zoom tooltip padding and border in midwinter, and give the midwinter `.moveArrowNone` padding its missing `px` unit. Remove theme declarations browsers already discarded, including stray comment terminators in the paper-plane and sunrise headers, and refresh the midwinter stylesheet content versions so browsers load the current CSS. Keep device states, log levels, popup/menu text and hover controls readable in dark, paper-plane and sunrise. Fixes #637.

- Install only the Font Awesome stylesheet, its WOFF2 fonts and licence into a cleared `include/fa` with directory guards, instead of the whole 25 MB npm package. Font URLs now carry the package version, so a browser that cached Font Awesome 5 fonts under the same names fetches the new ones.
- Disable network access while parsing imported package XML. Fixes #578.
- Keep SNMP agent cache values on one `pass_persist` protocol line by removing embedded carriage returns and line feeds before storage and output.
- Normalize Graph View graph-list values before storing them in the session, escape them in HTML, and encode them for JavaScript. Removing the last selected graph now clears the stored selection, while paging preserves it. Fixes #574.
- Escape and type-check the posted local graph ID before rendering Aggregate Graphs bulk-action confirmation markup. Fixes #586.
- Escape color-dropdown values and enclosing form row IDs in their HTML contexts; render color option identifiers as integers. Fixes #576.
- Recheck data-source profile references when a bulk deletion is submitted, preserving definitions still used by templates or sources while allowing unused profiles in the same selection to be removed.
- Escape device and network values before adding them to automation discovery HTML emails. Fixes #589.

- Create the identity audit file with restrictive permissions without changing the process-wide umask, which could otherwise affect unrelated threaded requests. Fixes #382.
- Capture the RRDtool dump while transforming RRD files so repair utilities print nothing outside debug mode and print the modified XML only once in debug mode. Fixes #438.

- Honour forced-local storage for RRDtool file checks, structured paths, and Boost operations. With proxy storage configured, realtime polling could send proxy-only commands to local RRDtool and recreate an existing RRD. Fixes #444.
- Retain buffered Boost samples until remote acknowledgement, refuse missing database connections, and stop recovery when an acknowledged sample changed before cleanup. Fixes #268.

- Keep the recursive RRD tuning report printer local to each `rrdtool_tune()` call, so repeated calls in one process do not redeclare a global function. Fixes #445.
- Report missing stored graph data accurately when a zoom request has no usable RRA. Fixes #369.

- Keep graph-group lookups scoped to the local graph ID, preserve the configuration cache map when setting an option, keep invalid structured filters from becoming unrestricted, and scope user-setting existence cache entries to the user. Public helper signatures and valid filter behavior are unchanged. Fixes #479.
- Build forced HTTPS redirects from a validated server name or the administrator-configured Base URL for catch-all virtual hosts. Preserve raw encoded request targets and remove HTTP listener ports. Configure a canonical server name (Apache UseCanonicalName On); invalid authorities return HTTP 400. Fixes #584.

- Accept only a number or `U` as a data source minimum, and only a number, `U` or an interface speed token as a maximum, refuse to create an RRD file whose stored minimum is anything else, and create realtime graph RRD files through the RRDtool pipe instead of a shell. A data source item that fails validation is no longer saved.

- When running as root, change the owner and group of the RRD files and structured-path directories the poller and Boost create, and of the RRA directory made for a new device, only for plain paths inside the RRA directory, never through a symbolic link; RRDfile maintenance likewise skips an archive directory reached through a symbolic link.

- Start RRDtool without a shell, so an RRDtool binary path containing a blank works for graphs, tuning and RRD writes. The path must name the executable alone; extra arguments or shell syntax in it now stop RRD writes as well.

- Send the RRD paths in exports and graphs to the RRDtool proxy bare and relative to the RRA directory, which is how the proxy reads them, so CSV export and other exports work through it. Graph images still fail against rrdproxy 54aad57; see `docs/migrations/graphing-rrd.md`.

- Clear each converted table from the installer's queue. It wrote a setting named `0` instead, so the queue was never cleared.

- Restore the RRDtool proxy client, which could not connect on phpseclib 4. It now checks the proxy's key fingerprint in constant time, gives up on a key exchange that is oversized or too slow, and never falls back to unencrypted frames. A default font path with a blank or a quote is no longer sent to the proxy. Fixes #399.

- Leave the data source type alone when tuning an RRD file with an empty or unknown type, instead of raising a PHP warning and, for an unknown type, sending RRDtool an empty type.

- Stop creating the structured-path directory for an RRD file when only showing its RRDtool create command.

- Pass `--y-grid` and `--units-exponent` to RRDtool once, quoted, instead of twice with the exponent once unquoted. The graph renders the same.

- Mark stacked areas as stacked in graph export metadata, and key that metadata, and the name given to an unnamed export column, by the column's own number. The flag compared against a type name no item has, and the numbering started after the count of every graph item, so no key matched a column.

- Keep VDEF-backed drawing items in graph images but omit them from CSV XPORT columns, which accept DEF/CDEF time series and reject scalar VDEF values. Fixes #273.

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

- Represent database table analysis results with typed immutable outcomes while preserving CLI text, JSON output and failure exit codes. Related to #682 and #683.
- Declare precise union return contracts for existing filename, command, CSP process-owner and RRD maintenance helpers while preserving success, failure and empty-output behavior. Related to #717.

- Serve legacy stylesheets and scripts from `public/assets/` with digested file names once `php bin/console asset-map:compile` has run, using Symfony AssetMapper 7.4. Theme `url()` and `@import` references are rewritten to the digested copies, so Midwinter no longer keeps hand-maintained import hashes and `update_hash.php` is gone. Without a compiled manifest, and for `custom.css`, plugins and the flag-icons stylesheet, pages keep the `?md5` URLs. Docker images and offline bundles ship the compiled files; source installations must rerun the compile after each upgrade.
- Reuse common row-count option rendering in automation previews while preserving each row filter.

- Migrate bulk device statistics reset to a Symfony confirmation page and Inventory use case, with authorized selection checks and primary/remote failure handling.
- Run legacy `exec_into_array()` commands through Symfony Process while preserving its public signature, stdout line array, exit-status handling, and unlimited wait behavior. Retain the native `exec()` path if Process cannot start because `proc_open()` is unavailable. Tracks #482.
- Isolate Cacti session release and timezone-cookie handling in the legacy web context adapter used before one-off local RRDtool processes.

- Use Symfony Clock, Filesystem, and Process components in RRD graph and maintenance operations while preserving procedural callers, the existing shared/exclusive directory lease, and the long-lived RRDtool pipe.


- Publish the command-line migration roadmap and the safety decisions for the database audit and repair commands in docs/migrations/cli-symfony-console-roadmap.md.

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

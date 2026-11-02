# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

- Serve migrated Symfony pages in all 23 shipped gettext languages: module domain keys without a YAML translation fall back to the matching `locales/po` entry, and the locale subscriber accepts every shipped locale with legacy spellings such as `pt_BR`, `zh-CN` and `de-AT`. Plural keys are bridged only where gettext and the English singular/plural choice agree; legacy `__()` and plugin catalogs are unchanged.

- Web Basic authentication (auth method 2) now takes the user only from `REMOTE_USER` or `REDIRECT_REMOTE_USER` set by the web server and no longer from `PHP_AUTH_USER`, which PHP fills from the client's `Authorization` header; servers that pass only `PHP_AUTH_USER` must now pass `REMOTE_USER`.

- Add the missing English "Invalid location search." message to the inventory catalog and test that migrated translation catalogs, templates and sources stay free of untranslated visible text.
- Preserve native database session activity and logout state across concurrent requests, confirm storage outcomes without committing caller-owned work, and bound session display metadata.
- Declare legacy ping and spike-removal properties explicitly public, preserving their visibility, defaults and serialization.

- Guide installer setup with grouped optional settings, explicit backup and recovery checklists, actionable runtime checks and read-only post-installation evidence. Add local unattended configuration validation, environment-backed SNMP secret references and authenticated redacted diagnostic downloads. Include English/French guidance and keyboard/browser regression coverage (#1313).
- Replace the installer web and CLI orchestration with a shared Symfony review and execution workflow for 1.3. Add read-only preflight/status, explicit discovery consent, signed reviews, worker locking, stopped-attempt recovery, partial collector outcomes, CSP-safe forms and English/French installer catalogs. Retain native migrations, imports and plugin integration. The CLI now enforces existing operator eligibility and upgrade permissions; deployments must provide the same strong `APP_SECRET` to web and CLI processes (#1293).
- Honor explicitly disabled native SNMP overrides while retaining omitted values; preserve explicit CLI credential clearing and blank browser password-field retention (#1300).
- Keep Midwinter form and hover callbacks scoped to their widgets and bind handlers once across repeated theme initialization.

- Add shared server-selected database target capabilities for native administrative form and worker handoffs, without changing existing routes or connection selection.

- Keep responsive resize measurements local so resizing cannot overwrite unrelated window properties, and remove unused tab measurements while preserving layout and callback ordering.

- Exclude unknown and non-finite samples before percentile aggregation, retaining measured zero and valid same-timestamp contributions (#1112).
- Preserve native collector recovery while ordinary or Boost output is pending or unreadable: administration refuses primary reads and the console keeps the local database/session backend until recovery finishes (#759).
- Run complete file and database session coverage concurrently in isolated Docker projects, retaining both failure statuses and the existing coverage validation and job deadline.

- Import required application coverage entry points before validating their execution, retaining tree and graph CLI worker evidence as migrations add new sources (#1255).

- Include client timing, dataset, connection outcome and server diagnostics in failed RRDtool proxy interoperability tests to investigate intermittent fixture failures (#1244).
- Register native database sessions through `SessionHandlerInterface` to avoid PHP 8.4 callback-registration deprecations, retaining the existing callbacks and shutdown behavior.
- Read group graph-setting presence from the group row, preserving stored empty and zero values without inheriting unrelated user settings.

- Store explicitly empty or null nullable numeric fields as SQL NULL in `sql_save()`, preserving explicit zero, omitted fields and non-nullable defaults (#1108). Existing stored zeros are unchanged.
- Serialize nullable SNMP text as empty strings and non-device numeric defaults as zero in poller cache entries under strict SQL, preserving stored host settings and explicit overrides.

- Bound Windows RRDtool one-shot commands by the command/poller deadline and prevent large input/output deadlocks using temporary file handles. Windows now requires writable temporary storage (#782).
- Match SNMP notification history descriptions by MIB and notification name, preventing cache aliases from duplicating events or inflating pagination.


- Use the existing error image for malformed remote graph responses, preserving valid image bytes and local permission checks (#1113)
- Exclude disabled SNMP notification receivers during discovery and final trap selection, preserving enabled receivers whose legacy disabled flag is NULL. This carries the existing LTS fix into main.
- Match the SMTP settings preflight to delivery for STARTTLS, implicit TLS, authentication and embedded host ports, retaining certificate verification (#200).
- Preserve a child process exit code first observed after the polling window, with the close result as fallback, instead of returning `false` (#1075).
- Retain queued Boost samples when the fallback RRD path read fails, and reach the poller path lookup for data sources without a graph item (#738).
- Run `cli/copy_user.php` and `cli/add_perms.php` through the `kadupul:user:copy`, `kadupul:permission:add` and `kadupul:permission:list` console commands. The scripts keep their arguments, output and exit codes. They now need the Console Access and User Management realms, a permission grant ends the user's remembered logins and raises their permission epoch as the web form does, a missing or empty new username prints the usage instead of creating an unnamed account, and the ids are bound instead of concatenated into SQL (#1213).
- Treat the obsolete data source delete action as an unknown action instead of calling an undefined handler; use bulk Actions for deletion (#685).
- Run `cli/reorder_data_query.php` through the `kadupul:data-query:reorder` console command. The script keeps its arguments, output and exit codes, and still refuses to run on a remote collector. It now needs the Console Access and Devices realms (the realm `host.php` requires), binds the device and query ids instead of concatenating them into SQL, reads the items in data source order, and records the run in the audit trail.
- Run `cli/poller_data_sources_reapply_names.php` through the `kadupul:data-source:reapply-titles` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Devices realms (the realm `data_sources.php` requires), binds the filter instead of concatenating it into SQL, and records the run in the audit trail. With `--debug` and nothing selected it no longer prints the selection SQL.
- Run `cli/poller_graphs_reapply_names.php` through the `kadupul:graph:reapply-titles` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Graphs realms, the filter is bound instead of concatenated into SQL, and the run is audited.
- Run `cli/repair_graphs.php` and `cli/remove_broken_graphs.php` through the `kadupul:graph:repair-items` and `kadupul:graph:remove-broken` console commands. The scripts keep their arguments, output and exit codes. They now need the Console Access and Graphs realms, ids are bound instead of concatenated into SQL, and the broken-graph list is no longer cut off at `group_concat_max_len`.
- Run `cli/add_graph_template.php` through the `kadupul:device:add-graph-template` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Devices realms, binds its ids, audits the change, and refuses an unknown device or graph template instead of writing an orphan `host_graph` row.
- Run `cli/add_tree.php` through the `kadupul:tree:add` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Trees realms, binds its ids and names instead of concatenating them into SQL, audits what it adds, and refuses a graph id with SQL after the number.
- Run `cli/add_graphs.php` through the `kadupul:graph:add` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Graphs realms, binds its ids, fields and values instead of concatenating them into SQL, audits what it creates, and refuses an unknown device before it associates a data query with it.
- Run `cli/add_datasource.php` through the `kadupul:data-source:add` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Devices realms (the realm `host.php` and `data_sources.php` require), refuses an unknown device or data template instead of writing an orphan or clamped `data_local` row, and records the new data source in the audit trail.
- Run `cli/poller_output_empty.php` through the `kadupul:poller-output:empty` console command. The script keeps its arguments, output, stderr messages and exit codes. It now needs the Console Access and Devices realms and audits each pass, and a pass that cannot report its outcome ends with an error and exit 1 instead of a PHP fatal error.
- Run `cli/add_data_query.php` through the `kadupul:device:add-data-query` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Devices realms (the realm `host.php` requires), binds its ids instead of concatenating them into SQL, refuses an unknown device or data query instead of writing an orphan `host_snmp_query` row, and records the association in the audit trail.
- Run `cli/remove_graphs.php` through the `kadupul:graph:remove` console command. The script keeps its arguments, output and exit codes. It now needs the Console Access and Graphs realms, ids and expressions are bound instead of concatenated into SQL, the removal is audited, and an expression over 50 bytes, with `;`, or empty is refused as `validate_is_regex()` intended.
- Match observed-sample percentile ranks to RRDtool, including single samples, low percentiles and half-rank boundaries (#1092).
- Preserve session and missing-message fallbacks when the legacy message catalog is unavailable (#1107).
- Preserve graph-view actions and query delimiters when a URI match begins at position zero (#1102).
- Try configured SNMP after a failed Ping-or-SNMP ping, and carry SNMP settings into command-poller availability checks for devices without poller items (#1105).
- Use the current response time when inconsistent device counters leave a zero average divisor (#1101).
- Schedule system-MIB collection from its own per-poller timestamp after four hours, matching LTS and avoiding repeated or missed collection from timestamp modulo arithmetic (#1106).
- Preserve refresh rendering when a SAPI omits REQUEST_URI, retaining the LTS empty-string fallback and existing logout policy (#1104).
- Keep numeric fetches and CSV exports within the requested end time, preserve the export start boundary and retain the selected resolution for exports over 10,000 rows (#1092).
- Keep quoted empty shell arguments in their original position while preserving unquoted and legacy null/false values; test-data-source templates retain their empty-field omission (#1103).
- Preserve destination graph-view actions and default-mode selection when validating redirects, fixing source-action leakage introduced by #1102 (#1241).
- Iterate gettext catalogs without parsing `ls`, skipping an unmatched glob and preserving filenames containing whitespace (#1117).
- Convert non-finite numeric Boost samples to unknown before scheduled and on-demand RRD writes, preserving valid sibling fields and original queue acknowledgement values (#739).
- Use true binary unit divisors for percentile and bandwidth-summation graph variables, preserving decimal and automatic scaling (#1110).
- Apply aggregate total CDEFs to legend items so average, maximum and VDEF percentile labels show the combined data instead of a member value (#1111).
- Add `html_require_legacy_js()` so plugins can declare their need for Chart.js, dygraphs, jquery.sparkline or the jQuery colorpicker. Release 1.3 still loads all four on every page and its page head is unchanged; release 1.4 will load only the requested ones. These libraries are deprecated and no core page uses them.
- Update billboard.js from 3.18.0 to 4.1.3 and the billboard stylesheet in every theme. The library is now installed from npm and checked against a pinned checksum like D3 and jQuery. Plugin charts keep working because the browser bundle still includes every chart method; 4.x also stops `javascript:` links spelled with a numeric character reference that omits its closing semicolon from reaching the default tooltip, custom tooltip contents and legend templates, which 3.18.0 allowed. Git-based installs must run `npm ci && npm run build` after pulling, because `include/js/billboard.js` is no longer tracked and `git pull` deletes it, leaving charts broken until the rebuild; packaged and offline releases are unaffected.
- Decide model-driven binding approval through a `metric_binding` state machine whose guard asks IdentityAccess for the signed-in actor and a binding-approval permission (Settings/Utilities realm), denies by default and audits every decision. Nothing calls approval yet and the RRD write gate stays closed.

- Replace the bundled fullscreen library with a native Fullscreen API shim that preserves the screenfull 3.x plugin interface.

- Inventory bundled browser libraries and report dependency version drift during maintenance.

- Compare dependency-report versions without rounding large numeric SemVer identifiers, and keep asset verification and npm lookups in explicit serial order.

- Load jQuery Migrate after jQuery 4 so existing widgets and plugins can use removed jQuery helpers. Keep its unsafe HTML and JSONP compatibility patches disabled.

- Make the CSP report size limit reachable with a bounded body read, drop reports when the rate-limit directory cannot be trusted, and sweep expired rate-limit counters (ports the lts protection; upstream Cacti #7704).
- Expire the remembered logins of the users a permission, realm, policy or group change affects, so their next visit needs a fresh sign-in; their open sessions keep reloading permissions as before. Remembered-login restoration also refuses a server-side token row older than the 30-day cookie lifetime instead of relying on the 90-day maintenance purge (upstream Cacti #7600, GHSA-xq26-f282-f998 from #8152).
- On Windows, drop the characters cmd.exe acts on inside quotes (`" & | ^ < > ( )`) from device and receiver values in data input commands, data query script arguments, the Windows ping and SNMP trap arguments, and from the deprecated SNMPv3 auth string; hostnames also lose `%`. `cacti_escapeshellarg()` gains optional `$cmd` and `$strip_env` flags for this and is unchanged on Unix. `escape_command()` now turns a CR or LF into a space so an RRDtool command cannot carry a second line, and the SNMP auth and privacy protocol fields accept only the listed protocols, including SHA384 instead of the misspelt SHA392 (GHSA-rjvj-r52f-8v5q, upstream Cacti #8055, #8056 and #7732).
- On Windows, drop the characters cmd.exe acts on inside quotes (`" & | ^ < > ( )`) from device and receiver values in data input commands, data query script arguments, the Windows ping and SNMP trap arguments, and from the deprecated SNMPv3 auth string. Those values also lose `%`, so cmd.exe cannot expand a variable, except SNMP communities, passwords and privacy passphrases. `cacti_escapeshellarg()` gains optional `$cmd` and `$keep_percent` flags for this and is unchanged on Unix. `escape_command()` now turns a CR or LF into a space so an RRDtool command cannot carry a second line, and the SNMP auth and privacy protocol fields accept only the listed protocols, including SHA384 instead of the misspelt SHA392 (GHSA-rjvj-r52f-8v5q, upstream Cacti #8055, #8056 and #7732).
- Confine data source RRD paths to the RRA directory before RRDtool or the filesystem receives them, in RRD create, update, fetch, graph, info, tune, dump and restore, the Boost writer, `data_debug`, dsdebug, dsstats, rrdcheck, spike removal and the gap, heartbeat, float and restructure tools (upstream Cacti #7665, #8064, #8258 and #8314). A trailing slash on `path_rra`, a symlinked RRA directory and RRA subdirectories that do not exist yet are accepted. A file outside the directory, a link that leaves it, a dangling link and a `..` segment are refused. Samples queued for a refused path are moved to `poller_output_rejected` instead of piling up in the poller output. With the RRDtool proxy only the text of the path is checked, since the proxy resolves it. RRD files kept outside `path_rra` must be moved under it, or `path_rra` set to the directory that holds them.
- Keep Boost samples queued when a database restart interrupts a flush. A data source whose RRD path or template lookup fails is no longer treated as removed, so its samples stay in the archive instead of being deleted unwritten, and a Boost child whose database session was replaced reports failure, so the run ends `failed` and keeps its archive for the next cycle instead of reporting `complete` (#1182).

- Refuse to signal a process-table PID that cannot name a Kadupul task. PID 1, zero, negative or malformed values and values wider than `pid_t` are never passed to `posix_kill()`, and worker cleanup confirms a registered PID still runs the same program before signalling it. This ports the lts/1.2 protection from upstream Cacti #7955 and #7414.
- Resolve the client address for Symfony remember-me and Basic restoration with the same trusted-proxy rules as legacy pages, so forwarded headers count only from a listed proxy (ports the lts protection; upstream Cacti #7658, #7363).
- Refuse unsafe table, column, index and option names and column clauses in the plugin and core DDL helpers (`db_table_create()`, `db_add_column()`, `db_update_table()`, `db_add_index()` and the `api_plugin_db_*` wrappers), and quote column defaults and comments; the plugin wrappers now use the core helpers. Definitions used by shipped upgrades and common plugins, including compound key strings, prefix lengths and quoted enum members, are unchanged. RRDtool create and update commands now refuse data source names and update templates RRDtool cannot parse, tune lines and data source paths with control characters are refused, and spike removal and background process starts quote their executable or RRD path.
- Confine stored field names used as SQL identifiers and literals when automation builds its rule item filter, search HAVING clause and data query pivots, and fail closed when a name has no usable part (upstream Cacti #7701 and the pivot part of #8149).

- Quote the stored SNMP index values in the new graph data query filter and anchor the graphs.php template filter pattern (upstream Cacti #8138 GHSA-j3px-vw6r-g25x, #7866).
- Require a set Boost debug log path to end in `.log` on the settings page, and have Boost ignore a stored path that does not (GHSA-m6wx-f538-m6q3, upstream Cacti #8150 and #8148).
- Encode the page title in the HTML `<title>` element, strip header-breaking characters from the graph export file name, and guard the graph export title, vertical label and legend cells against spreadsheet formulas (upstream Cacti #8164 and #8165, GHSA-v99q-57w7-mpmg, GHSA-hh7r-464j-rf39).
- Report a data query XML file as located on the edit page and in the template import preview only when it is a regular file under `resource/`, which the loader reads, and refuse a NUL byte in path containment checks (GHSA-2x86-jpm8-9vgp, upstream Cacti #8150 and #8151).
- Encode form and confirmation output: the edit form action, name and enctype, the notification receiver confirmation inputs, the data query graph template id in an inline script, and the names formatted into flash messages (upstream Cacti #8437, #8439, #8449, #7252, GHSA-9737-rqh3-h7fh).
- Quote the dsstats log path in the background poller command, and validate the graph id in the aggregate graph permission list as the graph and device lists already do (upstream Cacti #7844 and #7847).
- Escape new graph template and data query names in the import preview differences, so a name from an uploaded template shows as text instead of markup the preview purifier would allow (upstream Cacti #7238).
- Hold Data Source item names and minimum and maximum values from an imported template to the data source form rules, and reject the template with a message when one fails; every value in the shipped templates passes (upstream Cacti #7720).
- Add `kadupul:boost:purge-image-cache [--json]` and an hourly purge of the Boost image cache in the `boost` schedule. The purge removes only expired files named by `BoostGraphCacheKey`, never follows symlinks, and fails when `boost_png_cache_directory` is unset or outside the installation. `poller_boost.php` leaves the purge to the schedule when `KADUPUL_BOOST_SCHEDULER=1`.
- Move the Boost graph cache key and file name into `Kadupul\Graphing\Domain\BoostGraphCacheKey`; `boost_graph_cache_render_key()` and `boost_graph_cache_filename()` delegate to it with unchanged output.
- Add `kadupul:boost:run` and an opt-in `boost` schedule (`KADUPUL_BOOST_SCHEDULER=1`, consumed from `scheduler_boost`). With the scheduler enabled, `poller.php` no longer launches Boost; the master still applies its own due check and registration.
- Add `service/kadupul-boost-worker.service` and operator documentation for Boost queue workers: enabling, scaling, redelivery timeout for Doctrine DSNs, and a check to run before enabling.
- Dispatch Boost partitions to the `boost` message queue when `boost_processing_mode` is `queue`. The master waits for every partition to report under its run, or fails the run and keeps the archives; remote collectors keep using child processes.
- Add the `FlushBoostPartition` message, its handler and `bin/legacy-boost-partition.php`, which runs the existing Boost child for a run the master is still waiting on.
- Scope Boost child status to a run, add the `boost_processing_mode` setting (default `children`) and the `kadupul_messenger_messages` table in the 1.2.38 upgrade step. Children mode behaves as before; a child now needs the `--run` value the master passes.
- Bind or cast the id lists and LIKE patterns in the aggregate graph migration and title filter, data source and graph removal, and the report tree graph lookup, and match LIKE wildcards in the aggregate title filter literally (upstream Cacti #7261, #7262, #7264, #7265, #8149, #8152).
- Escape LIKE wildcards in the plugin list filter and bind the reverse-DNS name in the discovery sysName check, so `%`, `_` and a PTR record value match literally and cannot alter the query (upstream Cacti #7670, GHSA-jjfq-mj5r-2r95).
- Keep the database user and password out of `mysqladmin` process arguments in `scripts/sql.php` and `scripts/ss_sql.php`. Both scripts pass a private option file that is removed after each call, and `ss_sql.php` requests `status` again, so it no longer always returns `U` (upstream Cacti #8545 and #8450).
- Pass the SNMP port to the net-snmp command line and the native session target as an integer, so a stored port can no longer carry whitespace or other text into the target argument (upstream Cacti #7689).
- Show the source value and the resulting branch in the tree automation rule matching preview as text, and remove control characters from SNMP community, username, password, privacy passphrase, context and engine ID when a device or notification receiver is saved (GHSA-f7jw-gfhr-cxwm, GHSA-m57p-vf3v-cpgg and GHSA-m3fh-gxqj-76hq, upstream Cacti #8152).
- Check graph item input values against their field before a graph save writes them or a push-out copies them to existing graphs, refuse graph input saves and removals that name an input or item of another graph template, and refuse an imported graph template whose inputs name items outside it. Values now follow the existing storage ranges and UTF-8 character limits; decimal commas and blank optional numeric form fields are normalized before binding (#1159). This completes the lts/1.2 port of upstream Cacti #7669 and #7692 for CVE-2024-31458; main already restricted the input field itself.
- Refuse remote data collector requests to link-local addresses, including the cloud metadata address, 0.0.0.0/8 and other reserved ranges, also when an IPv4-mapped, NAT64 or 6to4 address carries them. Loopback and private collectors stay reachable. Requests connect to the checked address, do not follow redirects, and keep the collector name for the Host header and TLS check. This covers the remote agent calls, remote data queries and device diagnostics, and ports the lts/1.2 protection from upstream Cacti #7672.
- Verify package preview files only with the key the package reader has already trusted, return a shipped package key only when it is trusted, and skip the `package_public_keys` lookup when that table does not exist (ports the lts protection; upstream Cacti #7863, #8369, #8372; #1039).
- Require access to a graph tree before creating, deleting, renaming, moving or copying its nodes, changing a branch's sort settings, or placing graphs on it. A branch of another tree can no longer be used as the parent or source of a node change, and the graph action menu lists only trees the user may access. This ports the lts/1.2 protection from upstream Cacti #7674.
- Require access to a graph tree before creating, deleting, renaming, moving or copying its nodes, changing a branch's sort settings, or placing graphs on it. A branch of another tree can no longer be used as the parent or source of a node change, and the graph action menu lists only trees the user may access. Copies also require permission to the source device or graph, as on LTS. This ports the lts/1.2 protection from upstream Cacti #7674.
- Delete the server-side remember-me token before expiring the browser cookie when a legacy logout or credential reset clears a remembered login (ports the lts protection; upstream Cacti #7976).
- Verify a local login password once. A known account no longer verifies again before a rehash, so known, unknown, disabled and locked usernames each cost one hash, and a password replaced during the login refuses it (GHSA-p3rg-7pc3-2h86, upstream Cacti #8157).
- Escape the username shown on the change password page for an account with no stored password, so a stored username renders as text (upstream Cacti #8338).
- Bind on-demand Boost sample inserts so poller output is stored verbatim instead of being concatenated into SQL.
- Pass browser timezone input explicitly to the local RRDtool web-context adapter while preserving session-close and process-start ordering.

- Pass bootstrap row choices explicitly through legacy permission filters, removing the renderer's global binding while preserving tab-hook catalog changes.
- Pass bulk permission selection field names explicitly from authenticated user/group page handlers instead of reading POST inside the association helper.
- Remove 71 unused global bindings from 45 retained operation-page functions, tighten their per-file ratchet, and document global-state boundaries behind every Twig migration family. Native HTTP controllers are now checked alongside application/domain code for direct global access.
- Say in `rebuild_poller_cache.php --help` that `--threads` defaults to the Poller Command Concurrent Processes setting, not 5 (#1021).

- Keep data input field values out of quotes the input string opens. A value the template wraps in quotes, embeds in a quoted word, or places beside another field stays a single literal argument when the poller or Test Data Source runs the command; unsupported shell grammar inside a command substitution, a field or backslash inside backticks or `${...}`, bash `$'...'` or `$"..."` quoting, a field directly after an unescaped `$`, a backslash inside `$(...)`, and a newline, word-initial `#` or `<<` at the top level yield no command. Fields are substituted in one pass, and fields with no value or an empty value are removed before scanning, so token-shaped text inside a value is no longer stripped. Test Data Source no longer splits a quoted value when it drops a script server function name. Shipped data input methods produce the same command lines (GHSA-5v3j-wcrr-jxjg, #1118).
- Add ten Twig views for graph-input associations, data-query mappings and suggestions, template relationships, and registered SNMP MIB metadata. Remove three unused VDEF global bindings from the related data-query confirmation page without changing its HTML or request/query calls.
- Reject malformed aggregate parent identifiers, bound prepared insert batches, and hold the verified table engine stable until item replacement completes. Failed replacements preserve previous items and caller-owned work (#1017).
- Add ten Twig views for stored user/group realm grants, display preferences, graph preferences, time preferences and login options. Preference readers allowlist public keys and preserve zero, empty and missing values; existing PHP forms still handle changes.

- Add ten Twig views for user/group memberships and stored graph, device, template and tree permission exceptions. Show the stored default policy without presenting these records as effective access. Existing PHP pages still handle policy and membership changes.

- Add ten native Symfony/Twig management catalogs for graphs, data sources, aggregates, reports, recorded plugins and template relationships, with resource/owner permissions and bounded browsing. Legacy editors and operations remain available.
- Add ten Symfony/Twig relationship views for template definitions, automation criteria and steps, SNMP option entries, and receiver subscriptions and history. Parent catalog links preserve access to existing editing and operational actions.

- Add ten native Symfony/Twig System Utilities diagnostic views with injected readers, bounded filters, device-scoped cache reads and English/French catalogs. Existing maintenance actions and advanced diagnostics remain available through legacy pages.

- Keep a script or resource file that already exists when the installer imports a device template package, instead of writing the package's older embedded copy over it; missing files are still written, and package import from the web page and the command line is unchanged. A test lists the embedded files that still differ from the tree, so new differences fail CI (#998).

- Add native Symfony/Twig browsing for device, graph, data and aggregate templates; data queries; SNMP option sets; graph and tree rules; discovery networks; and notification receivers. Existing PHP forms still handle edits and bulk actions (#1007).

- Update the hash-locked CI Semgrep scanner from 1.180.0 to 1.181.0.

- Keep a package's file mode when `kadupul:package:sign` replaces it in place, and give a new output file the mode the umask allows, instead of always setting 0644; group and other write and all execute bits are always removed. The 1.2.36 upgrade step now writes its fixed collector-copy marker as a SHA-256 value instead of RIPEMD-160; nothing reads the value.

- Update flag-icons to 7.6.0 and Playwright to 1.64.1, and check bundled flag versions against the dependency manifest.

- Route the `db_*` helpers' connection state through `LegacyConnectionRegistry` instead of `global` declarations in `lib/database.php`; the connection globals keep the same names, values and assignment points for plugins and the poller, and the helpers' return values, retries and reconnects are unchanged. A warning for a missing connection key keeps its text but now names `LegacyConnectionRegistry.php` as its file and line.

- Give Symfony services an immutable `PlatformConfiguration` holding the installation paths, URL path, collector id and server OS that `include/global.php` sets; `$config` keeps the same keys and values. The external link and help adapters now receive it instead of the project directory.

- Fail CI when first-party PHP gains a `global` statement or a variable named in one, against a committed per-file baseline that may only fall; new files under `src/` may not use `global`.

- Harden in-place DOMPurify cleanup in the vendored patch set.

- Keep model-driven metric bindings and writer ownership claims in two new tables, `kadupul_metric_binding` and `kadupul_writer_ownership`, created on fresh installations and by the 1.2.37 upgrade step. No existing table changes, and existing data sources stay with the legacy poller.

- Validate and reproduce the existing interface query from a build-time catalog, preserving shipped query bytes, signed packages and template identities, with regression coverage for existing import and COUNTER behavior.

- Report U for every requested Apache server-status field when the page cannot be fetched or has no scoreboard, instead of zero thread counts, and give up after 5 seconds instead of PHP's 60-second default.

- Replace the bundled Perl data input scripts (ping, load average, logged-in users, processes, memory, partitions) and the Python 2 SB6141 modem script with PHP ports that print the same output without a shell; the process count no longer includes the pipeline's own shell and grep.

- Point the stock data input methods and the Unix partition query at the PHP scripts on fresh installs, and on upgrade wherever the input string is still the shipped default; customized methods and the Perl scripts are left in place.

- Ship the PHP scripts in the Apache, BayTech, Generic SNMP, Local Linux, MikroTik, MikroTik SwOS and SB6141 packages, re-signed with the Kadupul package key.

- Trust the Kadupul package signing key alongside the Cacti keys for package import, preview and installation, and add `kadupul:package:sign` to re-sign packages with a key read from `KADUPUL_PACKAGE_SIGNING_KEY`.

- Refuse shell metacharacters in the webhits.pl and diskfree.pl arguments, which both paste a Data Source input value into a shell command.

- Make the reporting poller exit unsuccessfully on failed sends, report/owner reads or statistics writes, count accepted sends separately from attempts, and retain failed reports for retry while continuing the batch (#202).

- Preserve the previous reindex cache on failed mark, insert, or stale-row deletion, and stop before collector propagation when the local replacement fails (#758).

- Clear and warm the Symfony production cache before installer and CLI upgrade schema changes, including already-current databases; stop before upgrade schema writes if the cache refresh fails (#432).

- Retain offline collector output while children run and after completion, recover both local sample queues after acknowledgement, and report PHP collector queue-write failures (#759).

- Stop collector synchronization on failed table copies, cleanup, or statistics updates; retain pending full-sync state and report failure instead of completion (#760).

- Defer legacy system input-field and mapping repairs during template and package previews, and describe the checks as pending until a real import (#215).

- Reject malformed template XML before processing objects, and return status 1 with diagnostics for failed CLI imports and previews while preserving returned per-object results and handling empty files without a PHP fatal error (#216).

- Render plain-template CLI previews as text with proposed-change wording instead of HTML import-completion output (#217).

- Stop data-query reordering with an error when its sort-field read fails; retain XML fallback for successfully read empty fields and missing rows (#744).

- Delete stale remote poller items using valid SQL in strict database modes, preserving current, zero and NULL timestamps (#741).

- Give login and denied-access pages a document language and a level-one heading while preserving their theme styling (#695).

- Insert RRDproxy cleaner file listings in batches of 400 rows, independently of each file's byte size (#623).

- Drain POSIX one-shot RRDtool output while sending input, enforce the existing command timeout and caller budget, and report graph-export timeouts as failures (#782).

- Return failure for rejected or unacknowledged proxy output, stop unconfirmed RRD creation, and preserve realtime graph caches when rendering fails or returns empty output (#769).

- Consume old and duplicate direct-poller RRD samples as rejected, without publishing them as stored values; newer samples continue through the same writer (#775).

- Retain malformed MULTI poller samples without inventing all-unknown RRD updates; preserve the existing age-based discard policy and valid sibling samples (#763).

- Preserve file and stdout log message text when PCRE line cleanup fails, while continuing to escape control characters (#948).
- Log PHP deprecations (`E_DEPRECATED`, `E_USER_DEPRECATED`) as warnings without a backtrace instead of as errors, so library deprecations no longer read as failures in `cacti.log` (#1184).

- Mark console welcome and navigation layout tables as presentational while preserving their text, links, classes and IDs (#946).

- Escape log control characters even when PCRE fails, preserving the existing rules for ordinary text and early-bootstrap logging (#942).

- Retain graph tabs and account controls on the Symfony console home by capturing the native helpers' printed HTML (#944).

- Bound RRDtool proxy replies by the existing command timeout and poller budget; discard timed-out connections and leave outcomes unconfirmed (#784).

- Register setup-php interpreters in mise under their reported patch versions and verify selection before publishing CI runtime evidence (#935).

- Make Logout Everywhere revoke file and database session credentials and remembered logins; preserve existing sessions and return failure if revocation cannot be committed. Bind remembered-login rotation to its admitted credential generation so an in-flight restoration cannot create a usable session or token after revocation.
- Preserve existing RRD files and pending samples when a proxy existence check fails or returns an ambiguous response.
- Restore authorization, HTML-context escaping, sort-column and theme tests to the Pest coverage suite using production helpers; retire tests for the removed remote-URL helper.
- Fix `padleft()` never returning for a pad that is not numeric, and pad numeric values up to the requested minimum length instead of stopping after one pad character.
- Reject a field name that ends in a newline in `build_where_from_array()` instead of passing it to the query, where it failed with an unknown column.
- Let `get_associated_rras()` take the selected RRA id as a bound argument, and use it for the graph page instead of building a SQL fragment from the request.
- Reject a user id that is not an integer or a digit string, or that falls outside 0 to 16777215 (the range of `settings_user.user_id`), in `set_user_setting()` and `save_user_settings()` instead of storing the setting under another user.
- Apply the same user id check in `clear_user_setting()`, `read_user_setting()` and `user_setting_exists()`, so an id such as `'abc'` no longer deletes, reads or counts user 0's settings.
- Report a user id that `save_user_settings()` rejects as a save failure on the user and profile settings pages instead of "Save Successful." (#891).
- Redact log values stored under common credential names such as `api_key`, `authorization`, `bearer`, `session_id` and `psk`, which `cacti_is_sensitive_key()` previously let through.
- Escape control characters in `cacti_log()` output to the log file, the console and syslog, and turn a newline in its `$environ` argument into a space so it can no longer start a second log record.
- Apply the same control-character neutralization to Symfony maintenance log files and syslog through a shared rule that also loads before Composer.
- Make the worker database test barrier wait for a complete decision, preventing a partially written file from turning an injected query failure into success.
- Validate audit and widen CLI parity log dates against each command's observed UTC days, allowing a midnight boundary while preserving date layout, separators and message comparisons (#924).
- Syntax-check unchanged incoming PHP during merges, including renamed files and octopus parents, while preserving the existing whitespace/style exception and staged content (#753).
- Restore 36 silently omitted legacy regression suites to the required Pest runner and verify each registered file against recorded ordinary and privileged execution.
- Save the Primary Admin setting only as a user id, and build the Guest User and User Template exclusion lists from integer ids, so a stored or session-cached value can no longer change those queries (#754).
- Remove bare and timed RRDtool success trailers on Windows without deleting preceding graph or fetch payload bytes (#781).
- Reject RRDtool fetch errors before parsing data-source headers, preventing failed reads from appearing as zero-valued statistics (#776).
- Use the matched archive index from the RRD file in resize recommendations when its archive order differs from the data source profile (#780).
- Check each group's own tree exceptions, and only tree exceptions, when a tree is opened by id, so `is_tree_allowed()` allows exactly the trees that `get_allowed_trees()` lists (#755).
- Bind and consume change-only edit-form handlers immediately, preventing them from leaking into the next form (#700).
- Render an empty multiselect value from its same-name setting without a PHP 8 string-append error; preserve explicit selections and empty arrays (#701).
- Deny a disabled or missing tree when a viewer opens it by id or a report includes it, as the tree picker already hides it; tree editing still reaches disabled trees (#914).
- Keep data-source diagnostics read-only: comparing an RRD no longer overwrites saved heartbeats with the profile default (#774).
- Fail a package import when a script or resource file cannot be opened, is not writable or is only partly written, keep the uploaded package for a retry, and exit 1 from `cli/import_package.php` (#765).
- Roll back a Data Template or Data Input Method import, with any items or fields it already saved, when one of its child hashes does not parse, and return failure instead of throwing a `TypeError`; the Import Templates page shows the validation error for a failed import or a file that does not parse instead of "The Template Import Succeeded." (#764).
- Deny `include/vendor` files other than the stylesheets, scripts, images and fonts the interface loads, including names such as `x.php.css` that hide a PHP extension, and hidden or hash-wrapped editor backups of `config.php`, `global.php` and `plugins.php`, by the file Apache maps rather than the request URI, so repeated slashes no longer reach vendor PHP when `MergeSlashes` is off; the optional `.htaccess.dist` also denies `phpunit.xml.dist` (#747).

- Update the native CI scanner to Semgrep 1.179.0 and hash-locked PyJWT 2.15.1, removing vulnerable scanner dependencies while preserving the existing scan policy.

- Render the console home with Symfony and Twig, preserving native login, plugin HTML/session hooks, translations and remote collector status. Add an authenticated help overview while retaining the contextual-help JSON endpoint.

- Render color templates and their color items through Symfony and Twig. Edits, item order and bulk actions are revision-checked, templates used by an aggregate graph or aggregate template stay selectable for duplicate and sync, and the server now refuses to delete them. Sync runs each template in one transaction, so a failure no longer leaves part of a template's aggregates updated.

- Render the permission denied page with Symfony and Twig, keeping the `custom_denied` reskin hook, the AJAX denial text, the validated Return link and existing translations.

- Serve Device Rules (automation templates) through Symfony and Twig. Edits, moves and deletes are revision-checked and recheck the Automation realm in their transaction; a move renumbers the discovery match order without gaps, and a match the database cannot compile as a regular expression is refused. Old `automation_templates.php` links navigate; posts to it no longer change data.

- Serve the Data Source Profile list, editor, RRAs, duplication and deletion through Symfony and Twig. Every change is revision-checked and rechecks the Data Source Profiles realm in its transaction, a profile used by data sources refuses structural changes to its RRAs as well as its step, factor and functions, and deletion refuses the default profile and refuses the whole selection when any profile is in use. Old `data_source_profiles.php` links navigate and its size and span lookups still answer; posts to it no longer change data.
- Add native Symfony/Twig list views for users, groups, authentication domains, discovered devices, collectors, trees, recorded RRD cleanup candidates, SNMP agent objects, notification history and login history. Existing PHP forms retain editing and operational actions (#1008).
- Forward ten more legacy root pages through the shared Symfony forwarder, and fail CI when a change adds a legacy root web page. The plan for the remaining Twig migration is in `docs/migrations/twig-completion-plan.md`.
- Profile requests. With `KADUPUL_PROFILE=1`, legacy pages append wall time, peak memory and query count to `var/profile/requests.jsonl`; the Symfony profiler runs in dev and test, and `tests/tools/profile_routes.py` compares each legacy URL with its Symfony route on time and query count.
- Fail Sonar-analysed pull requests and merge groups when a changed executable line is not covered by tests.
- Show plugin menu entries added through the `config_arrays` hook on Twig console pages. A plugin menu that fails to load falls back to the built-in menu.
- Render eight confirmation pages from two shared Twig templates without changing their HTML.

## [1.3.0-alpha.1]

- First Kadupul preview for isolated evaluation. This is pre-alpha software, with no production support or supported migration path. It combines the existing monitoring, polling and graphing application with an incremental Symfony 7.4 migration; PHP 8.4 or later is required.
- Ship a dependency-complete offline archive with signed provenance and a container image with signed provenance. Release publication waits for artifact verification; consumers must verify the signatures against the Kadupul repository and release workflows.
- Assess the full Git tree with OpenSSF Scorecard, pin test runtime images, and continuously fuzz the vendor asset integrity boundary. These controls do not constitute a security certification or a Best Practices badge.
- Measure the CDEF preview memory budget from its own execution rather than earlier tests' process-wide peak; retain the existing 8 MiB limit.

- Require configured data input validation before collection; invalid or missing approvals stop affected methods. Unconfigured installations retain their existing behavior; refresh configured approvals with the input whitelist CLI before resuming collection.

- Add Pest serial profiling and isolated parallel feedback with complete JUnit discovery checks and hardened XML evidence parsing.
- Diagnose unavailable PHP POSIX/process functions and Linux `/proc` before native worker-boundary self-tests start workers.

- Serve the CDEF list, editor, items, duplication and deletion through Symfony and Twig. Every change is revision-checked and rechecks the CDEF realm in its transaction, a CDEF can no longer include itself through another CDEF, and deletion goes through the CDEF reference contract. Old `cdef.php` links navigate; posts to it no longer change data.

- Serve the RRD check list through Symfony and Twig; purging now needs a POST with a CSRF token and rechecks the Utilities realm in the same transaction, and old `rrdcheck.php` links and posts no longer change data.

- Render GPRINT presets through Symfony and Twig in the console layout. Edits and deletes are revision-checked, and deleting a preset still used by a graph or graph template is now refused on the server. A legacy menu visit keeps the saved list filters.

- Render converted Twig pages in the console layout with the active theme and a realm-filtered console menu, and share list macros across the palette and VDEF pages.

- Remove the obsolete Snyk dependency-path ignore without adding replacement exceptions, and clarify that Dependabot-triggered pushes also skip secret-dependent Sonar analysis.

- Add explicit native test-suite commands and a guide to their separate dependencies; name source-contract tests after their behavior and restore isolated input-string validation tests against the production validator.

- Upgrade the managed jQuery distribution to 4.0.0 and replace removed JSON parsing and trimming APIs in realtime and Midwinter pages.
- Reuse the device form failure presenter for association, collector, maintenance, and automation actions while preserving operation-specific statuses, translated messages, and authorization responses.

- Verify authenticated graph-tree confirmation and native automation placement handoffs, including rejected foreign/non-header parents and admitted destinations; require their measured behavior checks.
- Restore database-client TLS option selection before atomic audit imports, reject unknown or failing client versions before audit writes, and preserve literal argument and owned SQL cleanup contracts.

- Keep CLI site tree nodes consistent with web placement by storing their site identity without a copied title; verify parent, duplicate, and malformed-input refusals through the actual CLI/API and require their measured behavior checks.

- Make the missing input-field index and aggregate creation timestamp repairs reachable from deployed 1.2.31–1.2.34 through the main 1.2.35 upgrade step; confirm native schema changes and preserve a retryable version on failure.

- Stream audit upgrade progress before the worker exits, withhold protocol markers, preserve failure receipts, and keep JSON responses and final diagnostics free of duplicate output.

- Reject malformed schema-audit reports with controlled CI diagnostics; cover clean, failed, dirty, empty, and missing-baseline contracts.

- Return failure from the legacy audit CLI when repair or export fails; report missing tables separately from attempted ALTER statements and preserve SQL comment prefixes in diagnostic plans.
- Preserve recognized scriptless upgrade releases, reject missing required migrations, and confirm each intermediate database version before advancing or reporting success.
- Report integer signedness drift for manual range review, preserve local text collations during unrelated schema repairs, and refuse index algorithms unsupported by the effective storage engine.
- Authorize non-deletion graph and data-source bulk selections with current owner/policy SQL in chunks of 1,000, retaining the established 10,000-object safety ceiling and supported 5,000-row UI batches.
- Batch device management reuses the same bounded current-policy selection checks before protected names or action hooks. Malformed decimal, exponent and floating-point IDs are refused for graph/device selections and filtered from data-source selections; valid zero-padded or whitespace-normalized integer representations retain their order and duplicates.
- Management graph/data list and count queries reuse policy ID subqueries instead of hydrating the complete device inventory. Graph lists apply the same graph/template policy as actions, and data-template filter choices derive only from admitted sources.
- Main device management intentionally requires both the Devices realm and object policy access. Creating a device does not grant a new device permission; default-deny operators need an explicit applicable policy grant before managing the new device. LTS behavior is unchanged.

- Display graph access refusals once after an AJAX-compatible redirect; preserve graph and device authorization before editor lookup or session locking.

- Allow unrestricted administrators and device operators to purge unfinished unassigned and orphan troubleshooting checks; preserve device-scoped purge for restricted operators.

- Authorize complete graph and data-source deletion scopes before reading dependent names or writing; retain safe confirmation choices and reject oversized dependency batches. Report collector or volatile-cache partial outcomes separately from local transaction cleanup.
- Register graph-item choice sources in strict cookie and domain-authentication coverage evidence.
- Inline dark graph hover helpers while preserving timer element snapshots and keyboard access. Prefer the native own-property API for icon lookups while retaining the older-browser fallback.

- Bound legacy Boost writer lock acquisition to 60 attempts; report contention and retain queued samples instead of waiting indefinitely.
- Correct Settings help for graph permission combinations, auto scale limits, and CSP nonce reporting and enforcement. Fixes #222, #230, and #265.
- Make SonarCloud selective during modernization with an explicit enable switch, trusted analysis/release branches and manual runs; retain complete coverage and visible failures, and add bounded CI jobs, safe concurrency, dependency download caches and a future required quality-gate check.

- Preserve completed or failed installer worker state when a concurrent status poll hydrates an unchanged step; retain explicit transitions, navigation, and retry defaults.

- Verify access-only session expiry refresh on refused remembered-login restoration without relying on wall-clock timing; retain exact credential and adjacent-session checks.

- Verify that the pinned Snyk dependency scan completes all five repository projects through its documented legacy testing path, while retaining the existing vulnerability policy.

- Run an explicit Sonar scope check on every eligible update; skip expensive coverage only for verified ordinary documentation changes and retain full analysis for relevant or uncertain inputs.

- Install the pinned Python test dependency in an isolated environment before Snyk scans all repository projects.

- Reduce repeated native test coverage parsing while preserving complete uncovered-source reports and strict subprocess evidence checks.

- Preserve heartbeat propagation for template-only and local data sources; accept unchanged read-only fields and independently validate consolidation edits before writes.
- Render sortable header labels as text, including stored automation fields and plugin `device_display_text` labels. Arbitrary label markup is now escaped; existing entities, generated sort icons and sorting callbacks are preserved.

- Preserve existing NaN gaps while removing stddev and variance spikes; count only completed replacements against the per-archive limit and report the selected replacement mode. Fixes #238.

- Preserve cached HR-MIB disk allocation units with the `Bytes` suffix; reject fractional, non-finite and out-of-range Integer32 samples as unknown.

- Preserve each graph item's numeric SHIFT or TICK value, including backward shifts; validate numeric fields only when rendering uses them.
- Preserve trusted RRA directory links and custom configured roots during cleanup; retain final file links and unsafe proxy or archive targets without creating directories outside the archive.

- Map complete exclamation-delimited poller field lists from PHP and Spine while preserving scalar exclamation marks and hexadecimal values, including hexadecimal-looking field names. Fixes #284.
- Query the shipped `poller` table when checking multi-collector Boost settings, preserve the current system setting if that count fails, and normalize the bounded 120-minute fallback before master scheduling arithmetic. Fixes #270.
- Trust forwarded client IPs only when the TCP peer is an explicitly configured
  proxy and exactly one allowlisted header contains one IP address. The unsafe
  `proxy_headers = true` mode no longer authorizes forwarded addresses.
- Limit data source troubleshooting, repair, purge, and bulk operations to allowed devices.
- Enforce device and graph access checks in Graph Management and Data Sources, including bulk confirmation lists and device reassignment.
- Scope both AJAX graph-input data-source choice queries to the actor's device policy before returning names; retain authorized current choices and device-less sources without loading the full device inventory.
- Skip unauthorized graph trees before name lookup on bulk-action confirmation pages.
- Restrict new graph creation and data query reloads to allowed devices.
- Require graph access before spike handling reads or rewrites an RRD.
- Restrict data source edits and RRD item changes to devices the user may access, and reject custom RRD paths that escape the configured RRA directory.
- Restrict graph edit, create, and device-move operations to graphs and devices the user may access.
- Require device access before viewing, saving, reindexing, pinging, reloading queries, or changing templates for a device; also enforce access in the web-facing device save API.
- Limit Data Query Cache and Poller Cache views to devices the current user may access, including their default Any filters.
- Report schema audit drift for defaults, collations, indexes, and missing tables without treating a stale fork baseline as automatically repairable. Fixes #454.
- Add Docker MariaDB integration coverage for the maintenance CLI commands on `main`, and validate CLI selectors, permissions, audit reporting and selected-host poller-cache behavior.
- Reject non-positive and fractional poller-cache thread counts, include the audit baseline and runtime dependencies in Docker integration coverage, and fail before starting the test stack when its configured test pattern matches no files.
- Resolve JavaScript and CSS include paths through Symfony Filesystem while retaining the legacy helper's search order, relative-path rules, and missing-file notifications. Closes #489.
- Read legacy current-page and browser URL values through Symfony HttpFoundation while retaining the existing helper signatures, server-variable precedence, and URI sanitization. Refactors #484.
- Preserve text-column collation when repairing from an older schema baseline, and match missing-table checks to the baseline's case-insensitive table identity.
- Confirm an interactive database upgrade before preparing and confirming its resulting repair plan; stop repair on failed or unconfirmed upgrades and honor the cron setting for quiet upgrade deprecation warnings.
- Enforce persistent CDEF reference integrity for legacy writers and atomic aggregate cache replacement; refuse online collector primary upgrades and confirm the final installation version before reporting success.
- Roll back complete aggregate regeneration and creation on unconfirmed reads or writes, preserving caller transactions; reject malformed aggregate item selections before saving and report incomplete propagation.
- Keep permission writes and session epochs consistent, including failed writes and absent deletions.

- Report failed graph-to-report inserts accurately and handle missing graphs without PHP warnings.

- Preserve explicit PNG realtime graph output and search filters across RRD cleaner pages; identify the CSRF refresh utility correctly in its CLI metadata.
- Migrate Data Input Methods and fields to Symfony/PHP 8.4 and Twig, with current authorization, revision checks, transaction rollback and explicit collector/whitelist retry outcomes.
- Add Symfony Inventory tree/report placement using owning Graphing and Reporting contracts, authorization, revisions and transactional confirmation.
- Retain old collector polling copies until primary assignment commits, repair missing associations on unchanged template assignments, and preserve remote enabled-state drift during bulk site/template changes.

- Migrate VDEF listing, editing, item ordering, duplication and deletion to Symfony/Twig with realm checks, CSRF protection, stale-form detection and dependency-aware deletion; retain safe legacy URL redirects.
- Share user and group permission-filter rendering while retaining their existing routes, translations, template choices and filter events. Part of #699.
- Handle recovery CLI help and version flags before requiring remote database connections; retain recovery samples when a normal run cannot connect.
- Add a reusable local write transaction helper with caller-owned savepoints, persistent InnoDB checks on the selected PDO connection, and native MariaDB/MySQL regressions.

- Add a reusable local write transaction helper with caller-owned savepoints, persistent InnoDB checks on the selected PDO connection, and native MariaDB/MySQL regressions.
- Replicate complete Data Source Profile definitions before collector references, retaining existing collector rows if delivery fails.
- Coordinate all Data Source Profile definition writers with deletion and preserve unchanged legacy references.

- Index RRD input-field references on fresh installations and through a registered schema upgrade from main 1.2.31 or LTS 1.2.32, keeping reference locks scoped to the selected fields.
- Allow user settings and credential metadata to store the full user account ID range on fresh and upgraded databases.
- Move External Links into the Navigation Symfony module with Twig forms, transactional viewing grants, stale-order protection and safe legacy redirects.
- Revoke migrated-route sessions and their remember-me tokens after credential replacement, preserve caller transaction ownership during revocation and concurrent transparent hash upgrades, and retain Console section names equal to `0` in External Links.

- Serialize database CSRF rotations on the primary and each collector, verify persisted keys, and bound the CLI worker to 30 seconds. Run database rotation on the primary collector; external-file rotation remains available on remote collectors.

- Write device poll status back by device id, so devices that share a hostname no longer overwrite each other. Fixes #688.
- Refresh DOMPurify to 3.4.16 and retain the application's sanitizer compatibility patches and source verification.

- Add the CSRF token only to same-origin XMLHttpRequest, jQuery and form posts in the installed CSRF Magic browser script. Form targets are read from the `action` attribute, so a control named `action` cannot hide them; relative URLs resolve against the document base; and token fields are withheld when a submit button's `formaction` points to another origin. The submit check is installed when the script loads rather than from `CsrfMagic.end()`, so an unclosed `plaintext`, `textarea`, `title`, `xmp` or comment that keeps the end-of-page call from running cannot switch it off. Browsers without `SubmitEvent.submitter` send no token from a form that has any button with a cross-origin `formaction`, or a non-POST/invalid `formmethod` override, including the form's same-origin buttons. The legacy dependency installer now applies checksum-verified patches recorded in `legacy-files.json`.
- Stop the CSRF Magic output handler from adding the token to forms that post to another origin. Only forms with no action or a relative action get the field from the server; absolute and protocol-relative actions are left to the browser script, which checks their origin. Attributes are read as the browser reads them, so a quoted `>`, a second `action`, character references, backslashes or control characters cannot hide the target, and any `<base href>` that is not relative withholds the field from relative actions too, even when it names this origin, and GET forms no longer receive the token when another attribute contains `method="post"`. Form tags inside comments or the text of `textarea`, `title`, `script`, `style` and similar elements get no token, nor does a form opened while an earlier form is still open, since the browser would move the token into the outer form. A `<base href>` only counts where the browser would parse it, and a page the handler cannot read to the end gets no token in relative-action forms. Tag names, attribute names, the method and URL schemes are compared as ASCII, since Kadupul sets `LC_CTYPE` from the user's language and in a Turkish locale `strtolower()` on PHP 8.1 and the PCRE `/i` flag do not fold `I` to `i`: `ACTION`, `SCRIPT` and `XI:` were misread there, and upper-case `SCRIPT` or `TITLE` elements stopped legitimate forms from getting the token.
- Read the document base URL in the CSRF Magic browser script through `Node.prototype`, so an element named `baseURI` cannot make every same-origin request lose its token.
- Port the remaining CSRF Magic library checks from 1.2: refuse more than eight submitted tokens, token times that are not digits or exceed the 300-second future clock-skew allowance, and generate fallback secrets with `random_bytes()`. The optional CSRF debug log and the default failure page no longer record tokens, the secret, form values or query strings. Secret rotation generates before modifying the working file, then exclusively writes and verifies a replacement in its directory, preserves existing UID/GID with verified ownership before applying mode 0640 and renaming atomically; generation or publication failure preserves the working key. Symlink file destinations are refused.
- Keep inaccessible SNMP cache entries as navigation-only links and return `NONE` for direct reads without PHP 8.4 warnings.

- Draw core icons from one registry, `config/icons.json`, which maps names such as `add` and `collapse-all` to Font Awesome 7 classes. `html_icon()` refuses an icon with no accessible label unless it is marked decorative, `layout.js` reads the same map, and themes redraw icons through registry overrides instead of rewriting classes in the page. Console menu glyphs are now registry names; a plugin's Font Awesome classes in `$menu_glyphs` still render as given. Plugin icons are no longer restyled by a theme: paw, paper-plane and sunrise leave `fa-arrow-down` and `fa-arrow-up` alone, and midwinter leaves plugin menu glyphs alone.
- Check graph permission on a remote data collector before it asks the main poller for a graph image.
- Keep cached graph and tree permission answers separate for each user, so an emailed report only includes graphs and trees its owner may view.
- Check that real-time graphs are enabled, that the user has the Real-time realm and that the user may view the graph before a real-time request polls any device or returns a cached image.
- Save real-time graph preferences only from a token-checked POST; polling by GET no longer changes them.
- Show the graph page's Real-time button only when real-time graphs are enabled and the user has the Real-time realm.
- Show a report device item according to the owner's device permission rather than the permission of an unrelated tree.
- Render About through Symfony and Twig with escaped version/beta text and complete English/French license information; preserve authenticated access without a console realm, including online collectors authenticated against their reachable primary.

- Render the color palette through Symfony and Twig, with protected named colors, revision-checked transactions and strict quoted CSV imports/exports.

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
- Resolve graph fonts in one Graphing `GraphFontResolver` from the theme, the System settings and the viewer's own fonts. Graph `--font` arguments, the Default Font for local, proxy, Data Source statistics and RRD check RRDtool processes, the error image text size and the Boost render key all use it, and a golden test pins the `--font` arguments to the previous code for valid settings.
- Reuse one RRDtool proxy session for the commands in a graph render, including consolidation-function lookups. Part of #502.
- Preserve negative integer `--units-exponent` values accepted by graph forms. Fixes #228.
- Complete Inventory site editing, sorting, duplication and deletion through Symfony; retire the procedural Sites page while retaining safe legacy URL compatibility.
- Refresh the Midwinter theme's bundled hotkeys-js to 3.13.15 and ua-parser-js to 1.0.41, matching the 1.2 LTS branch.
- Draw the classic theme's Console, Graphs, Reporting, Logs and external-link tabs as translated text links styled in CSS instead of GIF images and GD-rendered pictures of text. Tab ids, link targets and accessible names are unchanged, but the Reporting and Logs tabs now read "reporting" and "logs" rather than the old "nectar" and "clog" pictures. The tabs also show a visible keyboard focus ring, and external-link titles in scripts the bundled DejaVu fonts lack, such as Chinese or Japanese, no longer render as empty boxes.
Targeting `v1.3.0`, the first planned application release. See
[VERSIONING.md](VERSIONING.md).

### Tests

- Generate the audit baseline from a fresh install in CI and fail when its schema rows differ from the checked-in baseline. Fixes #453.
- Audit both fresh installs and release-upgraded databases against the checked-in schema in CI. Fixes #452.
- Validate locally authored and conflict-resolved merge content without rejecting whitespace inherited unchanged from the incoming branch.
- Add native SQL-backed regression coverage for HTML filters, graph drilldown context, ordered report rendering, and realm/view/role/simple-permission helper contracts. Part of #699.

- Exercise user-log cleanup against real MySQL and MariaDB, preserving each current account's latest login and token while removing failed and orphaned entries; collect coverage from the actual controller.

- Exercise local login, password changes, logout, user/group realm and permission changes, and report ownership/persistence through native production files with isolated SQL fixtures. Part of #699.

- Characterize `is_resource_writable()` for existing files, new files, directories, and permission-denied paths before changing the legacy filesystem check.

### Deprecated

- Deprecate `cli/audit_database.php --upgrade` and direct operators to run `php cli/upgrade_database.php` separately. Retain the flag for compatibility and print a warning to stderr. Part of #457.

### Fixed

- Preserve an explicitly disabled installer Scan Mode through settings reload and the automation network update (#1116).
- Reuse one PHP script server per remote realtime polling batch and close it after success or failure (#1114).
- Return a nonzero status for refused upgrade start versions, missing required migration scripts, migration errors, or failed version-marker writes. Fixes #240.
- Check linked data-source and poller device owners before graph device reassignment and before graph form writes; denied, missing, or malformed child owners leave the graph and its children unchanged. Template-only references remain non-device records.
- Validate installed font families after recognized Pango suffixes, reject names when fontconfig reports no installed fonts, and preserve complete multiword default descriptions through RRDProxy session setup.
- Make the graph spike-removal menu reachable with Enter and Space, restore focus on Escape, and discard obsolete menu replies after script reloads.
- Keep Paper Plane content links readable on hover; measure contrast using opacity groups and image-layer order, and reject new or worsened measured failures.
- Invalidate the affected user or group members' permission caches after removing a typed permission exception.
- Rebuild dependent poller caches once when updating a Data Input whitelist, with the worker retaining propagation failure reporting.

- Validate replicated resource-cache paths against the installation tree, protect `include/config.php`, and run PHP syntax checks without a shell.
- Restrict Script Server dispatch to PHP files under `scripts/` and only call functions declared by the selected file.
- Validate dashes, dash offsets and alpha values on graph-item and graph-template-item edit pages, preserving fractional dash patterns and offsets. Filter and quote those fields and the SHIFT and TICK values when rendering graphs, including values stored through graph-input overrides and template imports.
- Run binary SNMP get, getnext and walk commands through Symfony Process argument arrays, keeping hostnames and SNMP credentials intact as single arguments on Windows. Fixes #531.
- Bind the data-query output type when rebuilding poller cache entries and skip malformed values, so stored field text cannot alter the SQL query. Fixes #533.
- Validate graph-item TICK fractions and SHIFT offsets as single numeric tokens before saving or writing RRDtool pipe commands. Malformed legacy rows are skipped. Fixes #535.
- Require the Realtime Graphs realm and graph visibility before reading a graph cache or starting its realtime poller, including requests made through the guest account. Fixes #537.
- Treat missing or invalid Host Resources allocation units and negative disk samples as unknown instead of reporting raw units, raising a type error, or guessing an unsigned wrap. Fixes #243.
- Enforce the spike-removal replacement budget across every row and data source in an RRA, reset it only at the next RRA, and count NaN replacements. Fixes #238.
- Apply `nan` replacements in the spike-removal window modes and count changed samples, so float and fill no longer report success without changing the selected samples. Fixes #237.
- Save an editable profile heartbeat even when the browser omits the disabled step field, while refusing changes to structural fields for profiles used by local data sources. Fixes #232.
- Make the documented `add_tree.php --node-type=site --site-id=ID` workflow create a site tree item, persist its `site_id`, and render its current site devices. Reject unknown site IDs before inserting a row. Fixes #235.

- Reject tree CLI nodes with a missing tree, a missing or foreign parent, or a non-header parent; report failed node creation with a nonzero exit status. Fixes #236.
- Limit profile heartbeat propagation to the matching data template as well as the local data source, so template rows sharing the zero local-data sentinel do not overwrite unrelated templates. Fixes #233.
- Recheck data-source profile references when a bulk deletion is submitted, preserving definitions still used by templates or sources while allowing unused profiles in the same selection to be removed.
- Make the effective graph-policy display honor explicit template grants under Restrictive mode when the template policy defaults to Deny. Fixes #263.
- Verify worker-session MariaDB snapshot settings before association/maintenance transactions so heartbeat updates do not invalidate the final collector locking read on MariaDB 11.8.
- Allow collector heartbeat updates during Inventory association and maintenance network work; revalidate collector configuration and availability immediately before commit.
- Budget Inventory worker processes for every remote data-query request, preserving the existing local-work margin and the configured remote timeout cap.
- Reject malformed association commands before mutation, propagate query refresh failures in strict workers, and publish authoritative primary changes before collector commits.
- Restore the `data_input_data.data_input_field_id` index and normalize the `aggregate_graphs.created` timestamp during the 1.2.31 upgrade so upgraded databases match the fresh-install schema.
- Stop schema report, alters, and repair when the canonical baseline cannot be loaded, returning a failing CLI status and JSON `failed` status without results. Preserve explicit create/load mode messages and legacy exit conventions; missing or unparsable create inputs leave existing audit tables untouched. Fixes #242.
- Remove orphaned user-log entries even when no current user accounts remain.
- Stop token generation when the cryptographic random source fails instead of returning a predictable fallback. Fixes #580.
- Refresh generated Midwinter stylesheet import versions during the browser build so uncompiled installations invalidate changed child CSS.
- Return a failing CLI status and JSON `failed` status when any database table analysis fails, and use the correct `ANALYZE NO_WRITE_TO_BINLOG TABLE` syntax on main. Fixes #241.

- Show color names, data input field names, group names in permission reasons, and graph, device and site names in the tree editor as text; these places parsed the decoded names as markup. Copying a user group now rejects a prefix that the group name rule refuses, and SNMP notification tooltips no longer use inline handlers, so they also work under a nonce CSP.
- Build offline archives with the npm JavaScript CLI bundled with the selected Node runtime, avoiding shell-wrapper parse failures in CI. Related to #703.
- Preserve both existing audit baseline tables until a staged import is validated and atomically installed; report failed imports and repairs with a nonzero CLI status. Fixes #242.
- Invoke standard plugin upgrade callbacks during database audits and quote upgrade script paths and arguments.
- Ask for a Pango font description in the graph font settings instead of a TrueType font file. RRDtool 1.3 and later pass the value to Pango, which ignores a file path and draws its fallback font.
- Draw Midwinter graph legends in DejaVu Sans Mono. Its `Roboto Mono` setting fell back to the proportional DejaVu Sans on hosts without Roboto, including the container image, and misaligned legend columns.
- Draw the graph error image with the bundled DejaVu font when no system DejaVu Sans is installed, and wrap its text by measured width and whole characters. The GD fallback used the point size as a built-in font id, so lines overlapped and ran past the frame, and translated messages were cut inside UTF-8 characters.
- Search the bundled `include/fonts` directory on Windows as well. Classic theme external-link tabs lost their labels there because Windows ships no DejaVu font.
- Refuse a graph font name that is not a Pango font description, or that names no font `fc-list` reports as installed, when System, User or User Group settings are saved. Without `fc-list` a well-formed name is saved and the unchecked save is logged. In Theme font mode the hidden font fields keep their stored values unchecked, so a stale font does not block the rest of the Visual tab. A stored font file path or a name with double quotes, colons or control characters now draws the Default Font instead of reaching RRDtool, and is no longer sent to an RRDtool proxy.
- Key Boost cached graphs by the fonts a render resolves to. The key left out the Default Font, so a changed Default Font served graphs drawn with the old one.
- Refuse graph font sizes of 4 points or less, or above 72 points, when System, User or User Group settings are saved. The profile page no longer stores a cleared or invalid size as the user types, and User Group settings store the default instead.
- Replace graph font sizes that are empty, non-numeric, infinite or 4 points and below with the default, and cap larger ones at 72 points. A thumbnail with an empty title size threw a `TypeError`, and a size such as `1e400` made RRDtool reject every graph.
- Bind graph-template and local graph item ordering filters as parameters and preserve the non-classic theme fallback when available. Related to #476.
- Bring the lts/1.2 authentication hardening to main. Leaving the retired no-authentication method now switches to local authentication without starting a session or clearing the administrator password; the administrator signs in and must choose a new password. The guest account can no longer open Edit Profile. Failed LDAP and domain logins show one generic message and keep the directory error in the log. A local login for an unknown username costs the same password hashing as one for a known username. A remember-me login with a pending forced password change goes to the change page, every logout path deletes the server-side remember-me token, and a malformed remember-me cookie is ignored.

- Require sessions created before password binding was added to sign in again after upgrade; an unbound session cannot safely adopt a password changed while dormant.
- Preserve authenticated sessions through transparent password hash upgrades using a credential generation stored in user settings. A password replacement changes the generation; rehashing preserves it atomically with the password update and refuses a concurrently replaced credential.
- Check the directory's certificate on LDAPS and StartTLS connections by default. A new install starts at Demand, and an install that has a requirement stored keeps it. An existing install with no stored requirement that uses LDAPS or StartTLS, in the LDAP settings or on any domain, gets Never stored, the level it has always run at, so directory logins keep working: the web installer stores it during an upgrade, `cli/upgrade_database.php` stores it when upgrading from a version before 1.2.31, and an install whose database already reports 1.2.31 stores it at the first login attempt after the update. An existing install with no stored requirement and no LDAP encryption gets Demand stored at that point, so encryption turned on later checks the certificate. Administrators on Never or Allow should add the directory's CA and raise the setting, which now warns that those levels let anyone on the network path read LDAP passwords. Changing or resetting a password now ends the account's other sessions with file and database session storage. Group membership, group deletion and permission changes take effect in live sessions. With Force Complexity Upon Old Passwords on, a user whose password breaks the rules can finish logging in and is sent to the change page, an account that may not change its password is refused with the ordinary login failure, and an unknown username gets the same answer as a wrong password. A password an administrator sets in User Management must meet the complexity and history rules, the form's live check reports the rule a password breaks, and mismatched passwords stop the save. Wrong current passwords on the change password page count toward lockout, and a disabled or locked account can no longer use that page. Bulk Delete, Disable and Batch Copy leave the primary administrator and the acting account alone, and Batch Copy takes only a local account as its template. The User Management group filter accepts only a group number. User group changes that name a missing group are refused, and Copy carries the group's realms and permissions. Edit Profile stores only values a setting's field allows and needs the graph settings permission for graph settings; a refused value is marked on the form. The tree width and page refresh settings are printed as numbers on the Graphs page. Page error reports from help.php are limited per session and per user and escaped in the admin mail. A password rehash after login updates only the local account that logged in.

- Refuse state-changing actions sent by a cross-site GET or by a method other than GET and POST; same-origin links and requests keep working. This covers table purges, rule quick edits, the RRD Cleaner rescan and the Data Debug purge; the SNMP notification receiver log purge, the SNMP Agent notification log purge and the log viewer purge now need a POST. Refuse a CSRF token whose time is not a number instead of failing with a PHP error, stop accepting CSRF tokens bound only to the client address, and keep the CSRF secret out of the document root: it comes from `$path_csrf_secret` outside the document root or from a random database setting, which `cli/refresh_csrf.php` now rotates.

- Show the reason for a Remote Data Collector logout, close the script tag on the Permission Denied page, keep an anonymous visitor on the login page after the session lifetime, and show the Web Basic failure page when the template user is missing.

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
- Key Boost's cached graph images by the fonts, font sizes, colour mode, date format, time zone, language and requested theme each viewer renders with, and by the requested output format. A graph drawn with one user's custom fonts, date format or browser time zone was served to other users of the same theme until the cache expired, and the reverse ignored a user's own settings. Graphs rendered by the remote agent and by reports are now also written under the name their own theme and size options produce, rather than the global request's. The `graphv` output of `graph_json.php` and PNGs forced over an SVG template no longer share a file name with the plain image. The name also carries the graph's start and end times, so users whose first weekday or day shift hours differ no longer share an image for the This Week, Previous Week or Day Shift presets.
- Keep SNMP agent cache values on one `pass_persist` protocol line by removing embedded carriage returns and line feeds before storage and output.
- Normalize Graph View graph-list values before storing them in the session, escape them in HTML, and encode them for JavaScript. Removing the last selected graph now clears the stored selection, while paging preserves it. Fixes #574.
- Escape and type-check the posted local graph ID before rendering Aggregate Graphs bulk-action confirmation markup. Fixes #586.
- Escape color-dropdown values and enclosing form row IDs in their HTML contexts; render color option identifiers as integers. Fixes #576.
- Recheck data-source profile references when a bulk deletion is submitted, preserving definitions still used by templates or sources while allowing unused profiles in the same selection to be removed.
- Escape device and network values before adding them to automation discovery HTML emails. Fixes #589.

- Show a keyboard focus ring in every theme. Links and buttons clicked with the mouse show no ring; text fields now show it on click as well, as browsers apply :focus-visible there. In modern, paper-plane and midwinter the ring carries a contrasting halo, so one ring reads on both the light and dark panels. Keep switch checkboxes and radios reachable by Tab, let keyboard users reach the dark theme's graph utility icons, and improve contrast for text, icons, focus rings and the edges of fields, menus and switches at rest, hovered, focused and in selected rows. Midwinter now takes its status, icon and control colours from per-mode variables. `tests/e2e/tests/theme-contrast.spec.ts` measures real pages in every theme; focused declaration tests cover the changed dark, paper-plane and sunrise palettes. These checks do not establish AA conformance for every page. The themes also style `h1.loginHeading` like the login legend, ready for the login markup change.

- Create the identity audit file with restrictive permissions without changing the process-wide umask, which could otherwise affect unrelated threaded requests. Fixes #382.
- Fix blank and wrong Font Awesome 7 icons: the midwinter filter icon no longer shows a missing-glyph box, the multiselect collapse-all and expand-all buttons show their arrows, and the legacy `fa-circle-thin` class draws an outline circle again.

- Give icon-only controls an accessible name: the Add and page help links, the tab menu buttons, the Console tab in themes that hide its text, and the Data Source troubleshooter's pass and fail icons.

- Escape the values that graph views, start boxes, table headers and the spike removal menu print into HTML attributes and text. The realtime popup link now passes the graph URL to `window.open()` as encoded JavaScript strings, and the graph id is always a number. Forward-ported from lts/1.2.

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

- Accept an LDAP Domains login only for a realm that is an enabled domain, and always bind against that domain's directory first. Any other realm, a domain without directory settings, and a directory user with no account and no domain template are refused instead of falling through to the default template or guest account. Domain login errors no longer show directory error text on the login page; the log keeps it.
- Index RRD input-field references on fresh installations and through a registered schema upgrade from main 1.2.31 or LTS 1.2.32, keeping reference locks scoped to the selected fields.
- Allow user settings and credential metadata to store the full user account ID range on fresh and upgraded databases.
- Require a POST with a CSRF token to move, delete or add items on the CDEF, VDEF, color template, graph, graph template, data source, data template, data query, data source profile, device template, automation, tree and external link pages. Their move and delete links now post from the page and load the result in place; a GET for these actions gets 405.

- Require a POST with a CSRF token to add or remove device graph templates and data queries, to create, rename, copy, move or delete tree branches, set their sort order, or sort the tree list, to delete automation rules and data input fields, to enable or disable a data source, to remove a color, and to rebuild the poller, resource and SNMP agent caches, purge data source statistics, or clear or purge the Kadupul and user logs. The pages that offer these actions now post them with the token.
- Require a POST with a CSRF token to lock or unlock a tree for editing, reorder trees by drag and drop, change, reload or verbosely re-run a device data query, turn device debugging on or off, and repopulate a device's poller cache. The tree, device and new graph pages now post these actions with the token.
- Require a POST with a CSRF token to reorder items by drag and drop on the CDEF, VDEF, automation SNMP, automation template and color template pages, and to re-run a data query from the new graphs page. These pages now post the reorder and the reload with the token.

### Changed

- Share ordered System and profile graph-font metadata while preserving their defaults, translated labels, validation and field order.

- Route Inventory device menus and legacy `host.php` links through Symfony. Legacy POSTs expire without replay; GET action links open confirmation forms. Existing automation rules are applied through an Inventory use case and Automation adapter. Preserve collector, template and exact-location list filters.
- Device pages no longer execute legacy plugin UI hooks or custom device-page actions. Plugins must expose their own pages or adopt Symfony extensions; existing save/template/association/bulk action callbacks remain in isolated adapters. Legacy device CSV links now export the selected public-data page instead of unbounded host records.

- Add Symfony device maintenance for reindexing, query diagnostics, polling cache refresh, debug controls and connectivity checks with secret-safe plain-text results.
- Migrate device data-query associations and reindex settings to Symfony, retaining graph data and verifying primary/remote cache cleanup.
- Add Symfony device graph-template association editing with legacy automation hooks, stale-association protection, remote verification and existing-graph retention.
- Run cli/audit_database.php through kadupul:database:audit, with --json and --dry-run. The flags are unchanged apart from --dry-run, --json, --force and a bare, empty or spaced --as, which the shim now refuses; it takes the operator only as --as=NAME. Under bin/console, --repair only plans unless --force is given or the operator confirms the plan at the prompt. The command requires an operator with the Console Access and Installation/Upgrades realms. It reads docs/audit_schema.sql itself instead of piping it into the mysql client with the database password on the command line, and it builds each repair statement from typed parts, so no text from the file or the server reaches the database unquoted. The upgrade and each plugin upgrade run with no shell, so a plugin's recorded version reaches its script as one argument. The schema dump targets the configured database server, so MYSQL_HOST and similar variables cannot point it elsewhere. A failed upgrade now stops the run before any repair.
- Add Symfony bulk SNMP settings with explicit credential replacement, per-device credential validation and secret-safe failure responses.

- Add Symfony bulk site, template and collector assignments with whole-selection validation, primary rollback and verified collector replication.

- Add Symfony bulk location and polling option edits with explicit field selection, domain validation, stale-option protection and verified collector writes.

- Migrate device template synchronization to a Symfony confirmation and Inventory use case with current template locks and verified collector associations.
- Fix bulk template synchronization to discover after association commit, reject unconfirmed collector identity before action 7, repair cached missing associations, and preserve no-op coverage checks.
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
- Remove a device through `api_device_remove()` as one unit: its Graphs go first, then its dependent rows and the device row in one transaction (a savepoint inside a caller's transaction) after an InnoDB check, and remote collector cleanup after commit. Inside a caller transaction, cleanup is queued transactionally for the existing purge worker instead of contacting the collector before the caller commits. Malformed or fractional device IDs are refused before any side effect. A failure leaves the device and its rows in place, raises an error message and returns false. Report items of the device are now actually removed (upstream Cacti #7732).

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
- Stop `cmd.php` and the realtime collector from reading or closing the PHP script server's pipes when the server could not be started. The run logs an error and its script server data sources report U for that cycle; `cmd.php` does not fall back to running each script through the shell (upstream Cacti #7196).

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

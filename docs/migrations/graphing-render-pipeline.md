# Graph rendering pipeline on main

This is the detailed plan for moving graph rendering out of `lib/rrd.php`. It
extends [Moving `lib/rrd.php` into Graphing](graphing-rrd.md) and keeps its
decisions: every public function keeps its name and signature for the 1.3
series, each migration slice keeps the characterization tests passing unchanged after
the explicitly gated prerequisite corrections below, and LTS is unchanged.

Line numbers refer to origin/main at `3d436ccd9` unless a branch or plugin
commit is named. A statement marked *inferred* was read from the code and not
run.

## Current flow

`rrdtool_function_graph()` (`lib/rrd.php:2120`) opens one RRDtool proxy
session when the proxy is in use and the caller passed none (`lib/rrd.php:2122-2131`),
calls `__rrdtool_function_graph()` and closes the session in a `finally` block
(`lib/rrd.php:2150-2153`). A value RRDtool cannot accept becomes the graph
error image (`lib/rrd.php:2138-2149`).

`__rrdtool_function_graph()` (`lib/rrd.php:2230-3247`) does everything else in
one function of about 1,000 lines:

1. Checks access when `$user > 0` and returns `GRAPH ACCESS DENIED` (`lib/rrd.php:2252-2256`).
2. Sets `LANG` from `CACTI_LOCALE` when it is empty (`lib/rrd.php:2260-2262`).
3. Runs the Boost image cache check, which first applies pending Boost samples
   to the graph's RRD files (`lib/rrd.php:2265`, `lib/boost.php:437-465`).
4. Fills in the time window (`lib/rrd.php:2270-2295`), picks the step and the
   archive (`lib/rrd.php:2311-2371`) and reads the graph and its items
   (`lib/rrd.php:2373-2417`).
5. Builds the options through `GraphOptionsGenerator` (`lib/rrd.php:2441`).
6. Walks the items to write `DEF`s, substitute legend variables and cache CDEF
   and VDEF text (`lib/rrd.php:2486-2637`), computes legend padding
   (`lib/rrd.php:2640-2669`), then walks them again to write `CDEF`, `VDEF`
   and drawing items (`lib/rrd.php:2683-3170`).
7. For modes other than CSV export, passes the three command strings to the
   `rrd_graph_graph_options` plugin hook and adds business-hours shading
   (`lib/rrd.php:3172-3181`). CSV goes directly to `xport` without either.
8. Prints the source, returns the error text, writes an export file, writes a
   real-time file, renders and caches an image, or runs `xport`
   (`lib/rrd.php:3184-3246`).

Rendering is not read-only. One request can apply pending Boost samples
(`lib/boost.php:451`), apply them again for each data source an Nth percentile
or summation fetches (`lib/rrd.php:2032`, called from
`lib/graph_variables.php:73`, `78` and `315`), write the real-time file
(`lib/rrd.php:3210`), write the cache image (`lib/boost.php:633-648`) and bump
the Boost SNMP counters (`lib/boost.php:522-524`, `586`). On a definition
miss, `get_data_source_path()` also generates and persists an empty path
through `generate_data_source_path()` (`lib/functions.php:2870-2907`,
`3234-3310`). The read-only DBAL connection must not perform that write.

## RenderContext

`RenderContext` holds everything about the viewer and the site that changes
the image but is not part of the graph or the request. It is built once per
request by an adapter, passed down, and never read from globals again inside
the pipeline.

| Field | Contents |
| --- | --- |
| `theme` | The validated theme name and its RRDtool palette: `$rrdcolors`, the colour-mode variants, `$rrdborder` and `$rrdfonts` from `rrdtheme.php` |
| `colourMode` | `''`, `dark`, `light` or `dark-dimmed` |
| `fonts` | The `GraphFontProfile` from PR #710, including the Default Font |
| `timeZone` | The PHP zone that dates the legend and the `TZ` RRDtool dates the axis with |
| `dateFormat` | The viewer's date format and separator, and the site's `graph_dateformat` |
| `locale` | `CACTI_LOCALE` for RRDtool's `LANG`, and the locale and country that format summation values |
| `siteGraph` | Site settings that shape every image: watermark text and RRDtool tag, gradient support, business hours, maximum title length and the RRDtool version |

`GraphRequest` holds what the URL or caller asks for: graph id, archive id
(an id, `all` or none), window, height and width, thumbnail (no legend), output
format (PNG, SVG, `graphv` metadata), a theme override, the cache opt-out, the
mode (image, export file, CSV `xport`, real-time, print source, error text) and,
for real-time graphs, the session's real-time hash.

R1's `LegacyGraphRequestFactory` uses a new Infrastructure
`LegacyGraphThemeProfileResolver` to validate an override with today's
`cacti_validate_theme()` rules and resolve the effective command theme.
Without an override it uses the captured viewer theme; empty/invalid values
retain the configured/default installed-theme fallback. It loads that theme's
readable `rrdtheme.php` and selects the existing colour-mode palette/border
fallback, version-gated border/watermark and theme fonts, with the site/user
font precedence retained through #710's `GraphFontProfile`. The result is an
immutable `Domain/Render/GraphThemeProfile` carried by
`GraphRequest.commandTheme`; the validated effective name and resolved
profile enter the request fingerprint before cache-key generation. The
viewer profile in `RenderContext` remains available for existing error-image
and cache-filename-prefix behavior. `Domain/Command/ThemeArguments` receives
the resolved command profile, never a raw override or a filesystem path.
The Infrastructure resolver owns includes and filesystem reads; R2's wrapper
uses the same resolver. R0/R1/R2 gates cover a valid override differing from
the selected theme, absent/empty/invalid overrides, unavailable/unreadable theme
files, colour-mode fallback, theme fonts with site/user overrides and version
gates. Assert actual palette/border/font arguments and cache isolation; measure
the added resolution cost against the warm-cache budget and require per-render
memoization rather than duplicate theme includes. These are planned gates.

The design brief put the output format in `RenderContext`. It belongs in
`GraphRequest`: it comes from the URL (`graph_image.php:42-72`,
`graph_json.php:104-138`), not from the viewer, and both values reach the cache
key either way.

An absent URL format is not an absent effective format. The legacy image and
JSON adapters retain their existing pre-render `graph_templates_graph`
`image_format_id` query and current mapping/fallback behavior
(`graph_image.php:42-57`, `graph_json.php:107-123`). R1's request factory takes
that resolved value; for a legacy caller without a supplied format it resolves
the same graph metadata at the adapter boundary. `GraphRequest.imageFormat`
always carries the effective format before cache-key generation, command
construction or content-type selection. R9 keeps this narrow lookup outside
`GraphDefinitions`; R10 supplies an equivalent DBAL lookup in its adapter.
The full definition is still loaded only on a miss. R0/R1 gates cover absent
overrides on PNG and SVG graph definitions, explicit overrides and each
entry point's existing fallback; cache-key and response format must agree
for explicit PNG and the P0-corrected explicit SVG paths. The frozen JSON adapter
initializes `$gtype = 'png'` at line 13 before the explicit-format branch;
explicit PNG therefore reaches the renderer and response metadata as PNG
without an undefined-variable warning. It needs characterization, not a
failing-before prerequisite or intentional output change.
Explicit SVG is different: both legacy adapters pass `svg+xml`, but the frozen
`GraphOptionsGenerator::build()` override accepts only `png` (lines 130-136).
For a stored PNG graph, an explicit SVG request can therefore return PNG bytes
with SVG response metadata. P0 separately corrects this existing mismatch in
the current generator/adapters before R0/R1. Native generator and actual
image/JSON response tests must fail before and pass after, covering stored
PNG/SVG with absent/explicit PNG/SVG requests, command format, response MIME,
JSON metadata, and cache identity; only affected SVG-override goldens change
after independent review. This is a planned correction, not an existing fix.

`RenderFacts` is the third input. It holds values read from storage while
rendering: the render instant captured immediately after authorization, the last poller run, the consolidation functions
each RRD file holds, which files exist, substituted host, query and input
values, Nth percentile and summation values, data source steps, interface
speeds, and the source of the gradient variable names. An application service
collects them through ports before the command is built.

The settings user and the access user can differ. `read_user_setting()` reads
the session user (`lib/functions.php:317-323`), not the `$user` argument of
`rrdtool_function_graph()`, so a report rendered by the poller for a report
owner (`lib/reports.php:417`, `449`) can use the configured guest settings
when there is no session and `auth_method == 0`; otherwise it follows the
existing defaults and fallback resolution (`lib/functions.php:324-357`). The
access check still uses the report owner (*inferred*). `RenderContext` keeps that split: the
legacy factory reads the session, and `GraphRequest` carries no user.
A separate `GraphAuthorizationSubject` carries the access identity explicitly
to `RenderGraph`, so report and remote callers do not inherit session access.

### Inputs read today

Every global, session value, cookie, environment variable, request value and
setting that `rrdtool_function_graph()` and its callees read on the image path,
and where each goes. Database rows that describe the graph go to
`GraphDefinition` and are listed under [Command builder](#command-builder).

| # | Input | Read at | Goes to |
| --- | --- | --- | --- |
| 1 | Graph permission caches in `$_SESSION` | `lib/rrd.php:2252-2255` through `is_graph_allowed()` (`lib/auth.php:615`) | `GraphAccess` with the explicit authorization subject, before cache access |
| 2 | `$_SESSION['sess_realtime_hash']` | `lib/rrd.php:2494-2499` | Captured `GraphRequest` real-time identity, used by mode-aware `DataSourcePaths` |
| 3 | `$_SESSION['sess_current_timespan']` | `lib/boost.php:468-469`, `598-599` | `GraphRequest` (window preset) |
| 4 | `$_SESSION['selected_theme']`, `$_SESSION['sess_user_id']`, global `$themes` installed-name allowlist and availability of each `include/themes/<name>/main.css` | `get_selected_theme()` (`lib/functions.php:793-856`) validates session/site/user choices and selects an available non-classic/classic fallback; called at `lib/rrd.php:3410`, `4799` and `lib/boost.php:487`, `489`, `613`, `615` | Legacy factory captures the resolved viewer theme and fallback identity into `RenderContext.theme` and its fingerprint; preserve pre-upgrade and no-available-theme fallback rules |
| 5 | `$_SESSION['sess_user_id']` as the settings user | `read_user_setting()` (`lib/functions.php:322-323`) | Legacy factory only |
| 6 | `$_SESSION['sess_config_array']['thold_draw_vrules']` | `lib/boost.php:1271` | `GraphRequest` (not cacheable) |
| 7 | `$_SESSION['custom']` | `lib/boost.php:1287-1290` | `GraphRequest` (not cacheable) |
| 8 | Session close before a one-off process | `lib/rrd.php:1204-1205` (`LegacyRrdWebContext.php:17-20`) | R7 local one-off transport boundary after context capture; a side effect, not an input |
| 9 | `$_COOKIE['CactiColorMode']` | `lib/rrd.php:3419-3420` | `RenderContext.colourMode` |
| 10 | `$_COOKIE['CactiTimeZone']` | `include/global.php:610-613`; again before each one-off process, `lib/rrd.php:1217` (`LegacyRrdWebContext.php:30-31`) | `RenderContext.timeZone` |
| 11 | Session copies written by `cacti_time_zone_set()` | `lib/functions.php:8388-8397` | Legacy factory; a side effect |
| 12 | Request `action` | `boost_determine_caching_state()` (`lib/boost.php:1268`, `1275`) | `GraphRequest` (not cacheable for `properties`, `zoom`, `edit`, `graph_edit`) |
| 13 | `LANG` environment | `lib/rrd.php:2260-2261`; `rrdtool_set_language()` (`lib/rrd.php:97-108`), called at `lib/rrd.php:1199-1201` | `RenderContext.locale` |
| 14 | `TZ` environment and `date.timezone` | Set by `cacti_time_zone_set()` (`lib/functions.php:8388`); read by `date()` at `lib/rrd.php:2460`, `3387`, `3389` and by `mktime()`/`date()` in `add_business_hours()` (`lib/rrd.php:5111-5152`) | `RenderContext.timeZone` |
| 15 | `RRD_DEFAULT_FONT` environment | Set at `lib/rrd.php:166-167` (persistent pipe) and sent at `lib/rrd.php:381-385` (proxy) | `RenderContext.fonts` |
| 16 | `$cacti_locale`, `$cacti_country` | `number_format_i18n()` (`include/global_languages.php:848-855`), called at `lib/graph_variables.php:690`, `692` | `RenderContext.locale` |
| 17 | `CACTI_LOCALE` | `include/global_languages.php:193`; read at `lib/rrd.php:2261`, `102` | `RenderContext.locale` |
| 18 | `$datechar` | `lib/rrd.php:3352`, `3358` | `RenderContext.dateFormat` |
| 19 | `$consolidation_functions`, `$graph_item_types` | `lib/rrd.php:2232`, `2239` | Domain enums already planned in the target layout |
| 20 | `$image_types` | `GraphOptionsGenerator.php:25`, `136` | Domain enum; chosen by `GraphRequest` |
| 21 | `$config` paths, `cacti_server_os`, `rra_path` | `lib/rrd.php:2232-2239`, `3189`; `rrdtool_proxy_relative_paths()` (`lib/rrd.php:671`) | Infrastructure configuration |
| 22 | `storage_location`, `$config['force_storage_location_local']` | `rrdtool_uses_proxy()` (`lib/rrd.php:680-685`) | Transport wiring |
| 23 | `poller_lastrun_1` | `lib/rrd.php:2275` | `RenderFacts` |
| 24 | `poller_interval` | `lib/rrd.php:2277`, `2215`; `lib/boost.php:507` | `RenderFacts`; cache lifetime |
| 25 | `realtime_cache_path` | `lib/rrd.php:2452` | Captured path configuration supplied to mode-aware `DataSourcePaths` |
| 26 | `graph_dateformat` | `lib/rrd.php:2453` | `RenderContext.dateFormat` |
| 27 | `date` (last poller date) | `lib/rrd.php:2454` | `RenderFacts` |
| 28 | `max_title_length` | `lib/rrd.php:2968` | `RenderContext.siteGraph` |
| 29 | `enable_rrdtool_gradient_support` | `lib/rrd.php:3058` | `RenderContext.siteGraph` |
| 30 | `business_hours_enable`, `_start`, `_end`, `_max_days`, `_color`, `_hideWeekends` | `lib/rrd.php:5098-5153` | `RenderContext.siteGraph` |
| 31 | `rrdtool_watermark`, `graph_watermark` | `GraphOptionsGenerator.php:142`, `261` | `RenderContext.siteGraph` |
| 32 | `rrdtool_version` | `get_rrdtool_version()` (`lib/functions.php:7345-7350`), called at `GraphOptionsGenerator.php:33` and `lib/rrd.php:3416` | `RenderContext.siteGraph` |
| 33 | `selected_theme` | `cacti_validate_theme()` (`lib/functions.php:8767-8773`) and `get_selected_theme()` | `RenderContext.theme` |
| 34 | `default_date_format`, `default_datechar` (user, then site) | `lib/rrd.php:3356-3357` | `RenderContext.dateFormat` |
| 35 | `font_method`, `custom_fonts`, `<element>_font`, `<element>_size` | `lib/rrd.php:3472-3478` | `RenderContext.fonts` |
| 36 | `path_rrdtool_default_font` | `lib/rrd.php:166`, `381` | `RenderContext.fonts` |
| 37 | `path_rrdtool` | `lib/rrd.php:1179`, `1207`, `3185` | Transport configuration |
| 38 | `boost_png_cache_enable`, `boost_png_cache_directory` | `lib/boost.php:378`, `481`, `607-608` | Cache adapter configuration |
| 39 | `boost_rrd_update_enable`, `boost_rrd_update_system_enable`, `boost_rrd_update_max_records_per_select`, `boost_rrd_update_string_length` | `boost_check_correct_enabled()` (`lib/boost.php:170-173`) and `boost_process_poller_output()` (`795`, `916`, `981`) | Pending-samples adapter configuration; R0/R7 pin record-selection and update-flush boundaries, preserving retained samples and failure outcomes |
| 40 | `storage_location`, `$config['poller_id']`, `$config['connection']` | `boost_poller_id_check()` (`lib/boost.php:302-307`) | Cache adapter configuration |
| 41 | `$graph_data_array` window, size and thumbnail keys | `lib/rrd.php:2270-2295`, `2425-2436`; `GraphOptionsGenerator.php:98-116`, `158-177` | `GraphRequest` |
| 42 | `$graph_data_array` mode keys: `print_source`, `get_error`, `export`, `export_filename`, `output_filename`, `export_csv`, `export_realtime`, `graphv`, `output_flag`, `image_format` | `lib/rrd.php:3172-3236`; `GraphOptionsGenerator.php:119-131` | `GraphRequest` |
| 43 | `$graph_data_array['graph_theme']`, `disable_cache` | `lib/rrd.php:3407-3408`; `lib/boost.php:376` | `GraphRequest` |
| 44 | `rand()` for gradient variable names | `lib/rrd.php:4960-4961` | `RenderFacts` (name source); the fixture seeds it at `tests/Fixtures/rrd-characterization.php:209` |
| 45 | `auth_method` and configured `guest_user` when there is no session settings user | `lib/functions.php:324-357` | Legacy context factory resolves the guest/settings user; resolved fonts and dates enter `RenderContext` and its fingerprint |
| 46 | Site and user `client_timezone_support` | `cacti_browser_zone_enabled()` (`lib/functions.php:8365-8380`), called before applying the cookie by `cacti_time_zone_set()` (`8389-8391`) | Legacy context factory applies the browser zone only when both settings are nonempty; otherwise it retains the existing PHP zone and `TZ` |
| 47 | `$_SESSION['sess_user_config_array']` | `read_user_setting()` (`lib/functions.php:362-385`) uses cached values before querying settings and writes newly resolved values back | Legacy context factory preserves cached-setting precedence and captures effective viewer settings into `RenderContext` |
| 48 | `default_interface_speed` | `rrdtool_function_interface_speed()` (`lib/rrd.php:1537-1570`), called for CDEF `query_ifSpeed`/`query_ifHighSpeed` substitutions (`2813-2824`) | `RenderContext.siteGraph`; fact collection preserves ifHighSpeed, then ifSpeed, then configured Mbps fallback, then the existing empty-setting fallback |
| 49 | `extended_paths`, `extended_paths_type`, `extended_paths_hashes` | `generate_data_source_path()` (`lib/functions.php:3234-3310`) when a stored data-source path is empty | `DataSourcePaths` legacy adapter preserves path generation and persistence; resolved paths enter `GraphDefinition` |
| 50 | Site-setting caches: `$_SESSION['sess_config_array']` in web mode and `$config['config_options_array']` in CLI mode | `read_config_option()` (`lib/functions.php:704-708`, `775-778`) returns cached values unless a forced read is requested | Legacy factory preserves cached-setting precedence and captures effective site settings/configuration; database-backed adapters must not substitute stored values for those captured values |

The factory captures the effective zone after this gate, rather than applying
`CactiTimeZone` unconditionally. R0 pins the same cookie with both settings
enabled, the site setting disabled, and the user setting disabled.

The frozen Boost path also changes zone after this capture:
`boost_process_poller_output()` calls `cacti_system_zone_set()` before its
initialization and update checks (`lib/boost.php:766`). Cache-check and later
percentile/summation calls can therefore make the PHP date legend,
business-hours shading and child `TZ` use the system zone despite an enabled
browser zone. Keeping the captured viewer zone explicitly is an intentional
correction, not byte-for-byte compatibility with that defect. Prerequisite P0
preserves and restores the caller's effective PHP zone and `TZ` around every
render-triggered pending-sample boundary, including failed updates and metadata
fetches; it does not change the Boost poller's standalone system-zone policy.
P0 changes the existing procedural render-triggered calls in `lib/boost.php`
(`boost_graph_cache_check()` and `boost_fetch_cache_check()`) and the metadata update
boundaries in `lib/rrd.php`; it does not depend on R7 adapters. Native fixtures
in `tests/Fixtures/rrd-characterization.php` and
`tests/Unit/Core/Rrd/RrdGraphCharacterizationTest.php` exercise these current
entry points before any extraction. R7 later carries the verified restoration
behavior into its pending-sample adapters.
P0 records historical output before its fix, then explicitly updates only the
affected zone goldens after separate review; R0 adopts those corrected goldens. Gates cover Boost
disabled, enabled with no samples, enabled with applied samples, refused updates,
cache hits/misses and later percentile/summation updates, with a browser zone
different from the system zone and both browser-setting disable cases. Assert
legend, business hours, RRDtool child `TZ`, cache-key ordering and restoration
after failures; migration slices preserve those corrected goldens.


R0 also characterizes cached user settings that differ from stored values,
uncached settings, cached web and CLI site settings that differ from storage, forced-read cache bypass, interface-speed substitutions with each SNMP value present,
configured and empty default speeds, and empty data-source paths with flat and
extended naming. These are future characterization gates.

### Data-source path resolution

`GraphDefinitions` returns immutable values after mode-aware path resolution.
R3 introduces an Application `DataSourcePaths` port and `LegacyDataSourcePaths`
adapter receiving the captured `GraphRequest` and path configuration. For
historical modes it retains `get_data_source_path()` and its existing `db_*`
persistence for missing paths. When `export_realtime` is present, today's
`lib/rrd.php:2493-2504` instead constructs every data-source path as
`realtime_cache_path/user_<sess_realtime_hash>_<local_data_id>.rrd`; it never
calls the historical helper or persists a historical path. The real-time hash
comes from captured session context, not selectable request input. Preserve
presence semantics (including empty/zero values); a missing/null hash keeps
existing `false` behavior with no historical fallback. Resolved real-time
paths belong to that render's mode/hash/path configuration and must never be
reused through a graph-ID-only definition cache across viewers.
R4's read-only DBAL reader obtains definition metadata, then asks this same
mode-aware port for paths on a render miss. It does not generate an unpersisted
replacement, write through the read connection, or add write grants to the
read-user list. The legacy adapter retains the existing authenticated bootstrap,
connection routing and write authority; Symfony wiring must provide that bridge
before enabling the DBAL reader. A cache hit still skips definition/path work.
R3/R4 require equality for existing and empty paths, the same persisted generated
path and subsequent reuse, naming settings, and failure behavior from the legacy
helper. Add real-time gates comparing the exact `DEF` paths for multiple data
sources and two viewer hashes, missing/empty/zero hashes, zero historical-helper
calls and zero generated-path writes; pin the configured real-time root and
file-existence/failure outcomes. Collector path generation remains on its
existing `db_*` path.

Two findings from this inventory shape the slices:

- On main the cache write names its file from the global `$graph_data_array`,
  not from the array the render used (`lib/boost.php:579`), and reads
  `boost_png_cache_enable` as truthy where the check reads `== 'on'`
  (`lib/boost.php:607` against `378`). PR #705 fixes the first; the second
  ends when one cache adapter owns both.
- A one-off local render sets no `RRD_DEFAULT_FONT`: only the persistent pipe
  and the proxy set it (`lib/rrd.php:166-167`, `381-385`), and the web pages
  pass no pipe (`graph_image.php:128`, `graph_json.php:142`). The Default Font
  then reaches a web image only through the web server's own environment
  (*inferred*). The transport applies `RenderContext.fonts` the same way for
  every process.

### Cache key

PR #705 keys the image by `boost_graph_cache_render_key()`: theme, colour
mode, fonts, date format, date separator, PHP zone, `TZ`, `LANG`, locale,
country, window, `graphv` and image format (`lib/boost.php:389-454` on
`origin/fix/boost-cache-font-key-main` at `8343421e4`). PR #710 replaces the
font parts with the profile fingerprint (`bd837932c`). Every part comes from
rows 4, 9, 10, 13, 14, 16, 18, 33, 34, 35, 36, 41, 42 and 43 above, so
`RenderContext::fingerprint()` plus the cache-relevant part of `GraphRequest`
covers every input #705 keys by. The file name keeps #705's readable prefix
(`<theme>_lgi_<id>_rrai_<rra>[_tsi_<preset>][_height_<h>][_width_<w>]_rk_<hash>`)
so the Boost purge in `poller_boost.php` keeps matching it.

Site settings in `siteGraph` are not in #705's key. Today a changed watermark
shows after at most one poller interval, when the cached file expires
(`lib/boost.php:506-513`). Hashing them changes that to the next request. The
first slice that hashes `RenderContext` renames every cache file once; the old
files age out through the purge.

The graph tables carry no revision or modification time (`graph_local` at
`cacti.sql:1585-1598`; `graph_templates_graph` at `cacti.sql:1680`). A
definition revision would have to be a hash of the loaded `GraphDefinition`,
which costs the definition queries on every cache hit. Today a hit runs only
the Boost data source query (`lib/boost.php:439-445`) when on-demand updates
are on. The revision is added only if the reader slice measures that cost
within the performance gate below; otherwise an edit keeps showing within one
poller interval, as it does now. Substituted host and query values and Nth
percentile values are outside any definition hash and stay bounded by the same
lifetime.

## Command builder

`GraphCommandBuilder::build(GraphDefinition, GraphRequest, RenderContext,
RenderFacts): GraphCommandSections` is a pure function in the Domain layer. It reads no
global, setting, file or database, so `ArchitectureTest.php:29-36` holds for
it.

R6 introduces `Domain/Command/GraphCommandSections`, with separate ordered
argument lists for options, definitions and items/export columns. Those section
boundaries survive until the compatibility hook completes. `RrdCommand` remains
the flat invocation value used by transports; it cannot recover the boundaries
after flattening. In particular, `COMMENT` can occur in both options and items,
so the adapter never infers sections from argument prefixes.

`GraphDefinition` is immutable: the graph row (`lib/rrd.php:2373-2389`), the
ordered items with colour, GPRINT format, data source name, path, minimum and
maximum (`lib/rrd.php:2397-2417`), the CDEF and VDEF text (`get_cdef()` at
`lib/cdef.php:29`, `get_vdef()` at `lib/vdef.php:27`), each data source's step
(`lib/rrd.php:2212`) and data source profile archives (`lib/rrd.php:2348-2356`,
`3577-3587`), the right-axis GPRINT format (`GraphOptionsGenerator.php:207`) and
the associated archives (`get_associated_rras()`, `lib/functions.php:4297`).

Aggregate graphs need no special case. `push_out_aggregates()` and
`aggregate_graphs_insert_graph_items()` write ordinary `graph_templates_item`
rows (`lib/api_aggregate.php:438`, `1214`, `1411`, `1487`, `1492`), and the
render reads those rows like any other graph (`lib/rrd.php:2397-2417`).

### Hook boundary

The `rrd_graph_graph_options` hook receives a six-field payload: `graph_opts`,
`graph_defs`, `txt_graph_items`, `graph_id`, `start` and `end`
(`lib/rrd.php:3173-3181`). The three command fields are strings that plugins
parse; Thold also consumes graph ID and the resolved start/end window for
threshold selection and VRULE ranges (`plugin_thold/setup.php:556-568` at
`73bafae`). The port must preserve all six field names, values and types.
Thold splits `graph_defs` on
`\` and a newline, reads the quoted RRD path out of each `DEF` and rejoins its
legend items the same way (`plugin_thold/setup.php:402-433`, `553` at
`73bafae`). `RRD_NL` is that separator (`lib/rrd.php:9`). The hook runs on
remote collectors only for plugins with the view capabilities
(`lib/plugins.php:254`).

So through the 1.3 series an adapter serializes each `GraphCommandSections`
section separately into those three
strings exactly as today, attaches the actual graph ID and resolved start/end
window, forwards the complete six-field payload for non-CSV modes, runs the
hook, and adds business hours after it as
today (`lib/rrd.php:3175`). The legacy adapter then assembles the final command
with today's mode and output-path rules into a
`Domain/Command/LegacySerializedGraphCommand` value. `RrdTransport` accepts
`RrdCommand|LegacySerializedGraphCommand`: argument lists use the normal
encoder, while the explicitly tagged legacy value retains the serialized bytes
and today's local/proxy framing and rejection checks. It is never treated as a
single argument or tokenized and re-quoted. Only the legacy hook adapter creates
this compatibility value; URL fields still pass through the existing builder
and encoders, and no transport gains a shell execution path.

The transport call takes that command plus a Domain `RrdExecutionContext`
derived from `RenderContext`, rather than consulting globals. The legacy
factory captures the effective browser-gated timezone and the inherited
process environment, including default-font fallback. The execution context
carries effective `TZ`, `LANG` and `RRD_DEFAULT_FONT`, preserving existing
locale normalization and the forced English locale for `info`/`fetch`
(`lib/rrd.php:1197-1218`). Local one-off and owned pipe processes receive an
explicit environment overlay on their inherited baseline; parent process
state is not used to pass a different viewer's values. A reused owned pipe
must have the same execution-context fingerprint, or be replaced before use.

R7's local one-off transport method explicitly owns PHP session release through
an injected legacy web-context adapter after all session-dependent context is
captured. It retains `LegacyRrdWebContext::releaseSession()` before the binary
eligibility check and before each one-off child starts, including metadata
`info`/`fetch` calls. Callers such as graph previews and reports therefore do not
need to pre-close the session themselves. Cache hits, access denial, existing
persistent-pipe commands and proxy commands do not introduce an extra release.
The adapter still closes the session on the local missing-binary path, as today.
R7 includes changes to `LocalRrdtool` and `LegacyRrdWebContext` and requires
session-lock release before one-off process work, unchanged zero-release paths,
and a concurrent same-session request case. Environment values are already
captured; transport performs no new session reads.

The proxy adapter retains the existing validated `setenv RRD_DEFAULT_FONT`
startup operation. A local environment change is not evidence that a remote
server received `TZ` or `LANG`. Remote graph rendering remains blocked by the
existing interoperability limitation; cutover also requires verified remote
environment capabilities and per-viewer isolation. Unsupported remote render
context must fail rather than silently use another locale or timezone. Existing
non-render proxy `info`/`fetch` framing and supported font behavior remain pinned.
R7 requires a real child to observe the requested environment, two sequential
viewer contexts without leakage, command-specific locale selection, and the
existing proxy environment/interoperability cases. These are future gates.

R0 pins the pre-hook strings and post-hook transport bytes for unchanged and
mutated hooks, including spaces, quoted titles, escaped colons, DEF paths,
CDEF/VDEF expressions and `RRD_NL`; R7 must keep that round trip passing.
A separate contract test runs a parser written to Thold's rules over the hook
strings. The tagged legacy representation remains through 1.3 rather than
assuming arbitrary plugin output has a lossless argument-list parser.
CSV export bypasses this hook and business-hours boundary entirely, retaining
the direct `xport` command path and zero calls to either operation.

### Moves, in dependency order

| Order | Code today | Reads | Destination | Slice |
| --- | --- | --- | --- | --- |
| 1 | `rrdtool_escape_string()` (`lib/rrd.php:3317-3324`) | nothing | `Domain/Command/LegendText` | R2 |
| 2 | `generate_graph_def_name()` (`lib/functions.php:3432`) | nothing | `Domain/Command/DefNames` | R2 |
| 3 | `rrdtool_cdef_magic_variables()`, `rrdtool_cdef_magic_append()` (`lib/rrd.php:2163-2203`) | time | `Domain/Command/CdefMagic` | R2 |
| 4 | `colourBrightness()`, `gradient()` (`lib/rrd.php:5024`, `4942-5004`) | `rand()` | `Domain/Command/GradientArea` with a name source | R2 |
| 5 | `rrdtool_function_format_graph_date()` (`lib/rrd.php:3349-3394`) | rows 14, 18, 34 | `Domain/Command/DateLegend` | R2 |
| 6 | `rrdtool_function_theme_font_options()`, `rrdtool_function_set_font()`, `rrdtool_set_font()` (`lib/rrd.php:3396-3505`) | rows 4, 9, 32, 33, 35 and `rrdtheme.php` | `Domain/Command/ThemeArguments` over `GraphFontProfile` | R2, after #710 |
| 7 | `add_business_hours()` (`lib/rrd.php:5096-5160`) | rows 14, 30 | `Domain/Command/BusinessHours` | R2 |
| 8 | `GraphOptionsGenerator::build()` (`src/Graphing/Infrastructure/Rrd/GraphOptionsGenerator.php:23-268`) | rows 20, 31, 32; the right-axis GPRINT query | `Domain/Command/GraphOptions` | R5 |
| 9 | Window defaults (`lib/rrd.php:2270-2295`, `2425-2436`) | rows 23, 24, 41 | `Domain/Render/GraphWindow` | R5 |
| 10 | `rrdtool_function_get_resstep()` (`lib/rrd.php:3559-3604`) and archive choice (`lib/rrd.php:2311-2371`) | profile rows | `Domain/Command/ArchiveChoice` | R5 |
| 11 | `rrdtool_cdef_step_replace()` (`lib/rrd.php:2209-2216`) | step row, row 24 | `CdefMagic` | R5 |
| 12 | `rrd_substitute_host_query_data()`, `rrdtool_pipe_quote_substituted()`, `rrd_substituted_text_placeholder()` (`lib/rrd.php:3297-3315`, `3507-3547`) | host, query and input rows through `lib/variables.php:259`, `368`, `409` | `RenderFacts` substitutions, resolved by a port | R6 |
| 13 | Consolidation and `DEF`s (`lib/rrd.php:2486-2532`), `GraphItemConsolidationResolver` | `get_rrd_cfs()` runs `rrdtool info` (`lib/functions.php:3362-3375`); file existence (`lib/rrd.php:3616-3627`) | `Domain/Command/DataSourceDefs` | R6 |
| 14 | Legend variables (`lib/rrd.php:2556-2622`), `variable_nth_percentile()` and `variable_bandwidth_summation()` (`lib/graph_variables.php:370`, `573`) | `rrdtool fetch` and Boost samples; row 16 | Values in `RenderFacts`; the scan for tokens stays pure | R6 |
| 15 | Legend padding (`lib/rrd.php:2640-2669`) | nothing | `Domain/Command/LegendText` | R6 |
| 16 | CDEF text (`lib/rrd.php:2732-2847`), interface speed (`lib/rrd.php:2813-2825`, `1537`), `sanitize_cdef()` (`lib/functions.php:4929`) | `data_local` row | `Domain/Command/CdefDefs` | R6 |
| 17 | VDEF text (`lib/rrd.php:2859-2885`) | nothing | `Domain/Command/VdefDefs` | R6 |
| 18 | Drawing items (`lib/rrd.php:2933-3146`), with the pinned HTML escaping | row 28 | `Domain/Command/ItemArguments` | R6 |
| 19 | `XPORT` columns and stacked flags (`lib/rrd.php:3147-3161`, `3239-3243`) | nothing | `Domain/Command/ExportColumns` | R6 |
| 20 | Hook, business hours and dispatch by mode (`lib/rrd.php:3172-3246`) | plugin code, transport | `Application/RenderGraph` and `Infrastructure/Legacy/LegacyGraphOptionsHook` | R7 |

The CF fallback chain at `lib/rrd.php:2701-2721` is overwritten by
`cf_reference` on the next line (`lib/rrd.php:2724`); a test pins that
(`tests/Unit/Core/Rrd/RrdGraphCfFallbackTest.php:15`). The move keeps the
behaviour and drops the dead branch in the same slice, which then says so.

## RenderGraph

`RenderGraph` retains the inner renderer's ordering below. R7 intentionally
moves creation of an owned proxy session after authorization: today's outer
`rrdtool_function_graph()` opens it at `lib/rrd.php:2123-2131`, before the inner
access check at `2252`. Thus zero transport work on denial is an intentional
security correction, not preserved current behavior. R7 requires a native
fail-before denied-proxy case, followed by zero initialization/commands on
denial and one owned session closed on success or failure for admitted calls.
Caller-supplied sessions retain their ownership and are never closed by the renderer.

1. Ask `GraphAccess` for the explicit `GraphAuthorizationSubject`, before
   cache reads, pending samples, definition queries or transport work
   (`lib/rrd.php:2252`). A denial keeps `GRAPH ACCESS DENIED` and performs
   none of those operations.
2. Capture the render instant from Graphing's `Application/Port/RenderClock` immediately after
   authorization (`lib/rrd.php:2258`), before cache or pending-sample work.
   Carry that same instant into `RenderFacts`, window/archive selection,
   date legends, magic CDEFs and business hours; miss-time fact collection
   never asks the clock again. A slow pending-sample regression advances the
   fake clock across a boundary and proves the render still uses the entry
   instant. Resolve the candidate cache key from the captured request/context before
   applying pending samples. This retains #705's name-before-timezone-change
   ordering (`ba2bbf521`); P0 removes the unintended render-zone change while
   retaining key-before-update ordering. Resolving a key does not read an image.
3. Invoke `PendingSamples` under the existing Boost/poller/mode conditions
   (`lib/boost.php:437-465`). Applied updates or a refused update prohibit the
   cache read for this render. With no updates, consult `RenderedGraphCache`
   only when the existing caching rules permit it.
4. On a cache miss or prohibited read, load the definition, collect
   `RenderFacts` and build the command. Fact collection retains any separate
   per-source updates required by percentile and summation calls.
5. For non-CSV modes, run the hook adapter and business hours, producing the
   tagged legacy command described above. CSV bypasses both operations and
   retains the direct `xport` command path.
6. Dispatch by the existing mode precedence. CSV goes directly to `xport`.
   Otherwise, when `print_source` is present, return the serialized source for
   the wrapper/entry point to write today's escaped, wrapped HTML, command
   length and Windows warning; do not send a final render command through
   `RrdTransport` and do not write a cache entry. Fact collection before this
   branch retains today's metadata reads. Other modes keep their existing
   error-text, export-file, real-time-file, `graph`/`graphv` and output-flag
   dispatch (`lib/rrd.php:3184-3246`). Map the transport result:
   `Domain/Render/UnrepresentableGraphArgument` to the error-image outcome (`lib/rrd.php:2138-2149`), a
   missing RRD file to the error image or error text (`lib/rrd.php:2506-2519`),
   a deleted graph to `false` (`lib/rrd.php:2392-2394`).
7. Store the rendered bytes when the existing cache rules allow it, including
   SVG and `graphv` output as well as PNG. Reads and writes retain #705's
   format-aware key and existing mode exclusions; the legacy `.png` cache
   filename suffix does not restrict the stored payload to PNG.

`RrdTransport` has two implementations, `LocalRrdtool` and `ProxyRrdtool`, as
the existing plan says. `RenderGraph` owns the proxy session for the whole
render, as `rrdtool_function_graph()` does now (Issue #502).

### R7 clock and failure contracts

R7 introduces `Graphing/Application/Port/RenderClock::now(): \DateTimeImmutable`
and `Graphing/Infrastructure/Legacy/LegacyRenderClock`. The infrastructure
adapter accepts a `Closure` supplied by the procedural wrapper that calls the
existing `rrdtool_clock_now($clock)`. That wiring preserves the wrapper's
optional Platform clock and default clock without importing
`Platform/Application/Port/Clock` into any Graphing `src` layer; cross-module
imports there require a Contract. `RenderGraph` imports only its own clock
port. Gates assert zero clock calls on denial, one immediately after admission
including a cache hit, and the same instant after delayed updates. The fake
clock implements the Graphing port; adapter tests verify the existing injected
clock/default callback is invoked once. Architecture checks cover both the
use case and adapter, without exemptions.

The current `Infrastructure/Rrd/UnrepresentableArgument` remains internal to
encoders and adapters. At every infrastructure command-encoding or transport
boundary, including failures before final dispatch, the adapter translates it
into Graphing's `Domain/Render/UnrepresentableGraphArgument`, carrying only a
safe reason, never the rejected argument/path. `RenderGraph` catches this own
Domain failure and returns the existing mode-specific `RenderResult`: CSV
failure, error/source text, or an error-image request. The infrastructure
output adapter owns GD and response writes. R7 gates exercise native encoder
rejection through both transport adapters and the legacy encoding boundary,
zero command submission/cache writes after rejection, and all three output
modes. Application must not import/catch the infrastructure exception, and the
Domain failure must not import Infrastructure. These contracts and gates are
proposed R7 work, not implementations supplied by this documentation PR.

### Authorization in R7

R7 also introduces the `Application/Port/GraphOptionsHook` interface, implemented
by `Infrastructure/Legacy/LegacyGraphOptionsHook`. The use case calls that port
for the section-preserving hook/business-hours operation; it never imports the
legacy adapter. The port accepts Domain command sections plus graph identity
and the resolved window; its adapter forwards all six legacy payload fields and returns the
tagged Domain command value. R0/R7 hook-contract gates compare the complete
payload, use a consumer reading `graph_id`/`start`/`end`, and preserve changed
command strings after the hook without dropping the original context. Symfony
wiring chooses the adapter. The R7 architecture gate
must reject any Application-to-Infrastructure import, including this boundary.

R7 returns a Domain `RenderResult` describing bytes, source output, real-time
output bytes with the existing destination, a CSV result, or an error-image request with the
message and captured render context. Application performs no filesystem, GD,
theme-file or HTTP writes. Legacy infrastructure maps a real-time result using
the existing dump/chmod/failure-return rules, and maps an error-image result
through `Infrastructure/Rrd/ErrorImage`, introduced in R7; R9/R10 reuse that output adapter.
Required R7 gates cover private real-time output, failed storage, and error
images while enforcing the architecture rule on Application. This is the same
typed-outcome boundary used for print-source HTML, rather than new implicit
infrastructure dependencies in `RenderGraph`. Every result that reaches
percentile/summation fact collection carries the resulting metadata updates,
including image, source, file export, real-time and CSV outcomes: today's
assignments at `lib/rrd.php:2596-2614` precede mode dispatch at `3172`. The
legacy wrapper applies these updates to the caller-owned by-reference
`$xport_meta`, preserving existing unrelated entries and overwriting matching
keys exactly as today. Denial, cache hits and failures before fact collection
leave caller metadata unchanged; failures after collection retain completed
updates. Public `rrdtool_function_xport()` and `rrdtool_function_graph()`
signatures remain intact. The CSV variant additionally carries the parsed
xport payload. Gates compare payload and metadata with the native
characterization (`RrdGraphCharacterizationTest.php:274-276`), exercise
`graph_xport.php`'s consumers, and add a non-CSV print-source case with both
percentile and summation values and pre-existing caller metadata. Include
early-return and post-collection failure cases to verify by-reference effects.
CSV still invokes neither the graph-options hook nor business-hours shading.

R7 introduces both `src/IdentityAccess/Contract/GraphAuthorizationSubject.php`
and `src/IdentityAccess/Contract/GraphAccess.php`, with an
`Infrastructure/Legacy/LegacyGraphAccess` adapter. `RenderGraph` receives the
subject explicitly and depends on `GraphAccess`; the contract takes the graph
id and that IdentityAccess contract value. Graphing imports only the published
IdentityAccess Contract layer, as required by `ArchitectureTest.php:54-55`;
IdentityAccess never depends on Graphing Domain types. The subject has two
explicit variants: a positive user identity and a trusted legacy bypass.
For the user variant, the legacy adapter calls `is_graph_allowed()` with that
positive id and preserves the per-user cache behavior from #661. For the
trusted bypass variant, it returns allowed without calling `is_graph_allowed()`.
Numeric zero or negative ids are never passed to that function as a bypass.

The existing wrapper maps its `$user` argument to that subject. Reports use
`$report['user_id']` (`lib/reports.php:417`, `449-529`), independently of the
session used for fonts and dates. Remote requests retain their positive,
enabled, unlocked `effective_user` validation and authorized-poller check
(`remote_agent.php:206-217`) before creating the subject. Web adapters use the
authenticated or existing guest identity. The old wrapper's `$user <= 0`
internal-call behavior maps to the trusted bypass variant only in that wrapper.
The legacy wrapper constructs this variant from its existing trusted call
argument; request-to-subject adapters accept only positive user identities and
never deserialize or infer a bypass from URL, cookie, or remote request input.

PR #661 is a prerequisite of R7 because its per-user permission caches are
required by the sequential-user isolation gate. It also precedes R9 and R10.
R7 is gated by denied and allowed report-owner and remote-effective-user
cases with a different session user, guest cases, sequential renders for two
users, and a denied cache-hit case. Trusted legacy callers with both `0` and
`-1` must remain allowed with no session and authentication enabled, making
zero `is_graph_allowed()` calls. Nonpositive ids from request adapters must be
rejected. CSV cases must make zero hook and business-hours calls. Print-source
cases must preserve source HTML and length output while making zero final
render transport and cache-write calls. Eligible PNG, SVG and `graphv` renders
must retain cache hits and writes with distinct format-aware keys.
Denial must perform zero cache, pending
sample, definition and transport calls. These are required characterization
and migration tests, not claims that new tests already exist. R10 adds routes
and a voter that reuse this same contract; it does not introduce authorization
for the first time.

### Why the cache does not wrap the transport

The brief proposed the Boost cache as a decorator around `RrdTransport`. The
code argues against it:

- On a hit today nothing runs after the check except the read
  (`lib/rrd.php:2265-2268`): no definition queries, no `rrdtool info` per data
  source (`lib/functions.php:3375`), no `rrdtool fetch` for percentiles. A
  transport decorator sees the command only after all of that has run.
- The transport sees a command, not the viewer, the preset timespan or the
  `thold_draw_vrules` and `custom` session flags that turn caching off
  (`lib/boost.php:1271-1295`).
- The cache check also applies pending Boost samples and decides from the
  result (`lib/boost.php:437-465`). That ordering is use-case logic.

So the cache is a port, `RenderedGraphCache`, that `RenderGraph` calls at
steps 2, 3 and 7, with one adapter that keeps #705's behaviour: name before the
on-demand update, read the opened file's size, write through a random `boost_png_tmp_` name opened exclusively in `xb`
mode, then `rename()` in the cache directory, skip empty output, purge by directory
permission (PR #705). Exclusive creation must retain its no-follow/create-exclusive
behavior and collision/failure cleanup; a pre-existing temporary file is never
opened for overwrite. Applying pending samples is a second
port, `PendingSamples`, whose adapter calls `boost_process_poller_output()` on
`lib/database.php`. R7 introduces both ports and their legacy adapters before
the wrapper delegates, and gates the ordering explicitly: key resolution, pending
updates, then an optional cache read; updates and refusals yield zero cache
reads, while unchanged samples retain the eligible cache hit. R8 refines those
existing adapters into one owner of cache naming, eligibility, reading and
writing, and checks failure paths and performance; it introduces no prerequisite
needed by R7. Application code calls ports rather than legacy globals in both slices.

## Entry points and access

`graph_image.php` and `graph_json.php` become thin adapters: validate the
request as now (`graph_image.php:20-31`, `graph_json.php:27-37`), build
`GraphRequest` and `RenderContext`, call `RenderGraph`, and write the same
headers and bodies. Their parameters are a public contract: Thold and
Weathermap build these URLs, and `include/layout.js:3933` and `4080` request
one `graph_json.php` per graph on graph pages.

PR #661 adds the check that a collector without local storage makes before it
forwards a graph to the main poller (`a2ded3f0f`, after `graph_image.php:128`)
and keys the permission caches by user (`27e6329c7`). The Symfony routes take
their decision from the IdentityAccess contract introduced in R7,
`GraphAccess`, whose legacy adapter calls `is_graph_allowed()` (`lib/auth.php:615`) with #661's
per-user caches. A Symfony voter in Graphing infrastructure calls that
contract, as Inventory's routes call `ConsoleAccess`
(`src/IdentityAccess/Contract/ConsoleAccess.php:10-14`).

A remote collector forwards `graph_theme` to `remote_agent.php`
(`graph_image.php:130-136`), but not the viewer's colour mode, fonts, zone or
date format, and `remote_agent.php:217` renders in its own request (*inferred*).
Serializing `RenderContext` over that call is a separate change.

## Template propagation and aggregates

Propagation is a write path. `push_out_graph()` copies template columns whose
`t_` override flag is empty to every graph of the template and refreshes the
title cache (`lib/template.php:398-437`); `push_out_graph_item()` and
`update_graph_template_items()` do the same for items (`lib/template.php:520-548`,
`1170`). They change the rows `GraphDefinition` is read from, so they do not
operate on `GraphDefinition`. They become Graphing application services over a
template write model (template, override mask, attached graphs), with ports for
the writes and the title cache.

The render reads `title_cache` (`lib/rrd.php:2375`), which propagation writes,
and substitutes host values into it again at render time
(`GraphOptionsGenerator.php:149-152`).

Characterization comes first: for a fixture template with graphs, record the
`graph_templates_graph` and `graph_templates_item` rows and title caches after
each propagation function and pin them. The same applies to
`push_out_aggregates()` (`lib/api_aggregate.php:845`) and
`aggregate_create_update()` (`lib/api_aggregate.php:1055`). Existing graph
template and item editors call `push_out_graph()` and `push_out_graph_item()`
(`graph_templates.php:188`, `graph_templates_items.php:263`). These callers
keep using the procedural functions until R11 provides the services.

## lib/rrd.php callers outside lib/

`lib/rrd.php` defines 97 functions. 34 have callers outside the file in core
code (tests excluded) or in a known plugin. Plugins checked: thold `73bafae`,
monitor `8022083`, intropage `ff51d48`, reportit `303faf2`, weathermap
`b013d7a`, gexport `11f2634`, mactrack `4cde758`, flowview `fa7a7ad`, syslog
`97e925a`, webseer `6934964`, servcheck `f9a2836`, maint `f57e856`, audit
`fdd6d1f`, cycle `7acbc34` (Cacti develop, 2026-10-01). Only thold, reportit,
weathermap and gexport call `lib/rrd.php`. The repository's `plugins/`
directory holds only `index.php`.

| Function | Defined | Core callers outside `lib/` | Plugin callers |
| --- | --- | --- | --- |
| `rrd_init` | `120` | `poller_boost.php:682`, `poller_realtime.php:137`, `rrdcleaner.php:202`, `utilities.php:205`, `poller_maintenance.php:599`, `cli/poller_output_empty.php:53` | reportit `poller_reportit.php:373`; gexport `includes/functions.php:1395` |
| `rrd_close` | `467` | `poller_boost.php` (8 calls), `poller_realtime.php:146`, `152`, `rrdcleaner.php:205`, `utilities.php:207`, `poller_maintenance.php:605`, `753`, `poller.php:906`, `cli/poller_output_empty.php:65`, `75`, `83` | gexport `includes/functions.php:1475` |
| `rrdtool_last_rejection` | `579` | `poller_boost.php:1015`, `1087`, `1326` | none |
| `rrdtool_execute` | `585` | `poller_realtime.php:205`, `rrdcleaner.php:203`, `204`, `utilities.php:206`, `poller_maintenance.php:604`, `681`, `692` | reportit `poller_reportit.php:551`, `657`; thold `includes/functions.php:6366`, `11253`, `11261`, `11394` |
| `rrdtool_command_path` | `708` | `poller_realtime.php:194` | none |
| `rrdtool_ownership_path` | `1019` | `poller_maintenance.php:773` | none |
| `rrdtool_function_interface_speed` | `1537` | none | thold `includes/functions.php:1696` |
| `rrdtool_function_create` | `1576` | `poller_realtime.php:187`, `data_sources.php:1223` | none |
| `rrdtool_rejection_is_permanent` | `1738` | `poller_boost.php:1348` | none |
| `rrdtool_function_update` | `1746` | `poller_realtime.php:323` | none |
| `rrdtool_function_fetch` | `2005` | `cli/float_rrdfiles.php:272` | weathermap `lib/datasources/WeatherMapDataSource_rrd.php:708`; thold `includes/functions.php:6408` |
| `rrdtool_function_graph` | `2120` | `graph_image.php:128`, `166`, `graph_json.php:142`, `201`, `graph.php:554`, `558`, `graphs.php:1681`, `1684`, `aggregate_graphs.php:746`, `747`, `graph_realtime.php:223`, `237`, `remote_agent.php:217` | gexport `includes/functions.php:1415`, `1428`, `1460`; thold `includes/functions.php:8287`, `8289` |
| `rrdtool_escape_string` | `3317` | none | thold `setup.php:810` |
| `rrdtool_function_xport` | `3337` | `graph_xport.php:74` | none |
| `rrdtool_function_info` | `3636` | `data_sources.php:1236` | none |
| `rrdtool_cacti_compare` | `3830` | `data_sources.php:1239` | none |
| `rrdtool_info2html` | `4058` | `data_sources.php:1241` | none |
| `rrdtool_tune` | `4202` | `data_sources.php:1246` | none |
| `rrdtool_create_error_image` | `4772` | `graph_image.php:175`, `177`, `graph_json.php:212`, `214`, `graph_realtime.php:249`, `251` | none |

Five more are called from `src/` by `GraphOptionsGenerator` only:
`rrdtool_pipe_quote`, `rrdtool_pipe_quote_substituted`,
`rrd_substituted_text_placeholder`, `rrdtool_function_format_graph_date` and
`rrdtool_function_theme_font_options`. Ten are called from other `lib/` files
only: `rrd_acknowledged_pipes`, `rrd_command_deadline`, `__rrd_close`,
`rrdtool_pipe_command`, `rrdtool_uses_proxy`, `rrdtool_create_ds`,
`rrdtool_create_rras`, `rrdtool_create_prepare`, `rrdtool_set_rrd_ownership`
and `rrdtool_function_contains_cf`. `lib/reports.php` calls
`rrdtool_function_graph` nine times (`lib/reports.php:449-529`).

Thold also registers the `rrd_graph_graph_options` hook
(`plugin_thold/setup.php:127`) and is the only checked plugin that does. Gexport
renders with its own pipe, an export file and user `-1`, which skips the access
check (`plugin_gexport/includes/functions.php:1390-1428`; `lib/rrd.php:2252`).
That call shape stays supported.

### Deprecation

Every function above keeps its name and signature through 1.3 and delegates
once its code has moved. On PHP 8.4, the floor on main (`composer.json:12`),
`#[\Deprecated]` raises `E_USER_DEPRECATED` on each call. Thold calls
`rrdtool_function_fetch()` and `rrdtool_execute()` on poller paths, and
`boost_error_handler()` maps `E_DEPRECATED` but not `E_USER_DEPRECATED`
(`lib/boost.php:125-162`, *read, not run*). So the attribute goes only on
functions with no known plugin caller once core no longer calls them. The seven
plugin-called functions get a `@deprecated` docblock and a changelog entry in
1.3 and the attribute in the next series.

## Slices

These continue the slice table in [graphing-rrd.md](graphing-rrd.md#slices).
They refine three of its pending rows: R3 and R4 are "Web-side graph reads
through DBAL", R2 takes the colour helpers from the "RRD file repair ..." row,
and R13 is "Callers moved to Graphing services; wrappers marked
`#[\Deprecated]`".

| Slice | Change | Files | Gate | Risk | Rollback |
| --- | --- | --- | --- | --- | --- |
| P0 | Separately reviewed prerequisite corrections: explicit SVG override command/response agreement and caller-zone restoration around render-triggered Boost updates; preserve standalone poller policy | `lib/boost.php` render-triggered cache/update calls, `lib/rrd.php` metadata update calls, `tests/Fixtures/rrd-characterization.php`, `tests/Unit/Core/Rrd/RrdGraphCharacterizationTest.php`, `src/Graphing/Infrastructure/Rrd/GraphOptionsGenerator.php`, `graph_image.php`, `graph_json.php`, `tests/Unit/Core/Rrd/GraphOptionsGeneratorCoverageTest.php`, new native image/JSON response fixtures (existing boundaries; no R7 adapter dependency) | Fail before each correction; explicit SVG command/MIME/JSON/cache parity with stored PNG/SVG and absent/PNG overrides preserved; Boost off/on with no/applied/refused samples, later metadata updates, setting gates, restoration and cache ordering | Medium: intentional correction of recorded defects | Revert prerequisite and affected goldens together |
| R0 | Characterization: goldens per `RenderContext` field (dark mode, browser zone with both timezone settings enabled and with either disabled, cached and uncached viewer/site settings, web/CLI site-cache precedence and forced-read bypass, interface-speed precedence/defaults, empty-path naming/persistence, each date format, a non-English locale, theme overrides and their resolved palette/border/font fallback, invalid/unavailable session/site/user selected-theme fallback using the installed allowlist and stylesheet availability, theme and viewer fonts, no-session guest fonts/dates with `auth_method == 0`) and per mode (thumbnail, SVG, `graphv`, export, CSV with zero hook/business-hours calls, real-time, print source, error text); an input census that records every setting, user setting, cookie and session key a render reads and compares it with the table above; the hook string contract; a render timing script | `tests/Unit/Core/Rrd/RrdGraphCharacterizationTest.php`, `tests/Fixtures/rrd-characterization.php`, new `tests/Fixtures/rrd-characterization/graph-context-*.json`, new `RenderInputCensusTest.php`, `GraphOptionsHookContractTest.php`, `tests/tools/graph_render_timing.php` | Historical defect cases are recorded; P0-corrected goldens pass against unchanged post-P0 code | Low; tests only | Revert |
| R1 | `RenderContext`, effective-format `GraphRequest`, `LegacyGraphRequestFactory` and `LegacyRenderContextFactory`; built once in `rrdtool_function_graph()`; the Boost key from `RenderContext::fingerprint()` | `src/Graphing/Domain/Render/*`, `src/Graphing/Infrastructure/Legacy/LegacyRenderContextFactory.php`, `LegacyGraphRequestFactory.php`, `src/Graphing/Infrastructure/Legacy/LegacyGraphThemeProfileResolver.php`, `src/Graphing/Domain/Render/GraphThemeProfile.php`, `lib/rrd.php`, `lib/boost.php` | P0-corrected R0 goldens unchanged; resolved theme-override profile, fallback/font/palette and warm-cache cost gates; `BoostGraphCacheKeyNativeTest` from #705; the census | Medium: a missed input serves one viewer's image to another | Revert; renamed cache files age out |
| R2 | Pure helpers to `Domain/Command` (moves 1 to 7); wrappers delegate | `src/Graphing/Domain/Command/*`, `lib/rrd.php`, `lib/functions.php` | `helpers.json`, `graph-gradient*.json`, `graph-business-hours.json`, `graph-cdef-magic.json`, `tests/Unit/Core/Rrd/RrdFontArgumentsTest.php` (#710), `ColourBrightnessTest` | Low | Revert |
| R3 | `GraphDefinition`, the `GraphDefinitions` port and `LegacyGraphDefinitions` on `db_*`, running today's queries; the render consumes it; introduce `DataSourcePaths` with its legacy persistence adapter | `src/Graphing/Domain/GraphDefinition*.php`, `src/Graphing/Application/Port/GraphDefinitions.php`, `src/Graphing/Infrastructure/Legacy/LegacyGraphDefinitions.php`, `Application/Port/DataSourcePaths.php`, `Infrastructure/Legacy/LegacyDataSourcePaths.php`, `lib/rrd.php` | All `graph-*.json`; a mode-aware reader test against the characterization database including historical existing/empty paths and persisted naming, real-time DEF paths per viewer/hash/root, missing-hash outcomes and zero historical-helper calls/writes; per-image timing | Medium | Revert |
| R4 | `DoctrineGraphDefinitions` on `doctrine.dbal.web_connection` for Symfony routes; read grants added to the read-user list; missing paths resolve through R3's existing legacy write boundary | `src/Graphing/Infrastructure/Persistence/DoctrineGraphDefinitions.php`, `config/services.yaml`, `docs/symfony-migration.md` | Both adapters return equal definitions and persisted paths on the behavior database; read connection performs no writes; cache hit performs no path work; the second-connection cost measured on `graph_image.php` | Medium: an extra connection per image if used from a legacy page | Remove the service; R3 remains |
| R5 | Window, archive choice and options from `GraphDefinition`, `GraphRequest` and `RenderContext` (moves 8 to 11) | `src/Graphing/Domain/Command/GraphOptions.php`, `ArchiveChoice.php`, `src/Graphing/Domain/Render/GraphWindow.php`, `GraphOptionsGenerator.php` (wrapper), `lib/rrd.php` | `graph-options*.json`, `graph-relative-window.json`, `GraphOptionsGeneratorCoverageTest` | Medium | Revert |
| R6 | `GraphCommandBuilder` returns section-preserving `GraphCommandSections`: `DEF`, `CDEF`, `VDEF`, legend, items and export columns; `RenderFacts` collected through ports (moves 12 to 19). Split into R6a (definitions) and R6b (legend, items, export) if the diff passes about 1,500 lines | `src/Graphing/Domain/Command/*`, `src/Graphing/Application/CollectRenderFacts.php`, ports and Legacy adapters, `lib/rrd.php` | All `graph-*.json`; `RrdGraphCfFallbackTest`, `RrdEmptyCdefGuardTest`, the VDEF export tests and the RRDtool round trip (`RrdGraphCharacterizationTest.php:334`, `377`, `449`, `471`) | High: the largest block; ordering of `DEF` names and caches | Revert R6 to restore procedural command construction; wrappers remain public entry points until R13, with no duplicate fallback implementation retained |
| R7 | `RenderGraph`, explicit `GraphAuthorizationSubject`, `GraphAccess` and its legacy adapter, `RrdTransport` (`LocalRrdtool`, `ProxyRrdtool`), `LegacyGraphOptionsHook`; introduce `RenderedGraphCache` and `PendingSamples` ports and legacy Boost adapters before `rrdtool_function_graph()` delegates | `src/Graphing/Application/RenderGraph.php`, `Port/RrdTransport.php`, `src/Graphing/Application/Port/RenderClock.php`, `src/Graphing/Infrastructure/Legacy/LegacyRenderClock.php`, `src/Graphing/Domain/Render/UnrepresentableGraphArgument.php`, `src/Graphing/Domain/Render/RrdExecutionContext.php`, `Port/RenderedGraphCache.php`, `Port/PendingSamples.php`, `Port/GraphOptionsHook.php`, `src/Graphing/Domain/Render/RenderResult.php`, `src/Graphing/Infrastructure/Legacy/LegacyRenderOutput.php`, `src/Graphing/Infrastructure/Rrd/ErrorImage.php`, `src/IdentityAccess/Contract/GraphAuthorizationSubject.php`, `src/IdentityAccess/Contract/GraphAccess.php`, its legacy adapter, `src/Graphing/Infrastructure/Rrd/ProxyRrdtool.php`, `LocalRrdtool.php`, `src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php`, `src/Graphing/Infrastructure/Legacy/LegacyGraphOptionsHook.php`, `BoostImageCache.php`, `LegacyPendingSamples.php`, `lib/rrd.php` | Explicit report/remote/guest subjects, trusted legacy 0/-1 bypass with no session and zero auth calls, request rejection of bypass, per-user isolation and zero work on denied cache hits; intentional authorize-before-owned-proxy-session correction with fail-before, ownership and cleanup gates; Graphing clock port and legacy callback adapter, zero/one clock-call gates; entry-time snapshot through delayed updates; adapter translation of native encoding rejection and mode-specific outcomes with zero submissions/cache writes; Boost zone gates from P0; key-before-update/read ordering and zero cache reads after updates/refusal; All-mode by-reference percentile/summation metadata parity (including non-CSV source and caller-owned entries), CSV payload parity, early-return/failure outcomes and zero hook/business-hours calls; print-source zero final render/cache-write calls; PNG/SVG/graphv cache contracts; post-hook legacy section/byte round trip; full six-field hook payload contract and graph/window consumer gates; session release before one-off work, zero-release paths and concurrent same-session requests; real-process TZ/LANG/default-font and sequential-viewer isolation; proxy environment capability gate; output/storage/error-image contracts; Application has no Infrastructure imports; `RrdProxyInteropTest`; `graph-proxy*.json`; the behavior harness graph scenarios | High: authorization identity, plugin hook and proxy session lifetime | Revert |
| R8 | Unify cache naming, eligibility, reading and writing in R7's existing Boost adapter, keeping #705; refine failure handling and performance without introducing new R7 dependencies | `src/Graphing/Infrastructure/Legacy/BoostImageCache.php`, `LegacyPendingSamples.php`, `lib/boost.php` | `BoostGraphCacheKeyNativeTest`, `BoostGraphCacheFailureTest`, `BoostPngPurgeTest`; retain R7 ordering gates; cache hit and miss timing | Medium | Revert; R7's working adapters remain |
| R9 | `graph_image.php` and `graph_json.php` as thin adapters | `graph_image.php`, `graph_json.php`, `src/Graphing/Infrastructure/Legacy/GraphRequestFromLegacyRequest.php` | `entry_points.baseline.tsv` unchanged; `RemoteGraphPermissionTest` (#661); page crawl | Medium | Revert |
| R10 | Symfony image and JSON routes and a voter reusing R7's `GraphAccess`, per-route cutover flag; legacy URLs kept | `src/Graphing/Infrastructure/Symfony/*`, R7 access contract wiring, `config/services.yaml`, `docs/architecture-alignment.md` | #661 permission tests through the route; guest account; route baseline entry | Medium | Turn the flag off; legacy pages remain |
| R11 | Template propagation services after their characterization | `src/Graphing/Application/*`, ports, `lib/template.php` (formatting-only PER-CS change first, in its own PR) | New propagation goldens; behavior harness graph creation | High: writes to many graphs | Revert |
| R12 | Aggregate services after their characterization | `src/Graphing/Application/*`, `lib/api_aggregate.php` (PER-CS first) | New aggregate row goldens | High | Revert |
| R13 | Callers moved; wrappers deprecated as described above | `lib/rrd.php`, callers in the table above, `CHANGELOG.md` | Full suite; plugin page crawl with thold and monitor | Low | Revert |

P0 precedes R0 and R1; its reviewed intentional golden changes are the only
exceptions to unchanged characterization. R1 to R10 are in dependency order. R11 and R12 depend only on R3 and can run
alongside R5 to R10.

## Performance gates

No graph-render timing tool exists in `tests/tools`, so
R0 adds one. Numbers are taken on the behavior container with local RRDtool,
after 20 warm-up requests, over 200 requests, reporting p50 and p95 wall time
and the database connections opened per request. The thresholds are proposals
for review.

| Measurement | Before and after | Proposed gate |
| --- | --- | --- |
| One image from `graph_image.php`: a 2-item graph, the 20-item characterization graph, an aggregate of 30 items; cache off | R3, R4, R6, R7 | p50 within 5 percent, p95 within 10 percent |
| The same images with a warm cache | R1, R8, and R4 if the revision is added | p50 within 5 percent; no definition query on a hit unless the revision is adopted |
| A graph preview page of 24 graphs: time until every `graph_json.php` response has arrived | R3, R4, R8, R9 | Within 10 percent; connections per image unchanged unless R4 is wired into legacy pages |
| A Boost batch on the poller path: update time per RRD, kernel boot and service lookup against direct construction | Before any poller or Boost caller uses the container | As the existing constraint in graphing-rrd.md; no container use if slower |

A legacy page gets a second connection if it uses the DBAL reader. Therefore
legacy entry points keep `LegacyGraphDefinitions` unless R4's measurement shows
the cost is within the gate; Symfony routes use DBAL.

## Out of scope

- The collector create and update path. It stays on `lib/database.php` for
  multi-session routing and reconnects, as the existing constraint says.
  Applying pending Boost samples during a render is part of that path and stays
  there too.
- Graph images through rrdproxy. They stay blocked by rrdproxy's command
  parsing until a fixed rrdproxy exists; the KNOWN LIMITATION test stays.
- The HTML-escaped legend, title and vertical label output. It stays pinned.
- Data source statistics and RRD checks. `lib/dsstats.php:378` and
  `lib/rrdcheck.php` build their own argument arrays and use only the
  transport.
- Forwarding the viewer's context through `remote_agent.php`.

## Coordination

- P0 SVG-override and timezone corrections and their native regression evidence land
  before R0/R1. These are proposed prerequisites, not fixes implemented by
  this documentation PR.
- PRs #705 and #710 land before R1. R1 builds its key from #705's parts and its
  fonts from #710's `GraphFontProfile`. Both are stacked on #681 and #679.
- PR #661 lands before R7, R9 and R10; R7's per-user isolation gate already
  depends on its permission-cache changes.
- PRs #657 and #658 add a top-level module `src/GraphDefinition` for CDEF and
  VDEF management, #659 adds `src/ColorTemplates` and #660
  `src/AggregateTemplate`, while #656 and #667 use `src/Graphing`.
  `docs/architecture.md:15` gives graph definitions to Graphing, and
  `ArchitectureTest.php:54-55` lets Graphing read another module only through
  its `Contract` layer, which none of the four adds. R3 reads CDEF and VDEF
  text. Before R3, either those PRs move into Graphing or they publish a read
  contract. This plan's class `Kadupul\Graphing\Domain\GraphDefinition` is
  unrelated to the `Kadupul\GraphDefinition` module; whichever lands second
  should rename to avoid confusion.
- Another session, titled "Rrd.php refactor to Symfony", may hold local work on
  `lib/rrd.php`. No branch with that purpose was found in the mirror on
  2026-10-01. Confirm before R1 and R2, which edit the same functions.
- Edited PHP files on main move to PER-CS 2.0. `lib/rrd.php` and
  `lib/boost.php` already use four-space indentation; `lib/template.php`,
  `lib/api_aggregate.php` and `lib/graph_variables.php` still use tabs, so each
  gets a formatting-only PR before its first behavioural slice.
- Future form migrations of graph template, graph and item pages must keep
  calling the existing procedural propagation functions until R11 and R12
  provide services. This plan depends on no unpublished form plan or slice IDs.

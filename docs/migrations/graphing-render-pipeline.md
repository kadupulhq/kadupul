# Graph rendering pipeline on main

This is the detailed plan for moving graph rendering out of `lib/rrd.php`. It
extends [Moving `lib/rrd.php` into Graphing](graphing-rrd.md) and keeps its
decisions: every public function keeps its name and signature for the 1.3
series, each slice keeps the characterization tests passing unchanged, and LTS
is unchanged.

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
the Boost SNMP counters (`lib/boost.php:522-524`, `586`).

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

The design brief put the output format in `RenderContext`. It belongs in
`GraphRequest`: it comes from the URL (`graph_image.php:42-72`,
`graph_json.php:104-138`), not from the viewer, and both values reach the cache
key either way.

`RenderFacts` is the third input. It holds values read from storage while
rendering: the current time, the last poller run, the consolidation functions
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
| 2 | `$_SESSION['sess_realtime_hash']` | `lib/rrd.php:2494-2499` | `GraphRequest` (real-time) |
| 3 | `$_SESSION['sess_current_timespan']` | `lib/boost.php:468-469`, `598-599` | `GraphRequest` (window preset) |
| 4 | `$_SESSION['selected_theme']`, `$_SESSION['sess_user_id']` | `get_selected_theme()` (`lib/functions.php:791-830`), called at `lib/rrd.php:3410`, `4799` and `lib/boost.php:487`, `489`, `613`, `615` | `RenderContext.theme` |
| 5 | `$_SESSION['sess_user_id']` as the settings user | `read_user_setting()` (`lib/functions.php:322-323`) | Legacy factory only |
| 6 | `$_SESSION['sess_config_array']['thold_draw_vrules']` | `lib/boost.php:1271` | `GraphRequest` (not cacheable) |
| 7 | `$_SESSION['custom']` | `lib/boost.php:1287-1290` | `GraphRequest` (not cacheable) |
| 8 | Session close before a one-off process | `lib/rrd.php:1204-1205` (`LegacyRrdWebContext.php:17-20`) | Entry point; a side effect, not an input |
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
| 25 | `realtime_cache_path` | `lib/rrd.php:2452` | Infrastructure configuration |
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
| 39 | `boost_rrd_update_enable`, `boost_rrd_update_system_enable` | `boost_check_correct_enabled()` (`lib/boost.php:170-173`) | Pending-samples adapter |
| 40 | `storage_location`, `$config['poller_id']`, `$config['connection']` | `boost_poller_id_check()` (`lib/boost.php:302-307`) | Cache adapter configuration |
| 41 | `$graph_data_array` window, size and thumbnail keys | `lib/rrd.php:2270-2295`, `2425-2436`; `GraphOptionsGenerator.php:98-116`, `158-177` | `GraphRequest` |
| 42 | `$graph_data_array` mode keys: `print_source`, `get_error`, `export`, `export_filename`, `output_filename`, `export_csv`, `export_realtime`, `graphv`, `output_flag`, `image_format` | `lib/rrd.php:3172-3236`; `GraphOptionsGenerator.php:119-131` | `GraphRequest` |
| 43 | `$graph_data_array['graph_theme']`, `disable_cache` | `lib/rrd.php:3407-3408`; `lib/boost.php:376` | `GraphRequest` |
| 44 | `rand()` for gradient variable names | `lib/rrd.php:4960-4961` | `RenderFacts` (name source); the fixture seeds it at `tests/Fixtures/rrd-characterization.php:209` |
| 45 | `auth_method` and configured `guest_user` when there is no session settings user | `lib/functions.php:324-357` | Legacy context factory resolves the guest/settings user; resolved fonts and dates enter `RenderContext` and its fingerprint |
| 46 | Site and user `client_timezone_support` | `cacti_browser_zone_enabled()` (`lib/functions.php:8365-8380`), called before applying the cookie by `cacti_time_zone_set()` (`8389-8391`) | Legacy context factory applies the browser zone only when both settings are nonempty; otherwise it retains the existing PHP zone and `TZ` |

The factory captures the effective zone after this gate, rather than applying
`CactiTimeZone` unconditionally. R0 pins the same cookie with both settings
enabled, the site setting disabled, and the user setting disabled.

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

The `rrd_graph_graph_options` hook receives and returns three strings
(`lib/rrd.php:3173-3181`). Plugins parse them. Thold splits `graph_defs` on
`\` and a newline, reads the quoted RRD path out of each `DEF` and rejoins its
legend items the same way (`plugin_thold/setup.php:402-433`, `553` at
`73bafae`). `RRD_NL` is that separator (`lib/rrd.php:9`). The hook runs on
remote collectors only for plugins with the view capabilities
(`lib/plugins.php:254`).

So through the 1.3 series an adapter serializes each `GraphCommandSections`
section separately into those three
strings exactly as today for non-CSV modes, runs the hook, and adds business hours after it as
today (`lib/rrd.php:3175`). The legacy adapter then assembles the final command
with today's mode and output-path rules into a
`Domain/Command/LegacySerializedGraphCommand` value. `RrdTransport` accepts
`RrdCommand|LegacySerializedGraphCommand`: argument lists use the normal
encoder, while the explicitly tagged legacy value retains the serialized bytes
and today's local/proxy framing and rejection checks. It is never treated as a
single argument or tokenized and re-quoted. Only the legacy hook adapter creates
this compatibility value; URL fields still pass through the existing builder
and encoders, and no transport gains a shell execution path.

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

`RenderGraph` runs the steps in today's order:

1. Ask `GraphAccess` for the explicit `GraphAuthorizationSubject`, before
   cache reads, pending samples, definition queries or transport work
   (`lib/rrd.php:2252`). A denial keeps `GRAPH ACCESS DENIED` and performs
   none of those operations.
2. Resolve the candidate cache key from the captured request/context before
   applying pending samples. This retains #705's name-before-timezone-change
   behavior (`ba2bbf521`); resolving a key does not read an image.
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
   `UnrepresentableArgument` to the error image (`lib/rrd.php:2138-2149`), a
   missing RRD file to the error image or error text (`lib/rrd.php:2506-2519`),
   a deleted graph to `false` (`lib/rrd.php:2392-2394`).
7. Store the rendered bytes when the existing cache rules allow it, including
   SVG and `graphv` output as well as PNG. Reads and writes retain #705's
   format-aware key and existing mode exclusions; the legacy `.png` cache
   filename suffix does not restrict the stored payload to PNG.

`RrdTransport` has two implementations, `LocalRrdtool` and `ProxyRrdtool`, as
the existing plan says. `RenderGraph` owns the proxy session for the whole
render, as `rrdtool_function_graph()` does now (Issue #502).

### Authorization in R7

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
on-demand update, read the opened file's size, write through `tempnam()` and
`rename()` in the cache directory, skip empty output, purge by directory
permission (`0c45a6ff5`, `ac5c67539`). Applying pending samples is a second
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
| R0 | Characterization: goldens per `RenderContext` field (dark mode, browser zone with both timezone settings enabled and with either disabled, each date format, a non-English locale, theme and viewer fonts, no-session guest fonts/dates with `auth_method == 0`) and per mode (thumbnail, SVG, `graphv`, export, CSV with zero hook/business-hours calls, real-time, print source, error text); an input census that records every setting, user setting, cookie and session key a render reads and compares it with the table above; the hook string contract; a render timing script | `tests/Unit/Core/Rrd/RrdGraphCharacterizationTest.php`, `tests/Fixtures/rrd-characterization.php`, new `tests/Fixtures/rrd-characterization/graph-context-*.json`, new `RenderInputCensusTest.php`, `GraphOptionsHookContractTest.php`, `tests/tools/graph_render_timing.php` | The new tests pass against unchanged code | Low; tests only | Revert |
| R1 | `RenderContext`, `GraphRequest` and `LegacyRenderContextFactory`; built once in `rrdtool_function_graph()`; the Boost key from `RenderContext::fingerprint()` | `src/Graphing/Domain/Render/*`, `src/Graphing/Infrastructure/Legacy/LegacyRenderContextFactory.php`, `lib/rrd.php`, `lib/boost.php` | R0 goldens unchanged; `BoostGraphCacheKeyNativeTest` from #705; the census | Medium: a missed input serves one viewer's image to another | Revert; renamed cache files age out |
| R2 | Pure helpers to `Domain/Command` (moves 1 to 7); wrappers delegate | `src/Graphing/Domain/Command/*`, `lib/rrd.php`, `lib/functions.php` | `helpers.json`, `graph-gradient*.json`, `graph-business-hours.json`, `graph-cdef-magic.json`, `tests/Unit/Core/Rrd/RrdFontArgumentsTest.php` (#710), `ColourBrightnessTest` | Low | Revert |
| R3 | `GraphDefinition`, the `GraphDefinitions` port and `LegacyGraphDefinitions` on `db_*`, running today's queries; the render consumes it | `src/Graphing/Domain/GraphDefinition*.php`, `src/Graphing/Application/Port/GraphDefinitions.php`, `src/Graphing/Infrastructure/Legacy/LegacyGraphDefinitions.php`, `lib/rrd.php` | All `graph-*.json`; a reader test against the characterization database; per-image timing | Medium | Revert |
| R4 | `DoctrineGraphDefinitions` on `doctrine.dbal.web_connection` for Symfony routes; read grants added to the read-user list | `src/Graphing/Infrastructure/Persistence/DoctrineGraphDefinitions.php`, `config/services.yaml`, `docs/symfony-migration.md` | Both adapters return equal definitions on the behavior database; the second-connection cost measured on `graph_image.php` | Medium: an extra connection per image if used from a legacy page | Remove the service; R3 remains |
| R5 | Window, archive choice and options from `GraphDefinition`, `GraphRequest` and `RenderContext` (moves 8 to 11) | `src/Graphing/Domain/Command/GraphOptions.php`, `ArchiveChoice.php`, `src/Graphing/Domain/Render/GraphWindow.php`, `GraphOptionsGenerator.php` (wrapper), `lib/rrd.php` | `graph-options*.json`, `graph-relative-window.json`, `GraphOptionsGeneratorCoverageTest` | Medium | Revert |
| R6 | `GraphCommandBuilder` returns section-preserving `GraphCommandSections`: `DEF`, `CDEF`, `VDEF`, legend, items and export columns; `RenderFacts` collected through ports (moves 12 to 19). Split into R6a (definitions) and R6b (legend, items, export) if the diff passes about 1,500 lines | `src/Graphing/Domain/Command/*`, `src/Graphing/Application/CollectRenderFacts.php`, ports and Legacy adapters, `lib/rrd.php` | All `graph-*.json`; `RrdGraphCfFallbackTest`, `RrdEmptyCdefGuardTest`, the VDEF export tests and the RRDtool round trip (`RrdGraphCharacterizationTest.php:334`, `377`, `449`, `471`) | High: the largest block; ordering of `DEF` names and caches | Revert; wrappers still hold the old code until R13 |
| R7 | `RenderGraph`, explicit `GraphAuthorizationSubject`, `GraphAccess` and its legacy adapter, `RrdTransport` (`LocalRrdtool`, `ProxyRrdtool`), `LegacyGraphOptionsHook`; introduce `RenderedGraphCache` and `PendingSamples` ports and legacy Boost adapters before `rrdtool_function_graph()` delegates | `src/Graphing/Application/RenderGraph.php`, `Port/RrdTransport.php`, `Port/RenderedGraphCache.php`, `Port/PendingSamples.php`, `src/IdentityAccess/Contract/GraphAuthorizationSubject.php`, `src/IdentityAccess/Contract/GraphAccess.php`, its legacy adapter, `src/Graphing/Infrastructure/Rrd/ProxyRrdtool.php`, `src/Graphing/Infrastructure/Legacy/LegacyGraphOptionsHook.php`, `BoostImageCache.php`, `LegacyPendingSamples.php`, `lib/rrd.php` | Explicit report/remote/guest subjects, trusted legacy 0/-1 bypass with no session and zero auth calls, request rejection of bypass, per-user isolation and zero work on denied cache hits; key-before-update/read ordering and zero cache reads after updates/refusal; CSV zero hook/business-hours calls; print-source zero final render/cache-write calls; PNG/SVG/graphv cache contracts; post-hook legacy section/byte round trip; hook contract; `RrdProxyInteropTest`; `graph-proxy*.json`; the behavior harness graph scenarios | High: authorization identity, plugin hook and proxy session lifetime | Revert |
| R8 | Unify cache naming, eligibility, reading and writing in R7's existing Boost adapter, keeping #705; refine failure handling and performance without introducing new R7 dependencies | `src/Graphing/Infrastructure/Legacy/BoostImageCache.php`, `LegacyPendingSamples.php`, `lib/boost.php` | `BoostGraphCacheKeyNativeTest`, `BoostGraphCacheFailureTest`, `BoostPngPurgeTest`; retain R7 ordering gates; cache hit and miss timing | Medium | Revert; R7's working adapters remain |
| R9 | `graph_image.php` and `graph_json.php` as thin adapters | `graph_image.php`, `graph_json.php`, `src/Graphing/Infrastructure/Legacy/GraphRequestFromLegacyRequest.php` | `entry_points.baseline.tsv` unchanged; `RemoteGraphPermissionTest` (#661); page crawl | Medium | Revert |
| R10 | Symfony image and JSON routes and a voter reusing R7's `GraphAccess`, per-route cutover flag; legacy URLs kept | `src/Graphing/Infrastructure/Symfony/*`, R7 access contract wiring, `config/services.yaml`, `docs/architecture-alignment.md` | #661 permission tests through the route; guest account; route baseline entry | Medium | Turn the flag off; legacy pages remain |
| R11 | Template propagation services after their characterization | `src/Graphing/Application/*`, ports, `lib/template.php` (formatting-only PER-CS change first, in its own PR) | New propagation goldens; behavior harness graph creation | High: writes to many graphs | Revert |
| R12 | Aggregate services after their characterization | `src/Graphing/Application/*`, `lib/api_aggregate.php` (PER-CS first) | New aggregate row goldens | High | Revert |
| R13 | Callers moved; wrappers deprecated as described above | `lib/rrd.php`, callers in the table above, `CHANGELOG.md` | Full suite; plugin page crawl with thold and monitor | Low | Revert |

R1 to R10 are in dependency order. R11 and R12 depend only on R3 and can run
alongside R5 to R10.

## Performance gates

No timing tool exists in `tests/tools` (it holds shell and PHP checks only), so
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

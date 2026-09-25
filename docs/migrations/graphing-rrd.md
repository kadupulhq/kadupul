# Moving `lib/rrd.php` into Graphing on main

`lib/rrd.php` holds RRDtool process control, the RRDtool proxy client, graph
command generation, file creation and tuning, RRD file repair and a few image
and colour helpers. [Architecture](../architecture.md) assigns RRD integration to
the Graphing module. This queue moves that code there one slice at a time. LTS
is unchanged.

The file is not rewritten in one change. Other files in the repository and
third-party plugins call its functions, so every public function keeps its name
and signature for the 1.3 series. Once its code has moved, each one delegates to
a Graphing service.

## Target layout

```text
src/Graphing/
  Domain/            GraphItemType, ConsolidationFunction, DataSourceType enums;
                     RrdCommand (an argument list, not a string); GraphDefinition
  Application/       RenderGraph, ExportGraph, CreateDataSourceFile, TuneDataSource
    Port/            RrdTransport, GraphDefinitions, DataSources
  Infrastructure/
    Rrd/             PipeEncoder, LocalRrdtool, ProxyRrdtool, RrdXmlEditor, ErrorImage
    Persistence/     DBAL readers for the web rendering path
    Legacy/          LegacyDataSources for the collector path; RrdBridge
```

PR #314 creates the module with the `DeviceTreePlacement` contract. The RRD
slices add to that module and do not change the contract.

`RrdTransport` is a port because it has two real implementations: the local
`rrdtool -` pipe and the RRDtool proxy. Other classes get no interface unless a
second implementation or a module boundary needs one.

## Slices

| Slice | Status |
| --- | --- |
| Characterization tests for generated commands and helpers | PR #406 |
| `RrdCommand` and a pipe-mode encoder replacing shell escaping on the pipe | PR #410 |
| Remaining `cacti_escapeshellarg()` calls on the pipe in `lib/rrd.php` moved to the encoder | PR #421 |
| RRD file paths and the remaining pipe commands in `lib/rrd.php`, `lib/boost.php`, `lib/rrdcheck.php`, `lib/rrd_maintenance.php`, `lib/dsstats.php`, `lib/functions.php` and `poller_maintenance.php` quoted with the encoder | PR #426 |
| One-shot calls through `symfony/process` argument arrays; long-lived pipe in `LocalRrdtool` | Pending |
| Proxy client restored on phpseclib 4 and hardened without a wire format change | PR #436 |
| Graph and export `DEF` paths sent to the proxy bare and relative to the RRA directory | This PR |
| Graph command generation split by option, definition, item type and legend | Pending |
| Web-side graph reads through DBAL; collector writes stay on `db_*` | Pending |
| RRD file repair, `rrdtool_info2html` to Twig, error image and colour helpers | Pending |
| Callers moved to Graphing services; wrappers marked `#[\Deprecated]` | Pending |

Duplicate code in `lib/rrd.php` is shared through procedural helpers ahead of
the split: `rrdtool_cdef_magic_variables()`, `rrdtool_cdef_magic_append()` and
`rrdtool_cdef_step_replace()` for the magic CDEF variables,
`rrdtool_info2html_table()` for the data source and RRA boxes, and
`rrd_xml_transform()` for the dump, edit and restore loop of
`rrd_datasource_add()`, `rrd_rra_delete()` and `rrd_rra_clone()`. A move of
those callers takes its helper along.

Each slice keeps the characterization tests passing unchanged. A slice that
has to change a pinned output says so and states why.

## Decisions

The proxy client could not connect on main: it constructed
`\phpseclib\phpseclib\phpseclib\Crypt\RSA`, which phpseclib 4 does not provide
(see [phpseclib](../../tools/dependencies/phpseclib.md)). `ProxyCipher` now
writes and reads the frame of rrdproxy 54aad57: three hex digits giving the
length of a base64 RSA-OAEP (SHA-256, MGF1-SHA-256) wrapped 32-byte key,
followed by the base64 AES-256-CBC payload under a zero IV, one frame per
`_EOT_` sequence or `_EOP_` packet. The session is unchanged: the client key,
the proxy key, `setenv RRD_DEFAULT_FONT`, `setcnn encryption off`, the
commands and `quit`. The client compares the stored fingerprint with
`hash_equals()` after trimming and lowercasing it, reads at most 16 KiB for
the proxy key and gives up after 10 seconds, keeps no global encryption state,
never sends or accepts a plaintext frame, and logs each failure. rrdproxy
answers `setcnn encryption off` with an error, and the client ignores the
answer. It sends a default font path only as a bare token, since rrdproxy
splits the value on blanks and keeps quotes in it.

RRDtool proxy splits each command on whitespace and resolves path operands
and `DEF` paths exactly as sent, so quoting breaks its path checks. Array
commands already go to the proxy as bare tokens, and an argument that is empty
or holds whitespace, a quote, a backslash, CR, LF or NUL is refused before
anything is sent. The create and update strings write their path the same way
for the proxy.

`rrdtool_def_path()` writes the RRD path of a graph or export `DEF`. The local
pipe gets it quoted, as before. rrdproxy reads a `DEF` path up to the first
`:` and resolves it as sent, so the proxy gets it bare and relative to the RRA
directory, and a graph whose path holds a blank, a quote, a backslash or a
colon, or lies outside the RRA directory, is refused before it is sent, with
the graph error image. Legends, `COMMENT` text and titles keep the encoder's
quoting on both transports; rrdproxy passes them on and `rrdtool -` removes the
quotes. The data source name in a `DEF` stays quoted too, since rrdproxy keeps
everything after the path as it is.

Through rrdproxy 54aad57, exports (`xport`, and with it CSV export) work; the
interop test passes one through rrdproxy's own path resolution to a real
RRDtool. Data Source statistics work as well: their `graph x ...` command
names an output file first, and their `DEF` paths already reached the proxy
bare through `rrdtool_proxy_relative_paths()`, so `lib/dsstats.php` is
unchanged. Graph images (`graph` and `graphv`) do
not, for two reasons in rrdproxy's `lib/functions.php`:

- It refuses any command in which a blank, `=`, `:` or `,` comes before `/` or
  `\` (line 412). Kadupul adds `COMMENT:"  \n"` after the date range of every
  graph with a start and end, and a legend such as `In / Out` has the same
  sequence.
- It takes the first token after `graph -` that does not start with `-` as the
  output file and prefixes it with the RRA directory (lines 442 and 469). That
  token is a word of the title or the first graph element, not the output.

A test marked KNOWN LIMITATION in `RrdProxyInteropTest` pins both, so a fixed
rrdproxy fails it. rrdproxy also splits each command on whitespace and joins
it with single blanks (lines 447 and 485), inside quotes as well, so two blanks
in a legend or title arrive as one.

Remaining proxy work:

- Graph images need an rrdproxy that recognizes `-` as the output and accepts
  the date `COMMENT`.
- Reads after the key exchange have no time limit, as before.
- The protocol needs a version and a matching proxy release before any of
  these can change: frames carry no MAC, so CBC ciphertext can be altered
  undetected; the IV is always zero; nothing binds a reply to its request or
  stops a replay; the proxy fingerprint is MD5; and the client never proves it
  holds its private key, since the proxy accepts it by source address and the
  fingerprint of the public key it sends.

Titles, vertical labels and legend text are HTML-escaped before they reach
RRDtool, so the characters `&`, `<` and `>` reach the image as entities. That output is
pinned as it stands. Changing it alters existing graphs and is a separate change
with a release note.

## Constraints

Collector-side creation and updates run on remote collectors and depend on
`lib/database.php` for multi-session routing and reconnects. Doctrine DBAL
provides neither, so those paths read through a Legacy adapter until that
routing is migrated.

`rrdtool_function_update` is on the poller and boost path. Before a caller on
that path goes through the Symfony container, compare kernel boot and service
lookup against direct construction on a real boost batch.

Upstream Cacti fixes to `lib/rrd.php` no longer apply directly once a function
has moved. Record each move below so a fix can be ported to the right class.

## Moved functions

| Legacy function | Graphing class |
| --- | --- |

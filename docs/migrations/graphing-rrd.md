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
| RRD file paths and the remaining pipe commands in `lib/rrd.php`, `lib/boost.php`, `lib/rrdcheck.php`, `lib/rrd_maintenance.php`, `lib/dsstats.php`, `lib/functions.php` and `poller_maintenance.php` quoted with the encoder | This PR |
| One-shot calls through `symfony/process` argument arrays; long-lived pipe in `LocalRrdtool` | Pending |
| Proxy client hardening without a wire format change | Pending |
| Graph command generation split by option, definition, item type and legend | Pending |
| Web-side graph reads through DBAL; collector writes stay on `db_*` | Pending |
| RRD file repair, `rrdtool_info2html` to Twig, error image and colour helpers | Pending |
| Callers moved to Graphing services; wrappers marked `#[\Deprecated]` | Pending |

Each slice keeps the characterization tests passing unchanged. A slice that
has to change a pinned output says so and states why.

## Decisions

The proxy client cannot connect on main today. It constructs
`\phpseclib\phpseclib\phpseclib\Crypt\RSA`, which phpseclib 4 does not provide
(see [phpseclib](../../tools/dependencies/phpseclib.md)). The hardening slice
restores the client against the current RSA and Rijndael wire format and changes
nothing the proxy sees: constant-time fingerprint comparison, bounded
reads during key exchange, no global encryption state and typed failures. An
authenticated format needs a matching proxy release and a protocol version, and
is proposed separately. RRDtool proxy splits each command on whitespace and
resolves path operands and `DEF` paths exactly as sent, so quoting breaks its
path checks. Array commands already go to the proxy as bare tokens, and an
argument that is empty or holds whitespace, a quote, a backslash, CR, LF or NUL
is refused before anything is sent. String commands (create, update and graph
`DEF` paths) still carry the encoder's quoting; the proxy hardening slice turns
them into arrays before the proxy client works again.

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

# Model-driven monitoring

This document describes the design for monitoring that starts from the data
models a device advertises, not from a protocol-specific template, and records
how much of it exists in code. Part of it is implemented and tested in
isolation. Its services are wired into the container with writes disabled, but no
controller, command or poller calls them. The
[status table](#status) separates what exists from what does not, and
[known limitations](#known-limitations) lists what blocks production use.
Decisions are recorded in the [ADRs](adr/README.md).

## Overview

Legacy monitoring assumes SNMP: a device template points at OIDs and a data
query indexes them. Devices increasingly expose the same information through
gNMI, NETCONF, RESTCONF and Redfish. Defining each metric once per protocol
multiplies the definitions and ties graphs and alerts to a transport.

The design separates four concerns.

1. Models: which YANG, OpenConfig, Redfish and SNMP MIB models exist, and which
   a given device advertises or has been verified to support.
2. Packs: declarative monitoring definitions. A pack names the capabilities it
   needs and defines protocol-independent metrics with per-protocol mappings.
3. Plans: the concrete, versioned, language-neutral instruction to a collector
   for one device, derived from a pack and the device's verified capabilities.
4. Telemetry: normalised samples with exact counters and explicit quality.

```text
Inventory (device)        ModelRegistry (models, device associations)
        \                        /
         v                      v
   Monitoring: capability discovery -> pack selection -> planner -> collection plan (JSON)
                                                                        |
                                                                        v
                                                          collector (PHP now, any language later)
                                                                        |
                                                                        v
                                                         Telemetry: Sample, MetricId, CounterValue
```

## Domain boundaries

Cross-module access is through each module's `Contract` layer, as required by
[the architecture document](architecture.md). Device ids cross boundaries as
plain integers; Monitoring and ModelRegistry do not import Inventory.

| Module | Owns | Does not own |
| --- | --- | --- |
| ModelRegistry | Model identities and revisions, dependencies, provenance and checksums, and per-device model associations with their state | Deciding what to collect, protocol clients, YANG compilation |
| Monitoring | Capability discovery results, monitoring packs, the planner, collection plans, metric bindings and writer ownership | Device identity, model catalogue storage, sample storage |
| Telemetry | MetricId, ResourceId, MetricType, Unit, the SampleValue types, Sample, DataQuality, CollectionStatus and CollectionError, and the SampleSink and SinkReport contracts | Collection scheduling, graph rendering, protocol specifics, storage |
| Graphing | For this work: the gated write of samples to existing RRD files (`RecordSamples`, the RRD adapters) | Deciding which field a series maps to, or who may write it (Monitoring) |
| Inventory | Device identity, sites and desired configuration, including which credential reference a device uses | Capability state and collection plans |
| Collection | Existing legacy polling and Spine behaviour, unchanged | Model-driven plans (see below) |

The architecture document lists Collection as a planned module that owns
polling schedules and sample acceptance. Monitoring does not take that over. It
decides what to collect and expresses it as a plan; a collector decides when
and how, and sample acceptance stays with whatever consumes Telemetry samples.
If a Collection module is later introduced it consumes plans, and the boundary
between the two is revisited then.

## Protocol roles

| Technology | Role |
| --- | --- |
| SNMP | Existing transport and MIB-based models. Remains a first-class protocol and the fallback for devices without model-driven interfaces. |
| Redfish | REST/JSON management of servers and chassis. Resources are identified by URI under `/redfish/v1/`. |
| gNMI | Capability exchange, get and streaming telemetry subscriptions. Reports the models a device supports. Does not require OpenConfig. |
| NETCONF | Configuration protocol that also retrieves operational state. Its hello message lists capabilities and YANG module revisions. Does not require OpenConfig. |
| RESTCONF | HTTP access to YANG-modelled data. |
| YANG | The modelling language. Kadupul consumes module names and revisions first; compilation is deferred ([0006](adr/0006-yang-processing-strategy.md)). |
| OpenConfig | A set of vendor-neutral YANG models. One source of models, not a requirement of any protocol client. |

## Composer package strategy

Protocol libraries are intended to be standalone packages: `php-redfish`,
`php-gnmi`, `php-yang`, `php-netconf`, `php-restconf` and `php-openconfig`. They
are intended names. None exists, no repository has been created, and nothing in
Kadupul requires them ([0003](adr/0003-standalone-protocol-libraries.md)).

```text
kadupul (Infrastructure adapters)
   |  depends on
   v
php-gnmi   php-netconf   php-restconf   php-redfish
      \         |            /
       v        v           v
          php-yang  <----  php-openconfig (models; optional client integration)

A library never depends on kadupul. A client does not depend on php-openconfig.
```

Kadupul Domain and Application code depend on ports. An adapter in
Infrastructure binds a port to a library. No new Composer dependency is added by
the current work.

## Monitoring packs

The formal definition is
[monitoring-pack.schema.json](schemas/monitoring-pack.schema.json), JSON Schema
draft 2020-12, schema version 1. A valid example is
[openconfig-interfaces.pack.json](schemas/examples/openconfig-interfaces.pack.json).

A pack has a name, a semantic version, `capabilities` (`all` and `any`
lists), `model_families`, a `discovery` section that says how to enumerate
resources such as interfaces, `metrics`, `collection` preferences, optional
`graphs` and `alerts`, and `compatibility` with a Kadupul version range and an
optional informational mapping to legacy graph template and data query names.

Metric ids are dotted lowercase (`interface.in_octets`) and carry no protocol.
Every protocol detail lives under `mappings` (SNMP OID, gNMI path, Redfish
resource path and property, NETCONF XPath, RESTCONF resource path). Counters
state their width in `counter_bits` (32 or 64) so rollover can be handled
correctly. Non-numeric device values are mapped to numbers with `value_map`;
an unmapped value is bad quality, not zero.

The schema is closed: unknown fields are rejected unless the key starts with
`x-`. Rules that JSON Schema cannot express are enforced by the semantic
validator:

- metric ids are unique within a pack;
- every metric in `graphs` and `alerts` exists in the pack;
- `collection.interval_seconds.default` is at least `min`;
- every protocol in `protocol_preference` is mapped by at least one metric;
- the pack's `compatibility.kadupul` range is satisfiable by the running version.

### Lifecycle

| State | Meaning |
| --- | --- |
| draft | Authored, not checked. |
| validated | Passed schema and semantic validation. |
| installed | Stored and available for selection; not used for any device. |
| active | Selected for at least one device and producing plans. |
| deprecated | Still honoured for devices that use it; not offered for new devices. |

Versioning follows semantic versioning. Removing or renaming a metric id,
changing a metric's type, unit or counter width, or changing the meaning of a
mapping is a major change, because stored history and graphs refer to the id.
Adding a metric or a mapping is minor. Installing a new version never changes
the plans of active devices by itself; replanning is an explicit step.

Validation fails safe. An invalid pack is not installed, no existing pack is
altered, and the error names the file, the JSON pointer of the failing
location and the rule violated, for example
`/metrics/2/counter_bits: not allowed when type is gauge`. Every violation is
reported at once. Validation does not contact a device.

Packs are read from `*.pack.json` files. YAML is not read: no YAML parser is a
dependency of the Monitoring module, and the schema and examples are JSON.
Integers too large for PHP are kept as strings when decoding and then rejected,
never turned into floats. Patterns are matched with ECMA-262 anchoring, so a
trailing newline does not satisfy `^...$`; the Python `jsonschema` package is
more lenient here (see the evidence note under [Status](#status)).

## Capability discovery

Discovery reads what a device advertises: gNMI capabilities, the NETCONF hello,
a Redfish service root, or SNMP system and MIB support. Each result is recorded
as a device-to-model association with a state.

| State | Meaning |
| --- | --- |
| advertised | The device claims the model; nothing has been checked. |
| verified | A read against the model succeeded and returned plausible data. |
| partial | Some of the model works; the unsupported parts are listed. |
| unsupported | The device advertises it or an operator expected it, and verification failed. |
| unknown | Not yet determined, or discovery could not complete. |

Discovery rules:

- It is read-only. It never issues set, edit-config, or any write operation.
- It is idempotent. Repeating it with the same device state produces the same
  records and no duplicates.
- It never overwrites configuration. Operator-set values, including a manual
  override of a state, are preserved; a conflicting discovery result is
  recorded beside the override and reported, not applied.
- Results are cached with a time to live. A cached result is reused until it
  expires or a reconciliation is requested.
- Reconciliation compares a new result with the stored one. A model that
  disappears becomes `unknown` rather than being deleted, so history and the
  reason remain.
- A failed or partial discovery leaves previous results in place and records a
  CollectionError; it does not downgrade `verified` to `unknown`.

As implemented, a verification outcome (`verified`, `partial`, `unsupported`)
is replaced only by another verification outcome, never by a later
advertisement or an `unknown`. A model a provider stops reporting becomes
`unknown` if it was only advertised; a verification outcome is kept and listed
as not reported. Each stored record is the provider's own report. The registry
assessment is applied on every discovery and not stored: a model the registry
does not know, or does not know at that revision, is `unknown`; a registered
model whose dependency the device does not advertise is `partial`; one whose
dependency is advertised below the required revision is `unsupported`. Each
carries the reason. Capability records stay in Monitoring's own store; writing
them into ModelRegistry device associations is not done, because the registry's
Contract is read-only.

## Planning

The planner takes a device's assessed capabilities, the installed packs, the
device's enumerated resources and its credential references, and returns a
proposal. A pack is selected when its `capabilities` are satisfied by verified
capabilities, or by advertised ones too when the planning policy accepts them.
Each metric is collected over the first protocol in the pack's
`protocol_preference` that the metric maps, that a usable capability came
through, and that has a credential reference. A metric no usable protocol maps
is left out with the reason, which is how a `partial` capability reduces a pack.

Existing monitoring, legacy or from other packs, is read through a port with no
write method. A metric already collected for a resource is reported as an
overlap and not planned again for that resource. A gNMI, NETCONF or RESTCONF
path with a `[key=*]` wildcard is narrowed to the remaining resources; an SNMP
OID or Redfish resource cannot be narrowed, so a partly covered metric is
withheld with the reason. A `prefer-new` policy plans it anyway and still
reports the overlap.

Plans come out `proposed`. Replanning compares a fresh proposal with the
device's approved and active plans and reports added, removed, changed and
unchanged plans; it never modifies a stored plan. Only `ActivateCollectionPlan`
moves a plan to `approved` and then `active`, each step by an authorized actor,
and only when the activation policy allows it. The policy denies by default, and
with no authorizer configured every request is denied. The `dedup_key` is the
SHA-256 of the plan's content without its id, time and status; the `plan_id` is
a version 8 UUID derived from that key and the generation time.

## Collection plans

The formal definition is
[collection-plan.schema.json](schemas/collection-plan.schema.json), version
`plan_schema_version` 1. Examples:
[gNMI](schemas/examples/gnmi-interfaces.plan.json) and
[SNMP](schemas/examples/snmp-interfaces.plan.json).

A plan is the contract between the control plane and any collector. It contains
the device id, the pack name and version, a status (proposed, approved, active,
superseded), and one or more targets. Each target names a protocol, an endpoint,
a subscription (mode `sample`, `on_change` or `poll`, an interval where
applicable, and the paths, OIDs or resources) and the metrics it yields, each
with the `source` that produces it. The `dedup_key` is an opaque hash computed
by the planner so equal plans can be recognised; readers compare it and do not
parse it. The example keys are illustrative and were not computed by a planner.

The endpoint carries a `credential_ref` and optional port and TLS settings. The
device address is resolved by the collector from the device id; the plan holds
no secret and no address. Serialisation is UTF-8 JSON, one plan per document,
timestamps in UTC RFC 3339, counters never in plan documents.

Error handling for readers:

- A document that fails schema validation is rejected whole. A collector keeps
  running its previous active plan and reports the rejection.
- A plan with a `plan_schema_version` the reader does not support is rejected,
  not partially read.
- Unknown fields are ignored only under the `x-` prefix; any other unknown field
  is a validation error.
- A newer plan for the same device supersedes the older; the older becomes
  `superseded` and stays readable for audit.

Compatibility: adding an optional field is allowed within version 1 only after
all readers accept it. Removing a field, changing a meaning, or adding a
required field increments `plan_schema_version`. Producers emit the lowest
version that can express the plan during a transition.

### Version 2: logical bindings

[collection-plan.v2.schema.json](schemas/collection-plan.v2.schema.json) is
version 1 plus one optional field on a metric, `binding`, with examples
[gNMI](schemas/examples/gnmi-interfaces.plan.v2.json) and
[SNMP](schemas/examples/snmp-interfaces.plan.v2.json). The version 1 schema and
examples are unchanged and still valid.

A binding holds an opaque `binding_id` and the resource it applies to (`kind`
and `identity`). Nothing else. A plan never carries a file path, an RRD file or
data-source name, a `local_data_id`, or SQL. The v2 schema enforces this by
refusing, case-insensitively and in `x-` extension keys too, any object key that
contains `rrd`, `local_data`, `data_source` or `sql`, or that is exactly `path`
or `x-path`. That is a guard on key names; it cannot detect storage detail
placed in a value, so the serializer test also scans the output
(`PlanSchemaV2Test`).

`PlanSerializer` applies the version bump rule: a plan in which no metric has a
binding is written as version 1, and a plan with at least one binding is written
as version 2. A binding appears only when the `BindingCatalog` resolves an
approved or verified binding and the metric's source yields exactly one
resource; a wildcard source covers many resources and is never bound. Binding a
metric changes the plan's `dedup_key`; plans without bindings keep their keys.
A collector that reads only version 1 therefore keeps working until the first
binding is approved, and then must be upgraded.

The `binding_id` is derived from the series key (device, metric, resource,
protocol) and not from the storage field, so it survives a change of storage.
`RecordSamples` does not read it: it resolves each sample by that same key. The
id is a handle for a collector and for log correlation.

For a collector in another language, including Rust, this keeps the contract
small. The collector receives what to collect and the logical series each value
belongs to, reports `Sample` values through the Telemetry contract, and never
learns where they are stored. The schema is closed and uses only JSON types, so
a strict deserializer can implement it. No Rust code or collector process exists.

## Telemetry normalisation

Collectors translate protocol values into the Telemetry contract
([0004](adr/0004-common-telemetry-contracts.md)). Counters are exact unsigned
64-bit values carried as decimal strings and handled with GMP, never as floats.
The first sample of a counter has no delta. Unknown or invalid values are bad
quality or absent, never zero. Quality is one of good, stale, estimated, bad.

### Raw samples and calculated intervals

Telemetry has two separate representations of a counter.

- A raw sample (`Sample` with a `SampleValue`) is the cumulative reading as the
  device reported it. This is what storage receives.
- A calculated interval (`CounterInterval`, produced by `CounterNormalizer`) is
  the difference between two consecutive readings, classified as a delta, a
  reset or a gap. It exists for analytics, alerting and any consumer that needs
  a per-interval value.

A COUNTER or DERIVE data source in RRDtool computes the rate itself from
cumulative readings, applying its own rollover and range rules. Writing a
calculated delta into such a data source would be differenced a second time and
would record a wrong rate. So storage gets raw values, `CounterInterval` is not
used by the RRD path, and the two must not be mixed.

`SampleValue` has one implementation per data-source family, each keeping the
decimal text unchanged and never converting through a float: `CounterValue`
(unsigned 64-bit, for COUNTER), `AbsoluteValue` (unsigned 64-bit, for ABSOLUTE),
`DeriveValue` (signed 64-bit, for DERIVE), `GaugeValue` (a decimal of at most 64
characters, for GAUGE) and `UnknownValue` (written as `U`, never zero).

Every `Sample` names its device id and its `CollectionProtocol` (snmp, gnmi,
redfish, netconf or restconf, the names a plan target uses). `Sample::source`
is provenance text only. `CounterNormalizer` treats device, protocol, metric,
resource and labels as one series, so two devices with an interface of the same
name never share a series, and an SNMP reading is never differenced against a
gNMI reading.

### Counter decreases

Decided on 2026-10-23 in [0004](adr/0004-common-telemetry-contracts.md), and
covered by `CounterNormalizerTest` and `ProtocolEquivalenceTest`:

- A decrease on a counter declared 32 bits wide (on the sample or per metric in
  the policy), with both values inside 32 bits, is a delta with `rolledOver` set:
  `4294967290` followed by `5` gives 11.
- A decrease on a counter declared 64 bits wide, or of unknown width, is a reset
  with no delta. `18446744073709551614` followed by `2` is a reset, not a delta
  of 4. A value above 32 bits under a 32-bit width declared in the policy is
  also a reset; on the sample itself such a value is rejected when the `Sample`
  is built.
- A sample flagged `deviceRestarted` makes the interval a reset even when the
  value rose. That marker wins over a gap, and a gap wins over a decrease.

Until 2026-10-23 a declared 64-bit decrease was reported as a rollover. RRD
storage is not affected either way, because it receives raw values and leaves
rollover to RRDtool.

## RRD data-source semantics

An RRD file holds named data sources, each with a type, and the type decides how
RRDtool turns an update value into what it stores. The model-driven write path
supports four types (`Kadupul\Monitoring\Contract\RrdDataSourceType`).

| Type | Value written | What the formatter requires |
| --- | --- | --- |
| GAUGE | The reading as given, stored as a value | `GaugeValue`: an optionally negative decimal, no exponent |
| COUNTER | Cumulative reading; RRDtool stores a per-second rate | `CounterValue` or `AbsoluteValue`: an unsigned integer up to 18446744073709551615 |
| DERIVE | Cumulative reading that may fall; RRDtool stores a per-second rate | `DeriveValue`: a signed integer within the signed 64-bit range |
| ABSOLUTE | A count since the previous reading; RRDtool stores it divided by the step | `CounterValue` or `AbsoluteValue`: an unsigned integer up to 18446744073709551615 |

The right-hand column is what Kadupul's formatter enforces. The description of
what RRDtool stores is from RRDtool's documentation and is not itself tested
here, except where listed below.

COMPUTE, DCOUNTER and DDERIVE are not supported: a sample cannot be written to a
computed field, and no binding has needed the others. A file whose data source
reports a type the binding does not expect is refused with `TypeMismatch`.

What was checked against RRDtool, by `RecordSamplesRrdIntegrationTest`,
`ModelDrivenRrdWriteWorkflowTest` and the adapter tests, which create real files
in a temporary directory and run the `rrdtool` binary found on this machine
(reported as `RRDtool 1.11.0`; no other version was tried):

- All four types accept an update through the formatter and `rrdtool lastupdate`
  reports the value written: `1000` for COUNTER, `-12.5` for GAUGE, `-40` for
  DERIVE, `7` for ABSOLUTE. For these three non-counter types only the accepted
  raw input was checked, not the stored rate.
- A COUNTER reading of `18446744073709551000` followed by `18446744073709551300`
  is accepted exactly. `lastupdate` returns the second value unchanged and the
  fetched rate for the interval is `1.0000000000e+00`. The two values differ by
  300 over a 300 second step and cannot be told apart as doubles, so the exact
  rate shows that RRDtool differences the decimal text and a float conversion in
  Kadupul would have given 0.
- The rates RRDtool stores are doubles. Exactness applies to the input accepted
  and to the last reading it keeps, not to stored consolidated values.
- An unknown reading is stored as `U`.
- On a COUNTER capped with a maximum of 1000000, a drop from 2000 to 500 is
  stored as `nan` for that interval and not as a negative rate; the intervals
  around it hold `3.3333333333e+00`.

Two consequences follow. RRDtool's own rollover and reset handling applies to
stored data whatever Kadupul's normalizer thinks, and a gauge keeps its full
decimal text on input but is subject to RRDtool's double-precision storage.

## Metric bindings

A metric binding says where one logical series is stored. It is
`Kadupul\Monitoring\Domain\MetricBinding`.

The identity key (`BindingKey`) is device id, metric id, resource (kind and
identity) and protocol. The protocol is one of snmp, gnmi, redfish, netconf or
restconf. The storage field (`StorageField`) is a local data id plus a
data-source name, because one legacy data source can hold several fields in the
same RRD file; the local data id alone does not say which field a binding
writes. A name follows RRDtool's rule of 1 to 19 characters of letters, digits,
underscore and hyphen.

Uniqueness is enforced at two levels, and they are different.

- One key has at most one live binding. Registering a different definition for a
  known key is rejected (`BindingConflict`) until the existing one is rejected.
  Registering an identical definition returns the stored binding unchanged, so a
  retried discovery run is harmless, and it does not revive a rejected binding.
- One storage field holds one series. Registering a second key for a field that
  already has a non-rejected binding is rejected.
- Several fields of one file bind to different keys. That is allowed and tested.

A binding has a status. `Proposed` moves to `Approved` or `Rejected`; `Approved`
to `Verified`, `Stale` or `Rejected`; `Verified` to `Stale`; `Stale` to
`Rejected`; `Rejected` is final. Only `Approved` and `Verified` resolve for
storage. Registration always starts at `Proposed`; approval is a separate step
(`ApproveBinding`) that checks authorization before it reads the store and
returns the transition. With no `BindingApprovalAuthorizer` configured, every
approval is denied, and no IdentityAccess adapter exists, so approval is denied
outside tests.

Each binding records provenance: an origin (`model_driven`, `legacy_discovered`
or `operator`), a source detail that must not be empty, and a UTC time.
`lastVerifiedAt` is carried but no code sets it yet, and nothing moves a binding
to `Verified` or `Stale` yet; those transitions are defined and tested on the
domain object only.

`ResolveBinding` implements the `BindingCatalog` contract. It returns a
resolution with one of four statuses: `resolved`, `no_binding`, `ambiguous`
(the store returned more than one binding for the key) or `not_approved`. A
resolved answer carries the binding id, pack, data-source name and type, an
opaque `StorageReference` such as `local-data:12`, and the current writer. The
reference names the file without giving a path; only an infrastructure adapter
turns it into one. Resolution only reads. It reports ownership as found and
cannot change it.

Bindings are stored by `DbalBindingRepository` in `kadupul_metric_binding`
([0009](adr/0009-durable-binding-and-ownership-store.md)); unit tests use
`InMemoryBindingRepository`, and both pass the same contract tests
(`BindingRepositoryContract`). The primary key is the binding key, so one row
exists per key. Pack name and version are stored with the definition and are
not part of the key. A save locks the stored row and refuses a write that does
not follow from it (`MetricBinding::assertMayReplace`): a stale copy cannot undo
a rejection or move an approval back, and a different definition replaces only a
rejected binding, as a new proposal. Resource identity and data-source name are
`varbinary`, so `eth0`, `ETH0` and `eth0 ` are different keys and `traffic_in`
and `Traffic_in` different fields. A value longer than its column is refused
instead of truncated, database errors reach the caller, and a row changed outside
the store so that it breaks a domain rule is reported as `StoredBindingUnreadable`.

## Legacy data-source discovery

`LegacyDataSourceDiscovery` reads one device's existing data sources so that
bindings can be proposed from monitoring that already exists. It is read-only: it
issues `SELECT` statements with bound values and has no method that writes. It
reads `data_local`, `data_template_data`, `data_template_rrd`, `poller_item`,
`host_snmp_cache`, `graph_templates_item`, `snmp_query` and `data_input`, and
returns for each data source its fields, whether a `poller_item` row writes each
field, its data-query index, the cached resource names and the graphs that use it.

`LegacyExistingMonitoring` classifies how well a legacy field matches a logical
series (device, metric, resource). The protocol is not compared, because a
binding says where a series is stored and not which protocol collects it.

- Resource evidence is strong when a present `host_snmp_cache` value of an
  identity field for the kind (`ifName`, `ifDescr` or `ifIndex` for interfaces)
  equals the resource identity. It is weak when only the stored index equals the
  identity, or when the matching cache entry is no longer present because the
  device was reindexed.
- Metric evidence is strong when the reviewed `FIELD_SEMANTICS` map names the
  data-source name for the metric (for example `traffic_in` for
  `interface.in_octets`). It is weak when the name only resembles the metric's
  last segment.

| Confidence | Rule |
| --- | --- |
| Confirmed | Exactly one candidate, both kinds of evidence strong, a data-source type a binding can expect, and a valid name |
| Ambiguous | More than one candidate, for example two indexes reporting the same interface name |
| Heuristic | One candidate with weak evidence, or whose type or name cannot be bound |
| None | No candidate, or no identity fields are known for the resource kind |

Only a Confirmed match can become a binding, and only as `Proposed` with
`legacy_discovered` provenance (`RegisterBinding::discovered`). Ambiguous and
Heuristic matches are reported with reasons and never bound. `identityConcerns()`
lists data sources whose identity needs review: a duplicate resource name, a
reindexed SNMP index, no `poller_item` row, or several fields in one file.

`monitoredSeries()` feeds the planner's overlap detection. It reports every
field with strong metric evidence whose data source has an interface name,
ambiguous names included, because over-reporting an overlap only stops duplicate
collection.

Limits: the adapter is tested against an in-memory SQLite copy of the legacy
tables (`LegacyMonitoringFixture`, `LegacyDataSourceDiscoveryTest`), using the
column names from `cacti.sql`. It has not been run against MariaDB or MySQL.
`FIELD_SEMANTICS` covers six stock interface names, and identity fields are
defined only for interfaces. There is no adapter from a `local_data_id` to an RRD
file; `data_source_path` is read as stored (normally `<path_rra>/...`) and is
never resolved.

## Writer ownership

Every storage field has one authoritative writer. `WriterOwnership` has four
values.

| Value | Meaning |
| --- | --- |
| `legacy_poller` | The legacy poller writes the field. |
| `model_driven` | Model-driven collection has claimed it. |
| `unassigned` | No one is known to write it. |
| `conflict` | Both claim it. Nothing writes until an operator settles it. |

The default is legacy. `LegacyDataSourceDiscovery::ownership()` reports
`legacy_poller` for every field defined in `data_template_rrd`, whether or not a
`poller_item` row currently exists, because a missing row is more often a poller
cache waiting to be rebuilt than a field nobody writes.

`WriterOwnershipRegistry` combines that answer with model-driven claims kept by a
`WriterClaimStore`: `DbalWriterClaimStore` in `kadupul_writer_ownership`, one row
per storage field, in the application, and `InMemoryWriterClaimStore` in unit
tests. No row means no claim, so the legacy answer stands. A claim is a plain
insert, and the primary key decides between two writers: one commits, the other
sees a duplicate key and reports the field as already claimed. Nothing deletes a
claim. A claim happens only through an explicit `claim()` call and is refused
for a field the legacy poller or a conflict holds. If the legacy poller starts
writing a field after it was claimed, both sides claim it and the answer is
`conflict`. There is no automatic handoff in either direction, and approving a
binding does not change ownership; `ApproveBinding` has no access to it.

In practice this means a model-driven claim can succeed only for a field that
the legacy tables do not define. Moving an existing legacy field to model-driven
collection is not possible today; see [future poller handoff](#future-poller-handoff).

## Safe RRD integration

`RecordSamples` (Graphing) is a `SampleSink` that stores samples in existing RRD
files. One sink serves any number of devices: each sample is resolved by its own
device id and collection protocol, and a batch may mix devices. Each sample ends in a stored outcome or a refusal with a
`RejectionReason` and a one-line detail; nothing is dropped silently, and the
`SinkReport` holds one outcome per input sample in input order.

The write gate (`WriteGate`) is closed by default. Opening it takes an explicit
`WriteGate::enabled()`, so wiring the sink in by accident cannot start writing to
existing graphs. In the container the gate comes from the
`kadupul.monitoring.rrd_writes_enabled` parameter, which is `false` and has no
environment source.

The checks run in this order, and a sample stops at the first refusal.

1. Gate. If closed, every sample is refused with `WritesDisabled` before any
   binding is read or any file is touched.
2. Resolution. Each sample is resolved by its device id, metric, resource and
   collection protocol; `Sample::source` plays no part. A missing or unapproved
   binding is `NoBinding`; more than one is `AmbiguousBinding`. A resolution that
   answers for a different series than the sample is `NoBinding`.
3. Ownership. A `conflict` is `OwnershipConflict`; any owner other than
   model-driven is `NotOwnedByModelDriven`.
4. Location. `ConfiguredRootTargetLocator` turns the storage reference into a
   file. A missing file or an unknown reference is `TargetMissing`; a file that
   resolves outside the root is `StorageFailure`.
5. Inspection. `RrdtoolInspector` runs `rrdtool info` to read the data-source
   types and the last update time. A name the file lacks is `TargetMissing`; a
   type other than the binding expects is `TypeMismatch`.
6. Formatting. `RrdUpdateFormatter` checks the name, a timestamp after the epoch
   and no more than 300 seconds ahead of the clock, the value family against the
   type (`TypeMismatch`), and the value text (`InvalidValue`). A second at or
   before the file's last update is `DuplicateSample` (equal) or `OutOfOrder`
   (earlier). Two readings of one data source within one second are a duplicate,
   because rows have one-second resolution.
7. Update. `RrdtoolUpdater` runs `rrdtool update` with `--template`. Rows are
   grouped by data-source set and kept in time order, and a change of set starts a
   new call.

Containment. The locator maps `local-data:<id>` to a relative path configured for
that id, below one root that has no default. It rejects an absolute path, `..`, a
backslash, a NUL byte or a name not ending in `.rrd`, resolves symlinks, requires
the real path to stay inside the real root, and hands that resolved path to
RRDtool. Tests cover symlinked files and directories that escape the root,
traversal, a dangling link and a directory named like a file.

Process execution. `RrdtoolProcess` starts the binary through Symfony Process
with an argument array, never a command string, from a configured absolute path
rather than a legacy setting, with a fixed environment (`PATH`, `LC_ALL=C`,
`TZ=UTC`, nothing inherited), a deadline after which the process is killed, and
both output streams drained together and capped at 1 MiB. When a spawn fails,
Symfony Process retries it through `/bin/sh` with each argument single-quoted.
`RrdtoolProcess` checks that the binary is still an executable file just before
starting it, so only a binary removed in that interval reaches the quoted
fallback, which then reports exit status 126 or 127 as a `StorageFailure`. `RrdtoolUpdater` refuses any template or
row that begins with `-`, so no value can be read as an option such as
`--daemon`.

Sanitized outcomes. Rejection detail reaches logs and API responses.
`SampleOutcome` refuses text that contains a path separator, SQL or a line break,
and RRDtool's first error line has anything path-like replaced. `RecordSamples`
replaces a detail that is still refused with a fixed message instead of failing
the batch.

Partial writes. RRDtool applies rows in order and keeps those before a failing
row, so a failed call does not mean nothing was written. After a failure the
sink reads the file again: rows at or before the stored last update are reported
accepted, the rest as `StorageFailure`, and later calls to the same file are
reported as not attempted because their rows assumed the earlier call succeeded.
If the re-read also fails, rows are reported failed. Each file is handled alone,
so one file's failure does not stop another's samples. This behaviour is tested
with a fake updater (`RecordSamplesTest`), not with a real RRDtool failure part
way through a call.

Idempotence. Resubmitting a batch writes nothing the second time; the samples
come back as `DuplicateSample` or `OutOfOrder`.

Known gap. The check that a path lies in the root and the later RRDtool call are
separate steps. A path component replaced by a symlink between them is not
caught. The mitigation is operational: the root should be writable only by the
collector account. This is a time-of-check to time-of-use limit of the design.

What the tests show about not touching files: for each refusal case in
`RecordSamplesRrdIntegrationTest`, a hash, modification time and `lastupdate` of
the file taken before and after are equal. `RrdWriteSafetyTest` checks that the
write path names no legacy RRD location or setting and that the tests create
files only under a temporary root.

## Future poller handoff

NOT IMPLEMENTED. This section is a staged design proposal for review. No code,
configuration or test exists for any step, and nothing here should be read as
describing current behaviour.

The problem. A model-driven claim is refused for any field the legacy tables
define, so no existing graph can move to model-driven collection. Handing a
field over means changing what the legacy poller does, which this work
deliberately does not touch.

Preconditions before any handoff is attempted:

- The write gate is open for the deployment on purpose, and a production
  `local_data_id`-to-file adapter and a durable binding store exist.
- An IdentityAccess authorizer approves bindings and the handoff itself, and the
  action is audited with actor, target and outcome.
- The binding is `Approved`, its legacy match was `Confirmed`, and the file's
  data-source type and the pack's metric type agree.
- A collector produces the samples, and its output has been compared with the
  legacy values without writing to the production file (for example by reading
  the file and the samples side by side, or by writing to a copy).
- The RRD file is backed up.

Stages, each stopping on failure:

1. Observe. Read-only comparison of model-driven samples with what the legacy
   poller stored. No writes to the production file.
2. Quiesce. Stop the legacy poller writing the field. The legacy poller reads
   `poller_item` rows; a multi-output script writes every field of its file
   through one row with an empty `rrd_name`, so the unit that can be quiesced may
   be the whole file and not one field. The design must either hand over the whole
   file or leave it.
3. Claim. Record the model-driven claim only after the legacy side has stopped
   and the probe no longer reports the field as written by the legacy poller. A
   claim made while the legacy poller still writes is refused today, and a legacy
   writer that resumes after a claim produces `conflict`.
4. Verify. Confirm the first accepted row by `lastupdate` and no `conflict`.
   Mark the binding `Verified`.
5. Rollback. Release the claim, restore the legacy `poller_item` rows, rebuild the
   poller cache and accept a gap in the series. Rows already written cannot be
   withdrawn.

Dual writes are prohibited. Two writers on one field would interleave rows or
produce `OutOfOrder` and `DuplicateSample` refusals, and neither writer could
know which stored value was authoritative. The registry reports such a field as
`conflict` and the sink refuses it; the handoff must never rely on that refusal
as its safety mechanism. Comparing old and new write paths against production
data is also out of scope.

These steps change legacy poller state, so the design needs its own decision
record and legacy-side tests before it is built.

## Migration strategy

Legacy templates, data queries, Spine and RRDtool storage are unchanged, and the
model-driven path coexists with them
([0002](adr/0002-monitoring-packs-and-legacy-templates.md)). The intended order:

1. Read. Discover legacy data sources and capabilities; propose bindings for
   confirmed matches only. No writes.
2. Review. An authorized operator approves or rejects bindings. Ambiguous and
   heuristic matches stay with the operator.
3. New series. Model-driven collection writes only to fields no legacy template
   defines. The write path uses existing files and does not create them, so
   creating a file for a new series is not designed yet.
4. Handoff. Moving a legacy field is the proposal above. Until it is accepted
   and built, legacy fields stay with the legacy poller.
5. Retire. A legacy template is deprecated only after parity and a migration
   path are shown.

## Cacti compatibility

Nothing in the legacy application is changed or removed by this work: device
templates, data queries, graph templates, RRDtool storage, Spine, the poller,
plugin hooks and existing tables are untouched. The schema gains two new tables,
`kadupul_metric_binding` and `kadupul_writer_ownership`, and nothing else. The new
code only reads legacy tables, writes only its own two tables, and writes RRD
files only through the gated sink to files a binding names. The
`Kadupul\Graphing\Infrastructure\Rrd` classes are registered individually in
`config/services.yaml`, not by directory, and default to unconfigured storage. Packs and legacy templates coexist
([0002](adr/0002-monitoring-packs-and-legacy-templates.md)). A pack can name the
legacy templates it corresponds to, for information only. Legacy templates are
not deprecated until parity and a migration path are shown. The storage design is
in [0007](adr/0007-raw-samples-and-rrd-sink-semantics.md) and
[0008](adr/0008-data-source-ownership-and-bindings.md).

## Rust interoperability

The control plane remains PHP. The plan is the interface to collectors, so a
collector can be written in another language, including Rust, without
changing planning ([0005](adr/0005-php-control-plane-independent-collectors.md)).
The schema is written to be implementable by a strict deserialiser: JSON types
only, closed objects, explicit versions and opaque identifiers. No Rust code is
part of this work, and no collector process exists yet.

## Security

- Plans and packs contain `credential_ref` names only. Secrets are resolved by
  the collector from its own store and never appear in a plan, a log, a
  validation error or an audit record.
- TLS certificate verification is on by default. Disabling it is an explicit,
  per-target setting that is visible in the plan and should be audited.
- Discovery is read-only. Credentials used for discovery should be read-only
  where the protocol allows it.
- Activating a plan (moving it to `active`) is a privileged action. It requires
  an authorized actor through IdentityAccess's public contract and is recorded
  as an audit event with actor, target and outcome. Generating a `proposed`
  plan does not start collection.
- Packs are data, not code. A pack cannot run commands or load code; its fields
  are validated against a closed schema.
- Paths, OIDs and resource URIs from a pack are validated by pattern before use
  and are sent only to the device the plan names.
- Defaults are conservative: plans start `proposed`, TLS verifies, intervals
  respect the pack minimum.

This describes intended behaviour. None of it is verified until the status table
says so.

## Roadmap

Milestones are ordered by dependency. None is committed to a date, and each is
merged only with a working use case and tests.

| Milestone | Scope | State |
| --- | --- | --- |
| 1. Contracts | Telemetry contract, ModelRegistry types, pack and plan schemas, ADRs | Done |
| 2. Capability state | Discovery result and reconciliation; in-memory stores | Done |
| 3. Packs and planner | Pack loading and validation; planner producing plans | Done |
| 4. Storage foundation | Raw sample values, `SampleSink`, bindings, writer ownership, legacy discovery, gated RRD sink, plan v2 | Done |
| 5. Production wiring | Service configuration, a durable binding store with its schema and upgrade, a `local_data_id`-to-file adapter, an IdentityAccess authorizer, an audit event for approval and activation | Slice A (service configuration) and slice B (durable binding and ownership stores, schema step 1.2.37) done. Remaining: durable plan, pack and capability stores, the `local_data_id`-to-file adapter, the authorizers, the audit event, a source for the running version so `PackLifecycle` can be wired |
| 6. First protocol adapter | One real adapter behind `CapabilityProvider`, using an actual library, and a collector that executes a plan end to end | Not started; needs a protocol library |
| 7. Poller handoff | A decision record and legacy-side tests for [moving a legacy field](#future-poller-handoff), then the implementation | Not started; proposal only |
| 8. New series and presentation | Creating RRD files for new series, graphing and alerting from metric ids, template parity and migration guidance | Not started |

## Status

Statuses: implemented (code with a use case and tests under `tests/Symfony`),
scaffolding (a port or type with no production adapter, or an adapter nothing
calls), and future. The Telemetry, ModelRegistry, Monitoring and Graphing sink
services are wired in `config/services.yaml` (milestone 5, slice A). Bindings
and writer ownership claims are kept in the database on the `main` connection
(slice B). The plan, pack, capability and model stores are in memory and lose
their state at the end of each request, nothing calls the use cases from a
controller or command, and the RRD write gate is closed. `ModelDrivenWiringTest`
boots the test container and checks that the services resolve without opening a
connection.

Service wiring defaults. `kadupul.monitoring.rrd_writes_enabled` is `false` and
no environment variable feeds it, so `SampleSink` resolves to `RecordSamples`
with a closed gate and every sample is refused as `WritesDisabled`. Binding
approval and plan activation have no authorizer, so no binding is approved and
opening the gate would not store anything; do not change the parameter until an
authorizer exists. `kadupul.monitoring.rrdtool_binary` and
`kadupul.monitoring.rrd_root` are empty by default. While either is empty,
`RrdStorageFactory` returns `UnconfiguredRrdStorage`, which refuses every storage
call, so nothing guesses a binary or a directory and nothing reads the legacy
`path_rrdtool` or `path_rra` settings. A non-empty but invalid value throws
`InvalidRrdConfiguration` when the service is built. `kadupul.monitoring.rrd_paths`
(`local_data_id` to relative path) is empty and nothing fills it.
`kadupul.telemetry.series_limit_per_metric` (1000) is a provisional value, not a
measured one. `PackLifecycle` and `PackValidator` are not wired because the
validator needs the running product version and no non-legacy source exists yet.

| Item | Status |
| --- | --- |
| Architecture decision records 0001 to 0009 | Written, proposed |
| Pack JSON Schema and example | Written; validated against its example, see below |
| Collection plan JSON Schemas (v1, v2) and examples | Written; validated against their examples, see below |
| Telemetry contract: `MetricId`, `ResourceId`, `Sample`, `CounterValue`, `CounterNormalizer` and related | Implemented; tests in `tests/Symfony/Telemetry`. Samples carry a device id and a `CollectionProtocol`; a decrease is a rollover only on a declared 32-bit counter and a reset otherwise, as [0004](adr/0004-common-telemetry-contracts.md) specifies |
| Telemetry raw values: `AbsoluteValue`, `DeriveValue`, `GaugeValue`, `UnknownValue` | Implemented; `SampleValueTest` |
| Telemetry sink contract: `SampleSink`, `SinkReport`, `SampleOutcome`, `RejectionReason` | Implemented; `SinkReportTest`, `RecordingSampleSinkTest` |
| ModelRegistry (models, revisions, device associations) | Implemented with in-memory repositories: `RegisterModel`, `RecordDeviceObservation`, `RepositoryModelCatalog` behind the `ModelCatalog` Contract |
| Monitoring: capability discovery result | Implemented: `DiscoverDeviceCapabilities`, `InMemoryDeviceCapabilityStore`. The `CapabilityProvider` port is scaffolding: no protocol adapter exists |
| Monitoring: pack loading and validation | Implemented: `PackValidator`, `JsonPackReader` (JSON only), `PackLifecycle`, `InMemoryPackRepository` |
| Monitoring: planner and collection plan | Implemented: `MonitoringPlanner`, `CollectionPlan`, `PlanSerializer` (v1 and v2), `ActivateCollectionPlan`, `InMemoryCollectionPlanStore`. Scaffolding: `PlanActivationAuthorizer` has no IdentityAccess adapter, so activation is always denied outside tests |
| Monitoring: metric bindings | Implemented: `MetricBinding`, `BindingKey`, `StorageField`, `RegisterBinding`, `ApproveBinding`, `ResolveBinding` behind `BindingCatalog`, `DbalBindingRepository` (wired) and `InMemoryBindingRepository` (tests). `DbalMonitoringStoreDatabaseTest` runs against MariaDB or MySQL when `KADUPUL_TEST_MYSQL_DSN` is set and skips otherwise. Scaffolding: `BindingApprovalAuthorizer` has no adapter, so approval is denied outside tests |
| Monitoring: writer ownership | Implemented: `WriterOwnershipRegistry` over the `WriterOwnershipProbe` port, with claims in `DbalWriterClaimStore` (wired) or `InMemoryWriterClaimStore` (tests); legacy side is `LegacyDataSourceDiscovery::ownership()` |
| Schema: `kadupul_metric_binding`, `kadupul_writer_ownership` | Implemented: `cacti.sql`, `docs/audit_schema.sql` and the 1.2.37 upgrade step, compared by `ModelDrivenSchemaTest` and, on a server, `ModelDrivenSchemaDatabaseTest` |
| Monitoring: legacy data-source discovery | Implemented and read-only: `LegacyDataSourceDiscovery`, `LegacyExistingMonitoring` (also the `ExistingMonitoring` adapter used for overlaps). Tested on SQLite only; no caller in the application |
| Graphing: gated sample write | Implemented: `RecordSamples`, `WriteGate`, `RrdUpdateFormatter`, `RrdtoolInspector`, `RrdtoolUpdater`, `RrdtoolProcess`, `ConfiguredRootTargetLocator`. Tested with real RRDtool files in a temporary directory; wired as the `SampleSink` with a closed gate and unconfigured storage by default; no production caller |
| Clock adapters: `Telemetry\Infrastructure\Clock\SymfonyClock`, `Graphing\Infrastructure\Clock\SymfonyClock` | Scaffolding: each implements its module's `Clock` port over Symfony's `ClockInterface` and returns UTC with microseconds; tested with `MockClock` in `SymfonyClockTest`; wired to the `Clock` ports in `config/services.yaml`, while unit tests still use `FixedClock` |
| Audit event for plan approval, activation and binding approval | Future |
| Capability reconciliation and caching | Reconciliation on rediscovery implemented in `DiscoverDeviceCapabilities`; time-to-live caching and operator overrides future |
| Resource enumeration (interfaces and other pack resources) | Future; the planner takes resources as input |
| Production wiring of the sink: `local_data_id`-to-file adapter, configuration | Future |
| Durable storage for plans, packs and capabilities | Future |
| IdentityAccess authorizers for plan activation and binding approval | Future |
| Creation of RRD files for new series | Future |
| Poller handoff of a legacy field | Future; proposal only |
| Protocol libraries (php-redfish, php-gnmi, php-yang, php-netconf, php-restconf, php-openconfig) | Future; packages do not exist |
| Protocol adapters and any collector | Future |
| YANG compilation (libyang assessment) | Future |
| Alerting and graph definitions from packs | Future |
| Legacy template parity and migration path | Future |

## Known limitations

- A sample whose collection protocol differs from its binding's protocol
  resolves to no binding. Moving a series from one protocol to another needs a
  new binding.
- There is no adapter from a `local_data_id` to an RRD file. The sink is wired
  but its storage is unconfigured by default. `ConfiguredRootTargetLocator` takes an explicit map of relative paths
  and a root; nothing builds that map from the legacy tables.
- Plans, packs and capabilities are held in memory and are lost on restart.
  Bindings and ownership claims are durable, but nothing releases or hands over
  a claim.
- Two registrations of different keys for the same storage field can race: the
  one-series-per-field check is in `RegisterBinding`, and no unique key covers
  the field because a rejected binding may share it.
- The database store tests and the upgrade test skip without
  `KADUPUL_TEST_MYSQL_DSN`. CI runs them on MariaDB 10.11 and MySQL 8.4.
- There is no IdentityAccess authorizer, so binding approval and plan
  activation are denied outside tests. Without approval no binding resolves.
- Legacy discovery is tested on SQLite only, not MariaDB or MySQL, covers
  interface identity fields and six reviewed field names, and is wired to the
  web connection but has no caller. The wiring test does not open a connection.
- An existing legacy field cannot be claimed, so nothing legacy can be moved to
  model-driven collection today.
- The write path updates existing files only. It does not create them.
- The path check and the RRDtool call are separate steps (see
  [safe RRD integration](#safe-rrd-integration)).
- Partial-write reporting is tested with a fake updater and not by injecting a
  failure into a real multi-row RRDtool call. Real-RRDtool tests ran against one
  RRDtool build.
- A 32-bit counter whose width is declared neither on the sample nor in the
  policy is reported as a reset when it wraps (see
  [counter decreases](#counter-decreases)).
- No protocol client or collector exists, so no real device has been polled.
  Samples in the workflow tests are simulated.
- The Semgrep PHP parser cannot parse PHP 8.1 and later syntax such as `readonly`
  classes and promoted properties, which this code uses throughout. Security scan
  coverage of these modules is therefore partial. An explicit run on
  2026-10-23 (Semgrep 1.152.0, rule sets `p/php` and `p/security-audit`) over
  `src/Telemetry`, `src/ModelRegistry`, `src/Monitoring`,
  `src/Graphing/Application` and the new `src/Graphing/Infrastructure/Rrd`
  classes reported 0 findings across 188 files, with 106 of those files
  partially parsed. Zero findings is not a clean result for the unparsed
  regions. A manual search of the same code found no `exec`-family calls,
  `eval`, `unserialize` or backticks; the only process launch was the
  argument-array `proc_open` in `RrdtoolProcess`, which now uses Symfony Process
  with an argument array instead. The Semgrep run predates that change.

End-to-end evidence with fakes only: `ModelDrivenMonitoringWorkflowTest` runs
discovery, the registry check, the shipped example pack, planning, plan
serialization and Telemetry normalization of simulated gNMI and SNMP responses,
including a counter above `PHP_INT_MAX` and a 32-bit rollover.

`ModelDrivenRrdWriteWorkflowTest` covers the write half with real code and a real
RRD file: a simulated gNMI notification becomes a `Sample`, `ResolveBinding` and
`WriterOwnershipRegistry` resolve it, and `RecordSamples` stores a 64-bit counter
above 2^53 exactly in the claimed field while a legacy-owned field in the same
file is refused and left untouched. A second test sends one batch for two devices
into their own files and refuses a reading whose protocol does not match its
binding. Only the notification-to-sample translation
lives in the test. One test in `ModelDrivenMonitoringWorkflowTest` stays
skipped: `testGivenAnApprovedBindingWhenProductionWritesAreEnabledThenSamplesReachLegacyRrdStorage`
names the remaining gap, production wiring and a `local_data_id` to file
adapter.

Test run on 2026-10-23 with PHP 8.4 through `mise`, per namespace filter: Graphing
134 tests, 388 assertions, none skipped (the RRDtool-backed tests ran); Monitoring
213 tests, 804 assertions, 1 skipped (the test named above); Telemetry 137 tests,
277 assertions, none skipped. These counts are a snapshot, not a CI gate.

Schema validation evidence: the three example documents were validated against
their schemas with the Python `jsonschema` package, draft 2020-12, with format
checking enabled, and a set of invalid variants (unknown field, counter width on
a gauge, malformed metric id, a secret field in an endpoint, missing gNMI paths,
`on_change` with an interval, an unknown status) were rejected. This was run once
by the author of this document and is not part of CI.

The PHP suite now repeats this when Python's `jsonschema` is importable
(`python3 -I`), and skips with a message when it is not: `PackSchemaParityTest`
checks that `jsonschema` and `PackValidator` agree on the example and on every
invalid case in `PackValidatorTest`, and `PlanSerializerTest` validates real
planner output and the plan examples. One known difference: Python's `re`
lets `$` match before a final newline, so `jsonschema` accepts a pack name
ending in `\n`; `PackValidator` follows ECMA-262 and rejects it.

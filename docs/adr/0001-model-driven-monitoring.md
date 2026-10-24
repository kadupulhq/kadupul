# 0001. Model-driven monitoring as a core capability

## Status

Proposed.

## Context

Monitoring in the legacy application is organised around SNMP. Device
templates, data queries and graph templates assume an OID and an index. Devices
now expose structured data over gNMI, NETCONF, RESTCONF and Redfish, described
by YANG, OpenConfig and Redfish schemas. Adding each protocol as another special
case in the poller would repeat the SNMP coupling and leave every metric
defined once per protocol.

## Decision

Kadupul treats a device's data models as input to monitoring. A device
advertises capabilities (models and revisions). Kadupul records what is
advertised and what has been verified, selects a monitoring pack whose required
capabilities are satisfied, and derives a collection plan from the pack. Metric
identity (for example `interface.in_octets`) is independent of the protocol
that supplies it.

The work is split into modules with separate ownership: ModelRegistry (known
models and per-device associations), Monitoring (capability discovery, packs,
planning), and Telemetry (normalised samples). Inventory continues to own
device identity and desired configuration.

## Consequences

- One metric definition can be served by SNMP on one device and gNMI on
  another, and graphs and alerts refer to the metric id only.
- Capability state needs a lifecycle (advertised, verified, partial,
  unsupported, unknown) and reconciliation, which the SNMP-only model never
  required.
- New modules and a pack format are new surface to maintain and version.
- Nothing in the legacy poller, RRDtool storage or Spine changes in the first
  milestones. Feeding model-driven samples into storage is later work.

## Alternatives

- Add protocols to the legacy poller one at a time. Rejected because metric
  definitions would stay protocol specific.
- Adopt an external telemetry stack as the collection layer. Not chosen for
  now: it would replace storage and graphing decisions that are outside this
  change. It remains possible behind the collector contract in
  [0005](0005-php-control-plane-independent-collectors.md).

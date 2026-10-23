# Architecture decision records

An architecture decision record (ADR) states one decision, why it was taken and
what it costs. Records are short and are not edited after acceptance except to
change their status; a reversed decision gets a new record that supersedes the
old one.

No ADRs existed before this directory. Earlier decisions are described in
[the architecture document](../architecture.md) and the
[Symfony migration notes](../symfony-migration.md) and are not back-filled here.
Numbering starts at 0001.

## Format

Each record has these sections, in this order.

- Status: proposed, accepted, deprecated or superseded by NNNN.
- Context: the forces and constraints that make a decision necessary.
- Decision: what was decided, in the active voice.
- Consequences: what becomes easier, what becomes harder, what is left open.
- Alternatives: options considered and why they were not chosen.

Files are named `NNNN-short-title.md`. Add a row to the index when adding a record.

## Index

| Record | Title | Status |
| --- | --- | --- |
| [0001](0001-model-driven-monitoring.md) | Model-driven monitoring as a core capability | Proposed |
| [0002](0002-monitoring-packs-and-legacy-templates.md) | Monitoring packs and legacy templates coexist | Proposed |
| [0003](0003-standalone-protocol-libraries.md) | Standalone protocol libraries | Proposed |
| [0004](0004-common-telemetry-contracts.md) | Common telemetry contracts | Proposed |
| [0005](0005-php-control-plane-independent-collectors.md) | PHP control plane and independent collectors | Proposed |
| [0006](0006-yang-processing-strategy.md) | YANG processing strategy | Proposed |
| [0007](0007-raw-samples-and-rrd-sink-semantics.md) | Raw samples and RRD sink semantics | Proposed |
| [0008](0008-data-source-ownership-and-bindings.md) | Data-source ownership and bindings | Proposed |

The design these records support is in [model-driven monitoring](../model-driven-monitoring.md).

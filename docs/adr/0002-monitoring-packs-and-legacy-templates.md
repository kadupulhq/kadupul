# 0002. Monitoring packs and legacy templates coexist

## Status

Proposed.

## Context

Installations depend on device templates, data queries and graph templates.
They are imported, exported, edited by operators and used by plugins. A new
declarative format could be presented as their replacement, but the legacy
artifacts carry behaviour (data query indexing, RRD data source profiles,
plugin hooks) that a first-version pack does not reproduce.

## Decision

A monitoring pack is a separate artifact with its own schema
(`docs/schemas/monitoring-pack.schema.json`). Packs and legacy templates
coexist. Packs do not read, write or migrate legacy templates, and legacy
templates are not generated from packs. A pack may name the legacy graph
templates and data queries it corresponds to, as information for operators.

No legacy template is deprecated or removed until both conditions hold:
documented parity for the metrics and graphs it provides, and a tested
migration path for existing installations. Neither exists today.

## Consequences

- Existing installations are unaffected by the introduction of packs.
- A device can be covered by both mechanisms. Operators must avoid collecting
  the same metric twice; the planner should report a legacy overlap rather than
  silently resolving it. The planner reports overlaps against what an
  `ExistingMonitoring` port returns; no adapter reads the legacy tables yet.
- Two definition formats are maintained for an unspecified period.

## Alternatives

- Generate legacy templates from packs. Rejected: it ties pack features to
  what the template format can express and writes into legacy tables from a new
  module.
- Replace templates outright. Rejected until parity and migration exist.

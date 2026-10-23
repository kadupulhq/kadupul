# 0005. PHP control plane and independent collectors

## Status

Proposed.

## Context

Long-lived streaming (gNMI subscriptions) and high-volume polling do not fit
PHP request lifecycles well. A future collector may be written in another
language. The control plane (inventory, capability state, pack selection,
authorization) is already PHP and Symfony.

## Decision

The control plane stays PHP. It produces versioned collection plans in a
language-neutral JSON contract
(`docs/schemas/collection-plan.schema.json`). A collector is any process that
reads a plan, collects, and reports samples through the Telemetry contract.
The plan carries credential references only; the collector resolves them from
its own secret store.

The contract is the interface, not the implementation. No Rust code is
introduced now and no collector process is built in this change. The format is
kept Rust-ready by using only JSON types, explicit versions, closed objects and
opaque identifiers.

Version 2 of the plan (`docs/schemas/collection-plan.v2.schema.json`) adds an
optional logical binding on a metric: an opaque id and the resource, with no
storage detail. A plan with no binding is still written as version 1. See
[0008](0008-data-source-ownership-and-bindings.md).

Compatibility rules are in the schema: readers reject unknown fields unless the
key starts with `x-`, and a breaking change bumps `plan_schema_version`.

## Consequences

- Collectors can be replaced or scaled without changing planning.
- The plan contract becomes a public interface with a compatibility burden.
- Until a collector exists, plans are generated and stored but nothing acts on
  them. The status table in the design document states this.
- A Rust (or other) collector is a later decision, to be justified by measured
  need.

## Alternatives

- Collect inside PHP workers only. Not rejected for polling; it is the likely
  first collector. It cannot be the only option for streaming.
- Define the contract as PHP classes. Rejected: ties collectors to PHP.
- Introduce Rust now. Rejected: no demonstrated requirement.

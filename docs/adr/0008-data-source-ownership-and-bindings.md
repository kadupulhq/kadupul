# 0008. Data-source ownership and bindings

## Status

Proposed.

## Context

Model-driven metrics need a place to be stored, and much of that place already
exists: legacy data sources whose RRD files hold one or several named fields. A
guess about which field a metric belongs to would write to the wrong graph. A
second writer on a field the legacy poller updates would corrupt or reject
rows, since RRDtool refuses a row at or before the last update.

Legacy data in the tables is partly unreliable as an identity. An interface name
can be reported by two indexes, a device can be reindexed, and a multi-output
script writes every field of its file through a single `poller_item` row.

## Decision

A metric binding (`MetricBinding`) ties one logical series to one storage field.

- The identity key is device id, metric id, resource (kind and identity) and
  protocol. The storage field is a local data id plus a data-source name, because
  a file can hold several fields.
- One key has at most one live binding, and one storage field holds one series.
  Registering a different definition for a known key, or a second key for a
  field already bound, is rejected. Identical registration is a no-op.
- A binding carries the pack name and version, the expected data-source type
  (GAUGE, COUNTER, DERIVE or ABSOLUTE) and provenance: origin
  (`model_driven`, `legacy_discovered` or `operator`), a source detail and a time.
- Status moves proposed to approved or rejected, approved to verified, stale or
  rejected, verified to stale, stale to rejected. Rejected is final. Only
  approved and verified resolve for storage. Registration is always `proposed`.
- Approval needs an authorized actor through `BindingApprovalAuthorizer`,
  checked before the store is read. With no authorizer, approval is denied.

Binding discovery from legacy data is read-only and conservative.
`LegacyDataSourceDiscovery` issues `SELECT` statements with bound values.
`LegacyExistingMonitoring` classifies a match as confirmed, ambiguous, heuristic
or none from two kinds of evidence, resource and metric, each strong or weak.
Only a confirmed match, with one candidate and both kinds strong, can become a
proposed binding. Ambiguous and heuristic matches are reported to an operator.

Writer ownership (`WriterOwnership`) is `legacy_poller`, `model_driven`,
`unassigned` or `conflict`.

- Every field defined in `data_template_rrd` is `legacy_poller`, with or without
  a current `poller_item` row.
- A model-driven claim is made only by an explicit call and is refused for a
  field legacy or conflict holds. A legacy writer that appears after a claim
  turns the field into `conflict`.
- Exactly one writer is authoritative for a field. There is no automatic handoff
  in either direction, and approving a binding does not change ownership.
- Resolution reports ownership as found and cannot change it. A writer must
  check that it is `model_driven`.

A collection plan refers to a binding only logically. Plan version 2 adds an
optional `binding` with an opaque id and the resource. The id derives from the
identity key and not from storage, so it survives a storage change. A plan has no
path, RRD or data-source name, local data id or SQL, and the schema refuses key
names that suggest them. `StorageReference` is an opaque token such as
`local-data:12`, turned into a file only by an infrastructure adapter. A plan
with no binding stays version 1, and a plan with any binding is version 2.

Moving a field from the legacy poller to model-driven collection is not part of
this decision. The proposal is in the
[design document](../model-driven-monitoring.md#future-poller-handoff) and needs
its own record.

## Consequences

- The default is safe: existing graphs stay with the legacy poller, and nothing
  model-driven can write to them until a change that is not designed yet.
- It also means no existing legacy field can be claimed today, so the first
  model-driven writes can only go to fields the legacy tables do not define, and
  the write path does not create files.
- A collector can be written in any language without learning where data is
  stored, and storage can move without changing plans.
- Bindings and claims are in memory. A durable store needs a schema and an
  upgrade path, which this work does not add.
- Approval is decided by the `metric_binding` workflow guard through
  IdentityAccess's `BindingApprovalPermission`, currently realm 15, and is denied
  to everyone else. The RRD write gate is still closed, and approval does not
  change writer ownership.
- Discovery is tested on SQLite only, and its field-name semantics cover six
  reviewed interface names.
- The `Contract` enums mirror the domain enums and are kept equal by a test.

## Alternatives

- Match legacy fields by name alone. Rejected: duplicate and reindexed names would
  bind the wrong field.
- Bind automatically on a heuristic match. Rejected: a wrong binding writes to the
  wrong graph; an operator decides.
- Claim fields automatically when the poller looks idle. Rejected: a missing
  `poller_item` row is often a cache waiting to be rebuilt.
- Allow both writers and let RRDtool order the rows. Rejected: neither writer
  could know which value is authoritative.
- Put the storage path or data-source name in the plan. Rejected: it couples
  collectors to Kadupul's storage layout and leaks internals.

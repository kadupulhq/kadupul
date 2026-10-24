# 0009. Durable binding and ownership store

## Status

Proposed.

## Context

[0008](0008-data-source-ownership-and-bindings.md) left metric bindings and
writer ownership claims in memory, so they were lost at the end of every
request. Keeping them needs tables, and Kadupul inherits strict schema
discipline: every table is created by `cacti.sql`, listed in the audit
baseline and reachable through a versioned upgrade step. Version 1.2.35 shipped
in `v1.3.0-alpha.1` and the 1.2.36 step moves the stock data input methods, so
these tables take a new version.

Several processes can register, approve or claim at once. An in-memory store
never saw that; a database store does. The database also compares strings by
collation, while RRDtool data-source names and device resource names are case
sensitive.

## Decision

- Two additive tables, `kadupul_metric_binding` and
  `kadupul_writer_ownership`, InnoDB with `utf8mb4_unicode_ci`. Nothing else in
  the schema changes and no rows are copied from legacy tables. They are
  created by `cacti.sql`, listed in `docs/audit_schema.sql`, and added by the
  1.2.37 upgrade step, which confirms the engine, collation, columns and
  primary key before the runner records the version.
- The binding primary key is the binding key: device id, metric, resource kind,
  resource identity and protocol. Pack name and version are stored as part of
  the definition, not the key, as 0008 defines it. The ownership primary key is
  the storage field: local data id and data-source name.
- Resource identity and data-source name are `varbinary`, so both keys compare
  exact bytes and survive a later collation conversion.
- A binding is written inside a transaction that locks its row and refuses a
  write that does not follow from the stored binding
  (`MetricBinding::assertMayReplace`): a stale copy cannot undo a rejection, and
  a new definition replaces only a rejected binding. The in-memory store applies
  the same rule.
- A claim is an insert. The primary key decides between two writers: one
  commits, the other sees a duplicate key and reports the field as already
  claimed. No row means no claim, so the legacy answer stands, which keeps
  `legacy_poller` the default for existing data sources. Nothing deletes a
  claim.
- Both stores use the `main` connection. Bindings are central configuration, and
  the `web` connection may use read-only credentials. An offline collector has
  no main connection and fails rather than keeping a private copy.
- Values longer than a column are refused before the write, because a session
  without strict mode would truncate them. A row that no longer satisfies the
  domain rules is reported, not repaired.

## Consequences

- Bindings and claims survive restarts, and concurrent writers cannot create two
  rows for one key or field.
- The schema version moves to 1.2.37. Native probes that named 1.2.36 as the
  final version, or 1.2.35 as the last intermediate one, were updated.
- Two registrations of different keys for one storage field can still race: the
  check is in `RegisterBinding`, and no unique key covers the field because a
  rejected binding may share it.
- Releasing or handing over a claim is not implemented.
- Plans, packs and capabilities remain in memory.

## Alternatives

- Include pack name and version in the binding key. Rejected: 0008 allows one
  binding per key, and a second pack binding the same series is the duplicate
  `RegisterBinding` refuses.
- A hash of the key as the primary key. Rejected: it hides the identity from an
  operator reading the table and adds a collision case to handle.
- A binary collation on character columns. Rejected: no table uses one, and the
  conversion command rewrites table collations.
- Reuse the 1.2.35 step. Rejected: 1.2.35 is released, and installations already
  at it would never run the new tables.
- Store on the `web` connection. Rejected: it may be read-only.

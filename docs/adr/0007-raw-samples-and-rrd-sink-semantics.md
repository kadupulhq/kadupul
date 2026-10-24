# 0007. Raw samples and RRD sink semantics

## Status

Proposed.

## Context

Telemetry can describe a counter two ways: as the cumulative reading a device
reports, or as the difference between two readings
(`CounterInterval`, from `CounterNormalizer`). RRDtool COUNTER and DERIVE data
sources compute rates themselves from cumulative readings and apply their own
rollover and range rules. A value that has already been differenced and then
written to such a source is differenced again.

PHP floats hold 53 bits of integer precision, so a conversion between a device
counter and an RRD update string through a float corrupts values above 2^53.
RRDtool stores the rates it computes as doubles, so exactness can only hold for
the input it accepts and the last reading it keeps.

The existing graphs are written by the legacy poller. A new writer must not be
able to start by accident.

## Decision

Storage receives raw samples. `Sample` carries a `SampleValue`, with one type per
data-source family: `CounterValue` (COUNTER), `AbsoluteValue` (ABSOLUTE),
`DeriveValue` (DERIVE), `GaugeValue` (GAUGE) and `UnknownValue`. Each keeps its
decimal text and renders it with `toWire()`. None converts through a float.
`CounterInterval` stays for analytics and alerting and is not used for storage.

Samples reach storage through the `SampleSink` contract in Telemetry. A sink
returns a `SinkReport` with one `SampleOutcome` per input sample in input order.
An outcome is accepted, or carries a stable `RejectionReason` and a one-line
detail that may not contain a path, SQL or a line break. A sink never drops a
sample silently.

The RRD implementation (`RecordSamples` in Graphing) follows these rules.

- A write gate (`WriteGate`) is closed by default and is checked before any
  binding is read.
- A sample is written only if its binding resolves for exactly that series, the
  field is owned by model-driven collection, and the file holds the data source
  with the expected type.
- Values are validated for their data-source type and written exactly as
  `toWire()` gives them. An unknown reading is `U`, never zero.
- Timestamps must be after the epoch and at most 300 seconds ahead of an
  injected clock. A second at or before the file's last update is refused as a
  duplicate or out of order. Two readings of one data source in one second are a
  duplicate, because rows have one-second resolution.
- RRDtool runs as an argument vector through Symfony Process, from a configured
  binary, with a deadline. Symfony Process retries a failed spawn through
  `/bin/sh` with each argument single-quoted; the binary is checked just before
  starting so only a binary removed in that interval reaches that path. A
  template or row that begins with `-` is refused.
- Files are located under one configured root with no default, symlinks are
  resolved, and the resolved path is the one passed to RRDtool.
- After a failed multi-row call the file is read again, and rows RRDtool already
  applied are reported stored. Later calls to that file are not attempted.

Unsupported data-source types are COMPUTE, DCOUNTER and DDERIVE.

## Consequences

- RRDtool, not Kadupul, decides how a counter becomes a rate, so existing
  graphs, consolidation and the RRDtool documentation keep their meaning.
- A second differencing step cannot be introduced by mistake, because the sink
  accepts no interval type.
- Tests with real RRDtool files show that a 64-bit counter pair near 2^64 is
  accepted exactly and yields the expected rate, that `U` is stored, and that a
  capped counter drop is stored as `nan` and not as a negative rate. They show
  accepted raw input for GAUGE, DERIVE and ABSOLUTE but not the stored rates for
  those three. They ran against one RRDtool build.
- The path check and the RRDtool call are separate, so a path component swapped
  for a symlink between them is not caught. The root must be writable only by the
  collector account.
- Partial-write reporting is tested with a fake updater only.
- Each sample carries its device id and collection protocol, and the sink
  resolves bindings by those, so one sink serves many devices.
- Nothing calls the sink in production and it does not create files.

## Alternatives

- Write calculated deltas into a GAUGE. Rejected: it drops RRDtool's rate and
  consolidation handling and changes the meaning of existing graphs.
- Convert readings through floats. Rejected: precision loss above 2^53.
- Open the gate by default. Rejected: wiring the sink in would start writing to
  existing graphs.
- Shell out to rrdtool with a command string or use the legacy `lib/rrd.php`
  helpers. Rejected: quoting risk, and the legacy bootstrap and settings would
  become part of the write path.
- Let each sink decide silently what to drop. Rejected: a caller could not tell
  stored samples from lost ones.

# 0004. Common telemetry contracts

## Status

Proposed.

## Context

Every protocol reports values differently: SNMP Counter32 and Counter64,
gNMI typed values, Redfish JSON numbers. PHP integers are signed 64-bit and
PHP floats hold 53 bits of integer precision, so an unsigned 64-bit octet
counter above 2^53 loses precision as a float and overflows as an int.

## Decision

The Telemetry module publishes a protocol-neutral contract: MetricId,
ResourceId, MetricType (counter or gauge), Unit, Sample, DataQuality
(good, stale, estimated, bad), CollectionStatus and CollectionError.

A `Sample` names the device it was collected from (a positive integer id) and
its `CollectionProtocol` (snmp, gnmi, redfish, netconf or restconf, the same
names a collection plan target uses). Storage bindings are selected by device
and protocol. `Sample::source` is free provenance text and is never read as a
protocol.
`CounterNormalizer` treats device, protocol, metric, resource and labels as
one counter series: readings from two devices that share a resource name, or
from two protocols on one device, are never differenced against each other.

Counter values are exact unsigned 64-bit integers carried as decimal strings
and manipulated with GMP. They are never cast to float. Serialised form is a
decimal string in JSON.

Rollover and reset semantics:

- A counter declares its width (32 or 64 bits) where known, on the sample or
  per metric in the normalization policy.
- A decrease on a counter declared 32 bits wide, with both values inside 32
  bits, is a wrap: the delta is `new + 2^32 - previous` and the interval is
  marked `rolledOver`.
- A decrease on a counter declared 64 bits wide, or of unknown width, is a reset:
  no delta, never negative and never `2^64 - x`. A real 64-bit wrap takes decades
  at line rate, so a decrease there is far more likely a restart or a counter
  cleared on the device.
- A sample flagged `deviceRestarted` makes the interval a reset even when the
  value rose. That marker wins over a gap, and a gap wins over a decrease.
- The classification is recorded on the `CounterInterval`, not on the sample.
- A first sample has no delta and is never reported as zero.
- Unknown, missing and non-finite values produce a sample with quality bad or
  no sample; they are never converted to zero.

Gauges may be float or integer; the unit is part of the contract.

Raw values and intervals are separate. A `Sample` carries a `SampleValue`
(`CounterValue`, `AbsoluteValue`, `DeriveValue`, `GaugeValue` or `UnknownValue`)
that is the cumulative or instantaneous reading as reported. `CounterNormalizer`
turns a series of counter samples into `CounterInterval` values for analytics.
Storage takes the raw values; see
[0007](0007-raw-samples-and-rrd-sink-semantics.md).

## Decision on a 64-bit decrease (2026-10-23)

An earlier version of `CounterNormalizer` treated a decrease on a declared
64-bit counter as a wrap. On 2026-10-23 the rule above was adopted and the
normalizer changed to match it; `CounterNormalizerTest` and
`ProtocolEquivalenceTest` cover each case. RRD storage is unaffected, because
it receives raw values and leaves rollover to RRDtool.

## Consequences

- Exact counters at the cost of GMP (or equivalent) being required at runtime,
  and slower arithmetic than native ints.
- Consumers that need floats (graphing) convert at their own boundary and
  accept the precision loss there.
- The 64-bit decrease rule can misclassify a wrap as a reset; this is accepted
  and visible as a reset interval. A 32-bit counter with no declared width is
  also reported as a reset on wrap, so collectors must declare the width.

## Alternatives

- Use PHP floats. Rejected: silent precision loss above 2^53.
- Use signed ints. Rejected: values above 2^63 overflow.
- Defer to the storage layer. Rejected: RRDtool handles rollover for its own
  data sources but not for values that never reach it.

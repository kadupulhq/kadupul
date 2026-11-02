# Aggregate total legends

Issue #1111 affects `main`: aggregate generation applies its total CDEF to lines
but excludes GPRINT and expanded legend items. The line therefore shows the sum
while its average, maximum or VDEF percentile label can describe one member.
LTS already includes these items and needs no implementation change.

The correction removes the graph-type exclusion. The existing graph ID and first
total sequence still bound the selection, so member rows and other graphs retain
their CDEFs. VDEF IDs remain unchanged: the renderer already evaluates a VDEF on
its item's selected CDEF. This does not establish the broader remapping hypothesis
in #1115. The existing renderer guard against empty CDEF expressions remains.

`AggregateTotalLegendNativeTest` executes the production total-selection function
and checked PDO query helpers against isolated SQLite tables containing the query's
real column names. It passes persisted CDEF IDs/text into the complete production
graph builder. Request, graph database and configuration records are fixtures.
This does not exercise MySQL transaction ownership, the installed application or
a browser. No dependencies or external services are installed for the test.

PHP 8.4.25 passes ten cases and 70 assertions. Eight regression cases fail before
the correction; empty and unrelated selections pass. Four real RRDtool 1.11.0
`graphv` scenarios use opposite member samples whose sum is always 21.00. Both
similar-source and all-source totals render average and 95th-percentile labels
of 21.00. Original labels report 10.50 and 20.00 respectively. A MAX legend also
retains the existing fallback to AVERAGE when the RRD has only an AVERAGE archive.
Another 38 graph-characterization cases and 322 assertions pass with native
RRDtool explicitly selected. Unchanged LTS passes four row-selection scenarios.

After deployment, new and normally regenerated aggregates use the correction.
Existing derived item rows are not rewritten automatically; a normal aggregate
or template update that regenerates items is needed. An unchanged save does not
trigger regeneration. No schema, runtime, dependency, translated text or stored
RRD samples change. Reverting restores the former generation behavior, but does
not undo rows already regenerated. There is no release or deployment claim.

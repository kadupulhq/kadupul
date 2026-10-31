# Realtime script-server batching

Issue #1114 is a correctness and collection-efficiency defect on LTS and main.
`remote_agent.php::poll_for_data()` now owns at most one script server per request.
It validates the selected IDs before starting a child, starts lazily for the first
supported PHP command, preserves item order and values, and closes its own pipes
and process in `finally`. Unused per-source action-count queries are removed.
Authorization, SNMP handling, ordinary scripts, numeric ID grammar and JSON fields
retain their existing contracts. Main retains its collector ownership gate and
SNMP session adapter; LTS retains its existing local-host gate.

The configured PHP executable is passed as an argument array. Standard error goes
to the platform null device, preserving the former discarded diagnostics without
letting a shared stderr pipe fill. Failed startup or worker death yields `U` for
remaining PHP items without repeated starts. Empty, multiline, over-1023-byte and
reserved lowercase `quit`-prefix commands yield `U` instead of contaminating the
next response. These commands are outside the shipped line protocol. Valid zero,
multi-value results, and the existing maximum command length are retained.

## Verification

The native regression executes the actual polling function, result normalization,
ID validator and script-server I/O helper extracted through `PhpSource`. Database,
request and configuration adapters are fixtures. A real PHP child implements the
existing greeting and line protocol; this is not an installed collector bootstrap.
Both original implementations fail three of the four core cases; the empty
control passes. Twenty items originally start twenty children. The correction
starts one child and removes the redundant count query: twenty selected sources
perform twenty item queries rather than forty combined queries.

The focused suite passes 28 cases and 118 assertions on LTS/PHP 8.1.34 and
main/PHP 8.4.25. It covers startup refusal, worker exit, malformed commands,
selection validation, denied hosts, later database exceptions, stderr pressure,
paths containing spaces, and positive-short/failed shutdown writes. Main also
passes 36 adjacent remote-agent scope cases and six startup-helper cases.
The main entry inventory still classifies all 419 entries. Both sink inventories
and LTS architecture checks pass. Main retains the unrelated existing Windows
ping architecture signature mismatch; its baseline is not expanded.

No schema, configuration, dependency or runtime change is required. Both branches
register the new suite in their existing CI. No translatable text or source
references changed in the polling function; all 23 compiled catalogs per branch
remain byte-identical. Readiness remains `implemented` pending hosted CI and review.

## Deployment and recovery

Deploy the normal application update after branch checks and review. No data
migration is necessary. Reverting the fix restores per-item child creation and
redundant queries without changing stored samples. The existing blocking worker
I/O and script execution deadlines are outside this correction; it does not
establish hung-script recovery, hostile-plugin isolation, device interoperability
or a production capacity/latency budget. No release or deployment is claimed.

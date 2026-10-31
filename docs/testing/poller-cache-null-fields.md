# Optional SNMP defaults in poller cache entries

This correctness fix affects `lts/1.2` and `main`. The shipped `host` schema permits
NULL in six SNMP text fields for which `poller_item` requires strings. The shared
`api_poller_cache_item_add()` producer previously emitted SQL NULL for those
values. A cache rebuild under strict SQL then failed with a NOT NULL violation.

The producer now serializes those NULL values as empty strings after applying
host-field overrides. It preserves non-null values, including `"0"`, and does
not change stored host settings. Nullable cache context and engine-ID fields retain
their existing NULL values. Disabled hosts and invalid SNMP configurations
still omit their cache item. The public signature, SQL tuple format, schema and
runtime requirements remain unchanged.

Non-device script entries retain numeric zero for SNMP version, port and timeout,
rather than emitting empty strings into integer columns. This matches the values
previously stored by permissive SQL without running the script.

All four callers in `lib/utility.php` use this producer for script, scalar SNMP,
SNMP query and script-query cache entries. They receive the same tuple shape;
no caller exception or side-effect contract changes. No device, script, poller
or RRDtool execution is added.

Seven regression cases execute the extracted production function against the
shipped host and poller-item DDL on disposable MariaDB with STRICT_ALL_TABLES.
They cover stored NULLs, NULL overrides, retained values, disabled devices and
invalid SNMP versions or communities, and host-zero script cache entries. The two
NULL cases and the non-device numeric-default case fail before their corrections;
all seven pass afterward. RRD path/scheduling callbacks return fixed fixture values;
this regression does not claim installed authentication or remote propagation.
An additional generated-password account case verifies configured credentials are
used when no administrator override exists, and rejects a silent root fallback.
It starts from an inaccessible caller schema and verifies that only the newly
owned fixture schema is required.

LTS uses PHP 8.1.34; main uses PHP 8.4.25. Other engines await current-head CI.

Reverting restores the prior serialization and strict-SQL failure. Existing
settings and cached records are not migrated, so no data restoration is needed.
This fix does not make failed multi-collector operations distributed atomic.

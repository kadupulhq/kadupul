# Release preparation

The first release requires validation of device polling, collection, graphing,
installation, and reversible migration.

## Compatibility boundaries

Preserve plugin functions, hook names, constants, database tables, configuration
keys, and stored data formats. Any interface change requires an explicit migration
and compatibility evidence. Update product-facing text and assets independently.

## Migration

Validate database, RRD, configuration, and plugin migration in an isolated copy.
Keep backups and demonstrate rollback before recommending a production cutover.

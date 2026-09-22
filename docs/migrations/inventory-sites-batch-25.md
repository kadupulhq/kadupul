# Inventory Sites completion batch: 25 acceptance items

Scope: complete Sites within Inventory on main. These are 25 independently
reviewable acceptance items, not 25 separate screens or additional domain modules.
Device management, collector polling and LTS remain outside this batch; existing online collector Sites access is preserved. Sites stay part of
Inventory; Symfony owns routing/forms/rendering, application commands own operations,
and database access stays behind ports. Old URLs are compatibility entry points.

1. Reuse one set of site text and field invariants for creation and editing.
2. Include every persisted site setting in the optimistic revision.
3. Edit both address lines and city/state/postal code/country in Symfony.
4. Edit and validate the site's timezone through Symfony Forms.
5. Edit and validate latitude/longitude, including Unicode-safe input handling.
6. Edit map zoom and alternate name with schema/domain bounds.
7. Persist complete edits atomically with current authorization and revision checks.
8. Restore sorting by ID, accessible device count, city, state and country.
9. Add the bounded, duplicate-free `SiteSelection` domain contract (1–100 sites).
10. Authorize and load confirmation selections through `PrepareSiteAction`.
11. Add the `DeleteSites` application use case and persistence port.
12. Delete sites and unassign their active devices in one transaction.
13. Add the `DuplicateSites` application use case and persistence port.
14. Duplicate all site settings with a validated `<site>` name pattern; do not clone devices.
15. Validate the entire bulk snapshot before writes, acquiring site locks in ID order.
16. Commit both site cache invalidations with deletion or duplication; roll back on failure.
17. Add accessible list selection and bulk-action entry points.
18. Add single-site duplicate/delete navigation from the editor.
19. Add Symfony confirmation forms with POST, CSRF, escaped names and bounded IDs.
20. Preserve validated list navigation and provide translated confirmation/error messages.
21. Route legacy listing and edit/create bookmarks to Symfony, including online collectors and full-document menu navigation.
22. Replace legacy timezone lookup and reject stale legacy mutation submissions.
23. Delete the old procedural Sites implementation and prevent legacy bootstrap regression.
24. Verify domain, use-case, real database and both HTTP session backends; require measured coverage.
25. Verify offline assets/authorization, security inventories, migration documentation and PR metadata.

## Completion evidence

The code and tests implementing the acceptance items are in the accompanying PR.
Final command results and any pending CI/review are recorded in its validation
section. Do not equate an implemented checkbox with passed verification.

The retired `sites.php` implementation contained 3 `global` declarations importing
5 variables and 2 direct superglobal references. The compatibility entry has none;
no equivalent declarations were added under `src/Inventory`. Its diff removes
627 old lines and adds 18 bridge lines (609 fewer lines in that entry point).

## Compatibility decisions

- Old GET list/edit URLs redirect; old POST forms return 409 and must be reloaded.
  Serialized legacy selections are never accepted by the new handlers.
- List page sizes remain the Symfony list's bounded 25/50/100. Legacy `rows=-1`
  maps to the new default of 25; obsolete arbitrary page sizes are rejected.
- Device counts respect current visibility rather than exposing hidden devices.
- Deletion preserves legacy behavior for soft-deleted devices: only active devices
  are unassigned. Device records themselves are never deleted by a site operation.
- Duplication copies site settings, not devices or permission assignments. Duplicate
  names remain supported. Repeating a successful duplication POST can create more
  copies: no automatic retry or idempotency guarantee is introduced.
- Invalid historical site values may be displayed but must be corrected before a
  full save or duplication. Bulk deletion does not require valid editable fields.
- Site deletion does not introduce a new distributed collector synchronization
  protocol or foreign key into the legacy database.

Online collectors retain Sites administration using their configured primary
`rdatabase_*` connection. The local installation must be initialized and its boost
recovery queue empty. The primary must be reachable and initialized. There is no
fallback to editing the collector-local database. This exception is restricted to
Sites HTTP routes; a cached configuration cannot enable other routes or CLI workers.
Collector Sites pages continue linking to the existing local device management UI.

Review follow-up: legacy device saves now lock and recheck the selected site on
their existing database connection before writing the device. The lock lasts until
the owning transaction commits, so a device save waiting behind deletion cannot
restore a deleted site's association. A two-connection test drives the actual
legacy device creation command while deletion holds the site lock. Direct SQL
writes and third-party plugins that bypass the device API remain outside this
application locking protocol; no foreign key is added to the legacy schema.

The legacy SQL helper also stops retrying statements from a transaction after a
failure: a server deadlock may already have rolled back its locks. The transaction
owner must reject or roll back the operation. Standalone statement retries remain
supported. Failed `sql_save` operations now return `false`, including updates that
previously could return an existing/stale identifier. Regression evidence covers a
real constraint failure, unchanged successful saves, lost-transaction deadlocks,
and standalone retry compatibility; coverage publication requires this evidence.

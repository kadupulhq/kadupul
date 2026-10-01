# Data-source profile deletion

Bulk deletion locks and revalidates selected parent rows, then checks references
in one prepared locking query. It requests `REPEATABLE READ` before opening its
transaction. Failed isolation, transaction or guard verification prevents deletion.
Database guards reject a new nonzero reference after a waiting writer observes
that deletion committed; a range lock alone cannot protect that later write.

Fresh installations include the profile-reference index and eight database
guards. The registered `1_2_34.php` migration adds an absent index and installs
missing guards, validates every guard and requires all four profile/reference
catalogs to use InnoDB. Failed installation or verification stops the upgrade.

Canonical schema metadata and the installer registry advance to 1.2.34, so the
normal installer applies the migration to existing main 1.2.31, LTS 1.2.32 and
main 1.2.33 installations. The shared prerequisite migration 1.2.33 handles the
RRD input-field index and full user-ID settings schema. No forced version rerun
of the historical 1.2.31 upgrade is required. Native tests execute the actual
version-gated installer for all three starting versions and verify the installed
profile index and all eight guards before rerunning the migration idempotently.

Run the normal upgrade as the installation operator with TRIGGER privileges.
Upgrade collectors too: replication refuses missing or modified remote guards
before delivering references. See [reference integrity](../data-source-profile-integrity.md)
for account permissions, historical orphan/zero compatibility and backup rules.

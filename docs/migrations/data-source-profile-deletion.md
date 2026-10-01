# Data-source profile deletion

Bulk deletion rechecks profile references in one prepared, locking query. It
requests `REPEATABLE READ` before opening its transaction, so a server configured
with `READ COMMITTED` still prevents a new reference during the check/delete
window. If the isolation setting or transaction fails, nothing is deleted.

New installations include the `data_template_data.data_source_profile_id` index.
The existing `1_2_31.php` upgrade adds it when absent and leaves an existing index
unchanged. Installations upgrading from an older database version receive it
through the normal installer.

An installation already reporting database version 1.2.31 can apply the updated,
idempotent upgrade with the existing CLI option:

```sh
php cli/upgrade_database.php --forcever=1.2.30
```

Run this as the installation's normal database-upgrade operator. It reruns the
1.2.31 upgrade, including its existing column/index and poller rejection-table
changes; the profile index migration is also verified on a second invocation.
The index reduces the reference query to the selected profile ranges. Until it
is installed, deletion remains safe but the single usage scan can lock unrelated
references until the transaction completes.

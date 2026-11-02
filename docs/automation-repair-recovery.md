# Installer automation rule repair

On the 1.2 maintenance branch, installation repairs the shipped automation rules
after importing the selected templates. An optional template that is not selected
has no query or graph reference to publish. Its existing rule now remains unchanged,
and processing continues to other available templates. A later installer retry can
repair the rule once both references exist.

Database read and update failures stop installation through its existing error
handling. The repair is not a transaction spanning the template imports: earlier
successful work can remain. Correct the database problem and retry the installer;
do not treat a failed attempt as completed.

Back up the database before upgrading. This change adds no schema or runtime
requirement. Reverting the application files does not restore rule references
already overwritten by an older installer. For affected installations, import the
intended templates and retry the supported installer, or restore a verified backup
under the normal recovery procedure. Do not guess missing query identifiers.

The focused native regression imports the shipped table definitions and rule seed
into a disposable database, then exercises selected-template boundaries, later
retry, idempotence, an empty inventory, genuine database read errors, a rejected
write and a previously handled database error. It is registered in the existing
Boost database integration matrix. Local verification covered PHP 8.1.34 and
8.4.25 against MariaDB 10.11.19; the locked Pest 1 runner executes on PHP 8.1.
Its PHP 8.4 bootstrap currently stops on dependency deprecations, so PHP 8.4
evidence comes from executing the same native probe directly.

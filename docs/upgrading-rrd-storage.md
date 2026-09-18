# Upgrading RRD storage and the poller queue

The durable-queue changes require an explicit maintenance window. Deploying the
code alone does not convert an existing MEMORY queue. The installer and poller
preflight refuse an unsuitable queue so collection cannot silently continue on
volatile storage.

1. Stop scheduled and running collectors, including remote collectors, and stop
   web-triggered collection and maintenance. Back up the database, configuration,
   and RRD files together before changing either code or storage.
2. With collectors stopped, make the new CLI available and run
   `php cli/upgrade_database.php --migrate-poller-queue`. This converts the selected
   `poller_output` table to InnoDB without deleting its retained samples. The
   conversion may require extra database disk space and a table lock; allow the
   database operation to finish before restarting any producer.
3. Use the same queue destination as the collector. Online remote collectors
   produce into the main database; the command keeps that established target.
   Use `--local` only when intentionally checking or migrating a local queue.
4. Run `php cli/upgrade_database.php --check-rrd-storage` under every actual web
   and poller service account. Resolve storage ownership and trusted-user/group
   settings described in [the storage guide](testing/spikekill-safety.md) until
   every check passes. A successful check as root is not sufficient.
5. Complete the normal database upgrade, then restart collectors. Confirm that
   healthy samples drain and inspect logs for retained samples. Repair any RRD
   schema or storage failures before replaying their queued data.

Monitor database free space and retained queue depth throughout recovery. Samples
are not expired automatically: removing a queue backlog to make a check pass
would lose measurements. Preserve the pre-upgrade backup for rollback and stop
all writers before restoring its matching database, RRD files, configuration,
and code.

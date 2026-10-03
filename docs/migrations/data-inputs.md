# Data input methods

On `main`, `data_input.php` redirects to Symfony routes under `/app.php/data-inputs`.
Twig renders listing, method and field editors, duplicate/delete confirmations,
whitelist verification and retry actions. Old POST forms expire with HTTP 409.

Console realm 8 and Data Input realm 2 are required, including current enabled
group grants. The worker locks account, authentication policy and grants before
writes and requires primary InnoDB storage. A pending forced password change
blocks builtin-auth accounts only when password changes are enabled for that
account; external-auth accounts and builtin accounts without that permission
remain eligible when their account and realm checks pass.
Method revisions include every child field and sequence. Field URLs bind the
field to its actual parent. Referenced output fields cannot be removed or renamed.
Protected system methods remain hidden and unavailable for mutations.

Raw command definitions retain whitespace and placeholder syntax. Existing shell
metacharacter policy still applies. Input sequence follows command placeholders.
Legacy `data_input_sql_where` plugin restrictions run through the isolated worker.
List preferences are stored per actor; the shared authenticated session stays read only.

Method/field CRUD and replication CRC publication share a primary transaction.
Collector cache rebuilds and whitelist files are subsequent operations outside
that transaction. An incomplete handoff reports that local changes were saved,
offers a CSRF-protected retry and advises FullSync for unreachable collectors.
An unknown worker outcome requires reloading before retrying; do not assume rollback.
Whitelist update invokes `cli/input_whitelist.php --update --id=N` with an argv
array and verifies the stored command afterward. The worker then propagates the
method and rebuilds dependent collector caches once, reporting any failed handoff.
Diagnostic output is never rendered in HTTP responses.

Post-commit handoffs share a monotonic 160-second deadline measured from before
legacy bootstrap, below the gateway's 180-second timeout. Each whitelist or
collector child receives at most 30 seconds and the remaining shared budget,
with a drain margin reserved for stopping/reaping and returning the result.
Whitelist update omits `--push`; a separate collector leaf rechecks the original
actor's current grants and the method's availability using the same production
policy as the primary worker. It only rebuilds collector caches, and never
repeats the primary save, duplicate, delete, or replication-CRC publication.
The leaf's actor, target, nonce and phase must match its confirmation frame;
its database errors and session warnings mean an incomplete handoff.

Timeouts, revoked grants and budget exhaustion after a confirmed commit return
an explicit partial result with the committed IDs intact. Remaining phases are
skipped when the shared budget expires; there are no automatic retries. Reload
and use the existing CSRF-protected propagation/whitelist retry rather than
resubmitting a successful duplicate or save. A gateway timeout during the
primary transaction still has an unknown outcome and requires reloading before
retrying; post-commit supervision does not establish whether that commit occurred.

Verification: `mise exec php@8.4.25 -- php include/vendor/bin/phpunit -c phpunit-symfony.xml`
and `mise exec python@3.12.12 -- python tests/Symfony/data_input_review_http.py`.
The same scenarios run inside both session-handler coverage suites, with source
hashes checked by the coverage merger.

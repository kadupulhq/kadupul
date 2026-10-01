# Data input methods

On `main`, `data_input.php` redirects to Symfony routes under `/app.php/data-inputs`.
Twig renders listing, method and field editors, duplicate/delete confirmations,
whitelist verification and retry actions. Old POST forms expire with HTTP 409.

Console realm 8 and Data Input realm 2 are required, including current enabled
group grants. The worker locks account, authentication policy and grants before
writes, rejects forced-password accounts and requires primary InnoDB storage.
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
Whitelist update invokes `cli/input_whitelist.php --update --push --id=N` with an
argv array and verifies the stored command afterward. Diagnostic output is never
rendered in HTTP responses.

Verification: `mise exec php@8.4.25 -- php include/vendor/bin/phpunit -c phpunit-symfony.xml`
and `mise exec python@3.12.12 -- python tests/Symfony/data_input_review_http.py`.
The same scenarios run inside both session-handler coverage suites, with source
hashes checked by the coverage merger.

# External Links

`links.php` forwards to `/app.php/links/legacy`. The Navigation module owns the
Symfony forms, Twig presentation and primary database writes. Main requires
PHP 8.4.25 selected through `mise`.

## Compatibility

- Links retain the TAB, CONSOLE, FRONT and FRONTTOP styles, refresh choices,
  search, sorting, pagination and per-user filter preferences.
- Remote collectors render validated list filters without persisting preferences.
  Preference writes remain restricted to the primary collector.
- Create and edit append to the current maximum order, matching legacy save.
  Bulk deletion retains surviving order values. Adjacent moves normalize gaps
  and duplicates while preserving other links' relative order.
- Saving grants the acting account realm `id + 10000`; deleting removes both
  direct and group viewing grants in the same transaction.
- HTTP, HTTPS, FTP and FTPS URLs retain their original bytes. Installed content
  files must be ordinary files in `include/content`, excluding README,
  index.php and symlinks. File traversal and executable URL schemes are refused.
- A new console section is at most 20 characters. Existing sections may retain
  their legacy 50-character database values. The default stored section is
  `External Links`; presentation is translated independently.
- Old GET edit links redirect to the new editor. Old GET delete and move actions
  open confirmation forms. Old POST forms expire with HTTP 409 and are never
  replayed. Direct legacy deletion now uses the same confirmation and keeps
  surviving sort values, matching bulk deletion.

## Authorization and data handoff

Every route checks the console actor before parsing input and requires the
current External Links realm 15. Every mutation rechecks the account, policy,
console realm 8 and realm 15 under transaction locks, including enabled group
grants. Primary writes fail closed on nontransactional authorization or content
tables. Whole-list revisions include order and every saved link field; a stale
save, bulk action or move receives HTTP 409. No caller-owned transaction is
rolled back by the adapter. Confirmations and edits use Symfony stateless CSRF
validation and preserve raw data separately from escaped Twig output.

Run focused tests and the isolated HTTP scenario:

```sh
mise exec php@8.4.25 -- php include/vendor/bin/phpunit -c phpunit-symfony.xml --filter Link
mise exec python@3.12.12 -- python tests/Symfony/link_review_http.py
mise exec python@3.12.12 -- python tests/Symfony/link_review_http.py --database-sessions
```

The same scenarios run in both session bridge coverage suites. Source hashes and
required path checks reject stale or missing HTTP and shim measurements.

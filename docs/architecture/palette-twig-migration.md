# Color palette migration

The compatibility `color.php` entry point forwards to `/graphing/colors/legacy`.
GET links redirect to the Symfony list, editor, import or export. Old POST forms
return HTTP 409 and never replay their serialized selections.

The Graphing domain validates colors and CSV. Application ports authorize lists,
edits and deletion. Infrastructure uses the existing installation PDO database,
prepared statements and current authenticated identity. All routes require console
realm 8 and palette realm 5 through direct grants or enabled groups. Account locks,
disabled accounts and forced password changes deny access. Writes recheck grants
and policy under transaction locks, require primary collector and InnoDB tables,
and record an audit outcome without names or CSV payloads.

Names may be empty or contain at most 40 UTF-8 characters; their exact text is
stored and escaped by Twig. Hex accepts exactly 3 or 6 hexadecimal digits. Stored
`read_only=on` colors cannot be edited or deleted, regardless of submitted fields.
Graphs, graph templates and color-template item references block deletion.
Deletion binds the selected IDs and row revisions; import binds a complete ordered
palette snapshot so concurrent changes require a fresh form.

CSV accepts exactly `name` and `hex` headers in either order, RFC quoted commas,
quotes and newlines, at most 1 MiB and 5000 rows. Invalid fields, duplicate hex or
malformed quoting reject the entire file. Existing hex rows are skipped unless
updates are enabled; named colors always remain unchanged. A database failure
rolls back all rows. Export honors remembered search/named/usage filters and
exports every matching row, independent of pagination, using standard quoted CSV.
Values are literal data; spreadsheet formula interpretation is not enabled by the
application. New custom colors cannot be promoted to protected built-ins by a form.

Unit and actual kernel tests cover validation, revisions, dependencies, rollback,
account policy and all seven anonymous routes. Run real HTTP/MariaDB checks with:

```sh
mise exec python@3.12.12 -- python tests/Symfony/palette_color_review_http.py
```

The runner owns only Docker project `kadupul-palette-review` and cleans its volumes.

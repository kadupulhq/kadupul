# Graph item inputs in Symfony and Twig

This main-only slice moves the graph item input editor, `graph_templates_inputs.php`, into Symfony and Twig. It migrates the whole workflow: the create and edit form, its save, and removal. The page itself becomes a forwarding shim. Graph template editing, graph item editing (`graph_templates_items.php`) and the per-graph input values on `graphs.php` stay in PHP.

| Workflow | Route below `app.php` | Feature realm | Replaces |
| --- | --- | --- | --- |
| Create an input | `/graphing/graph-templates/{templateId}/inputs/new` | 10 | `?action=input_edit` with no `id` or `id=0` |
| Edit an input | `/graphing/graph-templates/{templateId}/inputs/{inputId}/edit` | 10 | `?action=input_edit&id=N` and `action=save` |
| Remove an input | `/graphing/graph-templates/{templateId}/inputs/{inputId}/delete` | 10 | `?action=input_remove&id=N` |
| Legacy URLs | `/graphing/graph-templates/inputs/legacy` | 10 | `graph_templates_inputs.php` through `LegacyPageForwarder` |

Graphing owns the workflow, as it owns graph templates. `GraphTemplateInputAccess` authenticates the console account and checks realm 10 before any template, input or item is read. The check is in each route, including the legacy route. Writes go through `AuditedPrimaryWrite`. They need the primary collector and InnoDB tables, and they recheck the account, console realm 8 and realm 10 with locking reads inside the transaction. The realm checks shared with Data Source Profiles moved into `DbalRealmPolicy`; the Data Source Profile adapter behaves as before.

A save is one transaction. It locks the template, then locks the input and checks the form's revision. Every submitted item must be a template item (`local_graph_id = 0`) of the same template. Values are pushed out to graphs built from the template. Then the input's item set is deleted and reinserted. A failure at any step rolls back the input row, its items and the graph values. The field type comes from `GraphTemplateInputColumn`, which matches the allowlist in `lib/graph_template_input.php`; a test keeps the two equal. Pushed-out values are checked with `GraphTemplateInputColumn::allows()`. The legacy `graph_template_input_value_is_allowed()` now delegates to that method, so `graphs.php`, `push_out_graph_input()` and the editor apply one rule. `lib/graph_template_input.php` loads the enum file directly, so legacy pages and native harnesses need no autoloader, and the editor does not load the legacy file. Removal is a POST with a Symfony CSRF token and the revision. It must delete exactly one input row; its item set is removed in the same transaction. GET never changes data.

The form posts Symfony fields only. The item checkboxes are a choice field built from this template's items, so an item from another template is refused as an invalid choice before the editor runs. The editor checks again under a share lock. Unknown fields, the old `i_<id>` and `save_component_input` names, and array values where scalars are expected produce 422 before any write. Route IDs must match `[1-9][0-9]{0,7}` and be at most 16777215, the `mediumint unsigned` range. Legacy query IDs follow the same grammar.

| Outcome | Status |
| --- | --- |
| No console account | 401 |
| Realm 10 or console realm 8 missing, or the account is disabled, locked, a guest or must change its password | 403 |
| Unknown template or input, or an out-of-range ID | 404 |
| Malformed legacy request | 400 |
| Invalid form or CSRF failure | 422 |
| Stale revision, a missing template or input at write time, or a foreign item | 409 |
| Read failure, including an offline collector's unreachable main database | 503 with `Retry-After: 30` |
| A write whose outcome is unknown | 502 |
| Saved or removed | 303 to `graph_templates.php?action=template_edit&id=N` below `url_path` |

Responses use `private, no-store`. Strings use the `graph_template_input` domain in English and French. The other 21 shipped languages have `graph_template_input.<locale>.yaml` catalogs (for example `de_DE`) that copy the legacy `.po` translations of the same English text verbatim. Several of those msgids, such as "Associated Graph Items", were used only by the old page and leave the regenerated `.po` files, so the gettext fallback alone would show English. Messages with no legacy translation, and the double-encoded he-IL "Text Format", fall back to English. The French catalog keeps its reviewed wording, which differs from the legacy French for 14 messages. The routes use main's `LocaleSubscriber`. Stored names, descriptions and RRDtool type names are escaped and not translated. The pages have no inline scripts or event handlers. `layout.js` opens these routes, and `graph_templates_inputs.php` links, as full documents rather than legacy AJAX fragments. The input delete marker on the graph template page now opens the confirmation by GET instead of posting the csrf-magic token.

## Behaviour differences

- `?action=input_remove` used to be refused on GET. It now redirects to a confirmation page. Removal itself needs POST, a Symfony CSRF token and the current revision.
- A POST to `graph_templates_inputs.php` returns 409, telling the user the form has expired, after authorization. `?action=save` on GET returns 405 with `Allow: GET, HEAD`.
- A bare visit or an empty `action` opens the editor, as `input_edit` was the page's default.
- When a save adds items to an input with existing items, each graph built from the template receives the value from that graph's item with the lowest template item ID. Legacy used whatever row the database returned first. The other push-out rules are unchanged: nothing is pushed for a new input or an input with no items, an empty data source is not pushed, and one malformed stored value cancels the push-out and is logged, while the save still proceeds.
- The name is limited to 255 characters, the width of `graph_template_input.name` (`varchar(255)`), on the server and by the input's `maxlength`. The legacy form allowed only 50 characters in the browser and nothing on the server. A description is limited to 65535 bytes, the `text` column.
- A stored hash that does not match `[a-fA-F0-9]{32}` is replaced when the input is saved. A valid hash is kept, so exports keep their identity.
- `push_out_graph_input()` in `lib/template.php` is unchanged and still serves `graph_templates_items.php`.

## Verification

`GraphTemplateInputEditorTest` runs the catalog, editor and access adapters on SQLite with a statement-counting DBAL middleware. It covers the column allowlist matching the lib function, catalog scoping, and the item list as one query. It covers creation, push-out, empty data sources, malformed hashes and malformed stored values. It covers cross-template, local-graph, missing and new-input foreign items, each with rollback. It covers a trigger-forced failure while the items are rewritten, which leaves every row unchanged, and unconfirmed creation and removal, and malformed stored rows. It also covers stale, missing and invalid input, removing all items, revoked access, and direct, group, guest, locked, unknown-method and realm 8 admission.

`GraphTemplateInputEditorDatabaseTest` runs the same editor and the real realm checks on MySQL or MariaDB, in a disposable schema built from the `cacti.sql` table definitions, under strict SQL. It checks:

- a save that commits the input, its items and the pushed values together, and removal;
- a trigger failure while the item set is reinserted, which rolls back the input row, its items and the pushed graph values;
- foreign, local-graph and missing items, refused with nothing written;
- a stale revision on save and remove, refused with nothing written;
- a rename committed by another session after the save's first lock, which the save's locking read sees and refuses as stale;
- a save blocked by another writer's row lock, which gives up without writing;
- console realm 8 revoked before the write.

It uses `KADUPUL_TEST_MYSQL_DSN` over TCP and runs in the `Database contracts` matrix with `--fail-on-skipped`, so a missing database fails the job.

`GraphTemplateInputColumnTest` checks each field's value rule, including bounds, control characters, invalid UTF-8 and non-scalar values, and that the legacy function returns the same answer. Before the move, the legacy function and `allows()` were compared on 61,020 column and value pairs, including 3,000 random strings per column, with no difference.

`GraphTemplateInputRoutesTest` runs the real kernel with controlled ports. It checks the legacy field order, the 17 field-type labels, item labels and emphasis, escaping, the absence of inline handlers, HEAD, saving and removal with `url_path=/cacti/`, and fallback for an unusable `url_path`. It checks malformed and legacy-shaped forms, missing CSRF, the 401, 403, 404, 409, 422 and 502 outcomes, a 503 for an unreachable database, and that denial happens before any catalog read. It checks every legacy redirect, 400, 405 and the expired POST. French is checked through an actual `fr-FR` user preference.

`LegacyPageForwarderTest` covers the shim. `sites-navigation.test.mjs` and `vendor-assets.spec.js` cover the navigation change. The legacy-page Pest tests for the old save and remove handlers were removed with the page. The remaining value and SQL-column tests in `GraphTemplateInputTest` still run against `lib/template.php`. The `graph_templates_inputs-input_edit` form golden was retired because the page no longer renders a legacy form.

Route profiling (R8) is not done for this page. The `tests/e2e` fixture seeds no graph template, so both sides of a pair would measure a 404. `tests/tools/test_profile_routes.py` lists the shim as unprofiled, with that reason, until the fixture has a template.

## Deployment and rollback

Deploy the PHP, routes, service wiring, Twig templates, translation catalogs, gettext catalogs and `layout.js` together. There is no schema, runtime or dependency change. Only the Symfony routes write graph item inputs. The shim forwards every request, and a form posted to the old page during deployment gets 409 instead of being applied.

Reverting restores the PHP page and its forms. Inputs saved through either path use the same tables and remain valid. Application rollback does not undo a save or removal already made. LTS is unchanged.

## Final diff review

The review compared the editor with the legacy `form_save()`, `input_remove()` and `input_edit()`, with `push_out_graph_input()`, and with the `graph_template_input` and `graph_template_input_defs` columns in `cacti.sql`. It found that the controllers needed the inline guard shape the entry-point classifier certifies, so the routes are listed as realm 8 + realm 10 rather than unknown. It found that a refused save re-rendered with a variable that hid the template's items. A regression now checks that a refused form keeps its items. The Data Source Profile access pin was updated for the shared realm policy, with its existing tests passing unchanged.

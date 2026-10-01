<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later -->
# Device templates on main

`host_templates.php` is a compatibility entry point. Symfony owns device template
listing, creation, editing, associations, duplication, deletion and synchronization
at `/app.php/inventory/device-templates`. The legacy POST form expires with 409;
legacy edit and filter URLs redirect to current GET routes.

The Inventory domain binds revisions to name, class and both sorted association
sets. The persistence port owns reads and invokes an isolated CLI adapter for
legacy template APIs and plugin hooks. Controllers require an authenticated
console actor before parsing or loading a template. Access requires current
console realm 8 and device-template realm 12, including enabled group grants.
Writes lock policy, account and grants again; disabled, locked, guest and
forced-password accounts cannot write.

Symfony forms require same-origin CSRF proof. Route IDs bind association parents;
clients cannot choose a parent in POST. Parent and child sets are locked before
revision comparison. New and expanded duplicate names are limited to 100 Unicode
characters, matching the database column. Local template writes and saved filters
reject caller-owned transactions, require the primary collector and inspect each
actual connection table (including temporary shadows) for InnoDB before beginning
a REPEATABLE READ transaction. Account, policy and direct/group grant tables are
covered by the same precondition. Local mutations roll back together. Delete detaches active devices only, leaving
their graphs and queries intact. Duplication preserves the public API hash and association behavior.
List counts and the has-devices filter include soft-deleted device references, preserving legacy semantics; only active devices are detached by deletion.
List graph filtering includes graph templates reached through attached SNMP queries.

## Synchronization and retries

Synchronization preserves `api_device_template_sync_template()` semantics: only
reachable/up-or-recovering devices (status 2 or 3) are synchronized. Collector
writes, SNMP recache, automation and plugin hooks are external side effects and
cannot share one atomic transaction with the primary database.

The worker locks current permissions and selected revisions, verifies primary
storage, and commits a durable `settings` operation claim before external effects.
A second transaction rechecks permission and revisions, runs the public API and
commits verified local writes. Failure after starting synchronization reports
possible partial external effects, rolls back remaining primary transaction work,
and preserves the claim. Reusing a submitted operation token returns 409. A lost
response is an unknown outcome: inspect device, collector and plugin state before
opening a fresh confirmation. A fresh confirmation is an explicit new operation;
remote APIs are responsible for their own idempotency. Claim records are retained
for diagnosis and are not automatically retried or deleted.

## Installed plugin presentation boundary

The isolated worker executes only installed `device_template_top` and
`device_template_edit` hooks with the authenticated actor and route template ID.
`TrustedDeviceTemplatePluginHtml::capturedHooks()` wraps only their named captured
output as Twig markup. Template names, associations and form values stay escaped.
Plugin authors remain responsible for escaping their own output. Rendered controls
are retained, including plugin-owned forms and links. Legacy plugin controls that
POST additional template fields to `host_templates.php` must adopt current forms
or their own authenticated endpoint: expired legacy mutations are not replayed.

## Evidence

- `DeviceTemplateDefinitionTest` checks strict fields/filters and revision handoff.
- `DeviceTemplateDefinitionPresentationTest` exercises the actual kernel, anonymous
  guards, French presentation, duplicate labels, installed markup and CSRF origin.
- `device_template_definition_review_http.py` runs authenticated HTTP and MariaDB
  create/edit/associations/duplicate/delete/sync, stale requests, rollback, current
  policy, legacy expiry and local/external outcome reporting.
- The shared HTTP coverage runner executes the same scenarios with file and
  database sessions. The worker is required by both coverage merge and self-test.

This migration targets main/PHP 8.4; the 1.2 LTS page is unchanged.

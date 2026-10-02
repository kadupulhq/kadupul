<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Primary CDEF reference integrity

The primary MySQL or MariaDB schema uses ten persistent InnoDB row triggers to protect CDEF references. A writer waiting for a CDEF deletion must recheck its parent after the deletion commits. Inserting, updating, replacing or copying a reference to the deleted parent is then refused by the database. If the deletion rolls back, the waiting writer can proceed.

The protected references are:

| Table | Reference |
| --- | --- |
| `cdef_items` | Required owning `cdef_id`, plus the canonical positive parent ID in `value` when `type=5` |
| `graph_templates_item` | Positive `cdef_id`; zero means no CDEF |
| `aggregate_graph_templates_item` | Positive cached `cdef_id`; zero or NULL means no CDEF |
| `aggregate_graphs_graph_item` | Positive cached `cdef_id`; zero or NULL means no CDEF |

The parent guards refuse an ID change or deletion while an incoming reference remains. Inherited target values must be canonical positive decimal IDs within the MEDIUMINT range; malformed values are refused rather than normalized.

## Installation and recovery

Fresh primary installation and normal primary upgrade install and verify the contract before recording completion. The existing release number is unchanged. An already-current primary installation can explicitly run:

```sh
php cli/upgrade_database.php --install-cdef-reference-contract
```

Run this operation with the installation account while primary writers are stopped. The operation validates persistent table engines and column types, existing references, usable cache indexes and the exact reviewed trigger and status procedure definitions. It proves native trigger capability with an owned disposable fixture. It does not change account privileges, server binary-log settings or existing unsafe references.

Conflicting operator triggers, incompatible named indexes, temporary table shadows or unsafe existing references refuse installation. Resolve the specific schema or data problem deliberately, then rerun. MySQL/MariaDB DDL can commit incrementally: an error may leave some guards or indexes installed. An exact partial installation is completed idempotently; a success is printed only after the full contract and data preflight are verified. A caller transaction is refused before DDL.

This contract is primary-only. Collectors do not replicate CDEF parents, so installing these guards on their replicated graph item tables would break legitimate collector synchronization. The explicit CLI operation rejects a collector configuration instead of switching its connection to the primary.

## Runtime access and deletion

The installer verifies `kadupul_cdef_reference_status`, a no-argument `SQL SECURITY DEFINER READS SQL DATA` procedure. The ordinary runtime identity needs its normal table access and scoped EXECUTE permission on that procedure; it does not need installer TRIGGER or CREATE ROUTINE privileges. The procedure checks the exact native guards and usable indexes. Runtime also checks accessible procedure properties and the connection's actual persistent tables, refusing temporary shadows. A settings flag cannot substitute for these checks.

Legacy bulk deletion retains the page's authorization and CSRF checks. Its helper rejects caller transactions, validates exact bounded selections, locks selected parents in ID order and verifies `system=0` before mutation. It removes only selected owned children and rejects incoming references from unselected definitions or graph/cache rows. A native statement failure rolls back the helper's owned transaction. If commit or rollback acknowledgement is uncertain, the caller reports that deletion could not be confirmed and asks the user to reload before retrying.

## Privileged DDL boundary

The contract protects ordinary row writes. Privileged DROP/TRUNCATE operations can bypass row triggers, and an actor allowed to replace routines can forge a status result. Such DDL authority is outside this guarantee. Keep runtime accounts free of those privileges; schema administrators must preserve or reinstall the verified contract after deliberate schema changes. Runtime accounts with only scoped EXECUTE cannot independently read the hidden routine body on both supported engines; installation establishes that trusted body.

## Native verification fixtures

The native probes create and remove only random task-owned schemas and, where needed, random task-owned principals. They read database credentials from `KADUPUL_REFERENCE_TEST_DSN`, `KADUPUL_REFERENCE_TEST_USER` and `KADUPUL_REFERENCE_TEST_PASSWORD`; no credential values belong in source or proof reports. The installer probes require a marked copied candidate and an exact credential-free fixture configuration, preserving existing installations and source evidence.

The normal upgrade probe seeds the historical 1.2.33 schema from commit `5a1c81c2dc89508052c7b54bf9db491f32f9beb7`, then invokes the actual production upgrade entrypoint. Its fixture parser removes the leading SPDX comment and recognizes that historical source's unused `DELIMITER //` declaration: the actual statements use semicolons and have no `//` terminators. This is historical source-schema parser normalization, not evidence that the old file executes unchanged through the MySQL command line. Actual SQL statements and data remain unchanged, and current source's real `END$$` trigger delimiters are honored.

## Upgrade markers and cache replacement

The current release marker is written only after required contracts and application defaults succeed. Lower intermediate upgrade checkpoints remain available, but `upgradeDatabase()` cannot publish the current release itself. The final writer locks the actual version snapshot, accepts an empty fresh table or one existing marker, checks the write and exact readback, and commits its owned transaction. Native write refusal or readback mismatch retains the previous marker for retry. Caller transactions, nontransactional version tables, temporary shadows and multiple markers are refused. An uncertain commit or cleanup is reported as unconfirmed rather than successful.

Aggregate cache replacement checks the actual selected connection and persistent InnoDB table, then deletes and reinserts in one owned transaction or a caller savepoint. It checks native PDO prepare, execute, SQLSTATE and commit/release outcomes. The two legacy aggregate editors and graph conversion stop propagation and success reporting after refusal. Other graph settings written earlier by those workflows may remain; the error asks the operator to review them before retrying.

A stale browser Step 97 poll preserves a persisted failed Step 99 and its error instead of silently starting another background upgrade. A normal authenticated reload still starts the existing retry wizard. Constructor regressions cover failed polling, healthy polling, a wizard that has not started, and an explicit new-wizard request.

The runner requires fourteen native probes, including the production cache replacement and final marker writers. Its web failure/retry case starts at version 1.2.33, uses normal local authentication and rendered CSRF, reaches a genuine background contract failure, confirms the old marker, repairs only the fixture orphan, and completes the normal authenticated retry. Cache races use two real connections under READ COMMITTED and REPEATABLE READ and confirm a server lock wait before releasing the deleting actor.

## Gettext compatibility generation

Some legacy consumers pass dynamic labels to `__()`, including the graph editor's `Cur:` and `Avg:` legend labels. Static extraction alone cannot safely remove historical catalogue entries. This change preserves every existing key and translation while adding the new refusal messages, including compiled French translations.

Generation uses the normal `locales/build_gettext.sh` flow followed by a GNU gettext compatibility union. The baseline is the reviewed source commit `9fc51ad209151d4b8f8080b704ef76c941e9a2b2`. Reproduce it with these steps in an exclusively owned temporary directory:

1. Run `locales/build_gettext.sh` with GNU gettext available. Save its newly extracted `cacti.pot` as `current.pot`.
2. Export the baseline POT with `git show 9fc51ad209151d4b8f8080b704ef76c941e9a2b2:locales/po/cacti.pot > previous.pot`.
3. Run `msgcat --use-first --no-wrap current.pot previous.pot -o locales/po/cacti.pot`. Current source locations win; historical identities remain available.
4. For every PO file, export its corresponding baseline file using `git show`, then run `msgcat --use-first --no-wrap current.po previous.po -o merged.po`.
5. Run `msgmerge --backup=off --no-wrap --no-fuzzy-matching --update -F merged.po locales/po/cacti.pot`, then `msgattrib --no-obsolete --no-wrap merged.po -o locales/po/<locale>.po`.
6. Compile each result with `msgfmt --check-format locales/po/<locale>.po -o locales/LC_MESSAGES/<locale>.mo`.

The normalized result adds fourteen POT identities, removes none, and changes no translations of existing identities. Ordering and current source references may change. The catalogue regression also verifies the historical dynamic legend labels and their compiled French values.

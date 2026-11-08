# Asset management pilot

The opt-in asset register manages a bounded population of network equipment.
It keeps accepted ownership and lifecycle records separate from observations,
monitoring device IDs, addresses and software descriptions. It does not alter
polling, devices, RRD files, credentials or existing plugin hooks. Main only;
the LTS maintenance line is unchanged.

## Enable and establish responsibility

1. Back up the application database, configuration and keys using the existing
   installation procedure. Rehearse the upgrade and restoration on a copy.
2. Deploy the complete change with locked dependencies. Run the normal installer
   or `cli/upgrade_database.php` workflow to schema **1.2.40**. Fresh installations
   include the same four tables. The step is additive and retryable; it refuses
   incompatible existing definitions. Schema 1.2.39 is not introduced by this
   change and no unpublished discovery implementation is required.
3. Set `KADUPUL_ASSETS_ENABLED=1` for both PHP-FPM and command workers. Rebuild the
   Symfony cache and restart workers. The default is disabled.
4. An eligible console account with Settings/Utilities permission (realm 15)
   opens `app.php/assets`, also linked from the Symfony device inventory.
   Remote collectors cannot administer the pilot. There is no guest mode.
5. Name the actual population and accountable steward. Agree ownership and
   custody responsibilities with that team. Set an observation freshness limit
   suitable for the population; the form proposes one day, not a research-derived
   service-level objective. Valid limits are five minutes to thirty days.

The web adapter sends mutations to `assets:register --worker`. It supplies the
authenticated identity; browser fields cannot choose the operator. The worker
rechecks the account and realm on the primary installation. Web asset reads use
the configured `web` DBAL connection, including existing read credentials.
The worker uses the existing local CLI connection. This is a process boundary,
not an operating-system sandbox or a new credential-isolation guarantee.

## Workflows

Create each asset with a stable generated ID. Record an accountable owner,
custodian, service, location, manufacturer and serial where known. Support and
maintenance dates, contract and recovery references, redundancy, replacement
lead time and purchase cost are optional evidence fields. Costs are integer
minor currency units with an explicit currency code; totals never combine
different currencies. Do not put passwords or other secrets in any text field.

Commissioning moves `planned → received → active`. Active records require an
owner, custodian and service. Received equipment can enter storage or retirement;
active equipment can enter storage or retirement; stored equipment can return
to service or retire. Retired equipment can return to storage after review or
be disposed of. Disposal is terminal. Every transition requires an evidence
reference. The operator must verify the referenced handover, approval or
sanitization certificate; the application cannot establish that an external
document is authentic or that sanitization actually occurred.

Add directed dependencies between assets. Retirement refuses surviving
dependants, and dependency cycles are refused. Reactivation refuses dependencies
on retired/disposed assets. Move the dependent service or retire its asset before
retiring its prerequisite. Dependency review is independent of monitoring health.

Import existing monitoring device IDs in batches of at most 100. This reads
`host.id`, `description`, `hostname` and `location`; it makes no device contact.
The observation time is when inventory metadata was read, not proof of a
successful poll. Each observation can be linked to an asset and opens its existing
monitoring detail page. Disabling or deleting a monitoring device does not retire
the asset.

External integrations can import scoped observations through the UI or CLI.
Source identifiers start with an ASCII letter and use letters, numbers, `.`, `_`,
`:`, `/` or `-`; include the account or installation scope. `kadupul-monitoring`
is reserved for the built-in adapter. Source identifiers and external keys must
not have surrounding whitespace; ambiguous spellings are rejected rather than
silently combined. Observation records are:

```json
[
  {
    "key": "device-104",
    "observed_at": "2026-11-07T12:00:00Z",
    "fields": {
      "name": "core-switch",
      "manufacturer": "Example",
      "serial": "S104",
      "location": "Rack A",
      "address": "192.0.2.104",
      "software": "Firmware description"
    }
  }
]
```

Use the actual UTC observation time; future, older, duplicate-batch and
same-timestamp contradictory observations are refused. A source/key pair has one
explicit asset mapping. The register does not infer identity from an IP address,
hostname or serial alone. Review physical or administrative evidence before
linking. Correct a mistake by reassigning or clearing the mapping and recording
the reason. Prior mappings and observations remain in history. Assets themselves
are not destructively merged.

Observed differences appear alongside accepted values. Only an explicit approval
can copy selected name/manufacturer/serial/location observations into the accepted
record. Discovery cannot overwrite ownership, financial details or lifecycle
state. A successful empty batch leaves old records intact. Integrations report a
failed collection with `source_failure`; this makes that source ineligible for
freshness metrics until a successful collection. Expiry also makes observations
stale. Neither condition retires equipment.

## Operating cadence and decisions

The steward reviews unlinked identities and field conflicts weekly. Owners review
support expiry, maintenance, service dependencies and replacement decisions
monthly. Record the review and evidence in the register. Overdue reviews remain
visible; this change does not schedule email or other external notifications.

Prioritize consequences using the service, redundancy, recovery plan, support
date and replacement lead time. Explain the decision in the evidence reference.
Criticality is a declared category, not a calibrated probability or expected-loss
estimate. There is no multiplied ordinal risk score.

Establish coverage against procurement records or a physical audit. The baseline
records independently verified IDs, evidence and verification date; include
unregistered assets in that independently maintained population. Reconcile the
baseline again when records are created or the scope changes. An absent baseline
is displayed as not assessed. The register count cannot establish completeness.

Review samples of linked and unlinked records against independent evidence.
Record sample size, incorrect merges and missed duplicates. These are review
counts, not estimates proven representative of the whole population. Set
acceptance targets after measuring the baseline and document their rationale.
Do not expand solely because all software tests passed.

## CLI and retries

`mise exec -- php bin/console assets:register --operator USERNAME` returns the
current register, metrics and the latest 50 history entries. It resolves the
existing account with the same administrator requirement. Shell access is the
CLI trust boundary; the option does not authenticate the operator.

Use `--apply` to read one JSON command from stdin. Every command includes `action`,
the current integer `revision`, and a fresh 32-character lowercase hexadecimal
`request_id`. The UI creates these values automatically. For example, save this
as a local input file after substituting a new request ID and the actual revision:

```json
{
  "action": "import",
  "revision": 3,
  "request_id": "1de57178eb40429ca9b933783b540c79",
  "source": "inventory/account-a",
  "observations": []
}
```

```sh
mise exec -- php bin/console assets:register --operator USERNAME --apply < import.json
```

Exit 0 and `status: ok` confirm a committed operation and return its receipt.
Exit 1 reports a stable reason without database diagnostics. If the worker times
out or its response is lost, inspect history before retrying. Replaying the exact
same command and request ID returns the original committed result. Reusing that
ID for different content is refused. A stale revision requires reviewing the
new state and creating a new operation, not blindly updating the revision.
An unconfirmed web write displays its request ID without a replacement form.
Keep that ID when investigating; opening a new form creates a different operation.

## Storage, bounds and recovery

Four InnoDB tables hold the registry policy/revision, typed asset/identity/
dependency records, operation journal and observation history. Flexible record
fields are validated JSON in `asset_record`; this format is internal to the
experimental pilot. It is not a public reporting schema.

Every mutation locks the singleton register row, rechecks access and revision,
and commits records, observations and the operation journal together. Failed
writes/readbacks roll back; existing caller transactions are refused without
being committed or rolled back. Unrelated legacy writers do not mutate these
new tables. Direct SQL writes to these tables are unsupported.

The pilot admits at most 500 assets, 2,000 scoped identities, 2,000 dependency
edges and 50 sources. Imports contain at most 100 observations; the request
ceiling is 512 KiB. Each view loads the bounded register and renders 50 asset
and identity rows, with 50 journal entries per history page. The global mutation
lock deliberately favors correctness at this scale. Expansion requires measured
latency, contention and memory evidence and a reviewed storage/query design.

History is retained without automatic deletion. Monitor database growth and
include all four tables in transactionally consistent backups. Protect backup
and journal access as administrative data. The journal is not tamper-proof.
The existing audit sink also receives redacted success/failure/denial metadata;
its failure cannot undo a committed operation. Monitor that sink separately.

For rollback, disable the feature in both web and worker environments, drain
active commands and rebuild caches before reverting code. Keep the additive
tables and schema version. Reverting code does not undo recorded decisions.
For data restoration, stop all asset writes, restore all four tables from one
consistent backup, compare revisions and receipt/history counts, and reconcile
changes made after the backup before reopening the pilot. Do not restore one
table independently. Existing database/RRD restoration procedures still apply.

## Evidence and release gate

Tests live in `tests/Symfony/AssetManagement` and are included by the existing
Symfony runner. Native tests require an explicitly disposable database whose
name ends in `_assets_test`, configured by `KADUPUL_ASSET_TEST_DSN`.

Local verification uses PHP 8.4.25, the committed Composer lock, MariaDB
10.11.19 and MySQL 8.4.13. The checks cover lifecycle transitions, source identity, reconciliation,
idempotency, stale revisions, failed/empty collections, late journal rollback,
real writer lock contention, migration retry, HTTP authorization, CSRF and locale
selection. SQLite coverage supplements the native database checks. The subprocess
test executes the production command and operator adapter against the disposable
database, including account lock and remote-collector refusal. Another test loses
the acknowledgement after a real commit and confirms that retry returns the
original receipt without creating another asset.

The capacity fixture admits a 100-observation batch, refuses 101 observations,
reads 500 persisted assets with a fixed four-query budget including history,
and refuses a 501st asset. This is a query-count and boundary check, not a
production latency or maximum-field-size memory benchmark. Native transaction
and upgrade tests are registered in the existing MariaDB/MySQL CI matrix.
The complete focused suite passed separately against both native engines.

Before production adoption, require current-head CI and independent review,
an installed browser/worker rehearsal under deployment credentials, an actual
backup restoration rehearsal, and pilot-owner acceptance of the observed data
quality. No production capacity, multi-tenant isolation, deployment or release
is claimed. Cloud-specific adapters, software entitlement accounting and
physical disposal verification are outside this network-equipment pilot.

## Implementation review

This is an architectural improvement/new feature on main, with no LTS backport.
The separate review traced the route through early admission, locale selection,
CSRF, shape validation, the stdin worker envelope, current-account authorization,
the registry lock, persistence, journal/receipt and redirect. It identified and
corrected omitted-field resets, unconfirmed-write resubmission, eager service
initialization on unrelated routes, and initialization before schema validation.
Focused tests cover those outcomes and the failure/retry paths above.

Local verification on 2026-11-07 used base revision
`44ef2204ec1f3e2711e7f5a555068f055b7ed9b3` plus the asset implementation.
The original suite's SHA-256 fingerprint of the changed non-Markdown files is
`9c18c621be127cbd5a3d08c1797e77317c725aef229520d3848b250075b3f2bf`.
It hashes each sorted relative path, a NUL, its bytes and another NUL, using
`git diff --name-only HEAD` and excluding only `.md` files.
The subsequent browser regression and CI registration produce fingerprint
`568ed72ef626728877af76d3b4177f42fb81994604fdea062dafdea5b0c09437`;
application code and dependencies are unchanged. The supplementary rehearsal
compared 40 installed application/configuration and dependency-metadata files
with the working tree and found identical bytes.

| Check | Local evidence |
| --- | --- |
| Asset workflows, real MariaDB persistence/worker, SQLite and kernel HTTP | 64 tests, 338 assertions, passing |
| Asset workflows, real MySQL 8.4.13 persistence/worker, SQLite and kernel HTTP | 64 tests, 338 assertions, passing on the same source fingerprint |
| Complete Symfony suite | 5,988 cases reported, 342,030 assertions, zero failures/errors; 516 skipped. The native asset suites were run separately against MariaDB as recorded above. |
| Asset static analysis | PHPStan: zero errors; no new suppressions |
| Architecture, service wiring and templates | Deptrac: zero violations; container, Twig and translation YAML lint pass |
| Formatting and workflow configuration | PER-CS check and actionlint pass |
| Managed frontend | Locked build and 49 JavaScript tests pass |
| Authorization and sink inventories | 433 classified entry points, none unknown; sink inventory matches; no new architectural hotspots under `LC_ALL=C` |
| Classifier rejection checks | 13 asset handoff checks and 27 existing installer handoff checks pass |
| Disposable backup restoration | `mariadb-dump --single-transaction` of all four tables restored into a separate database; every row and the application snapshot matched at revision 4, including one asset, one identity, four journal entries and one observation |
| Supplementary installed browser/worker rehearsal | 80 checks pass under `/cacti/` with Apache 2.4.68, PHP-FPM/CLI 8.4.27, MariaDB 10.11.19, Playwright 1.64.1 and Chrome 157.0.8103.39. Real installer finalized schema 1.2.40. Production CLI and SQL confirm revision 21, two assets, two retained identities, 21 receipts and two observations; the original asset is disposed and its removed dependency is absent. Monitoring device 1 remains disabled. |

The PHP coverage subprocess checks need PCOV and `display_errors=stderr` in the
child runtime as well as the parent. These were enabled in a task-local INI
directory; global PHP configuration was not changed. The inventory AST reader
used PHP 8.4.25 with a 2 GiB memory allowance. Earlier environment failures and
intermediate implementation failures are not acceptance evidence.

The authorization inventory records the opt-in route's primary-server and
realm-8/realm-15 requirements. Enforcing sources are pinned and mutation checks
refuse changed or counterfeit handoffs. The HTTP refusal sweep explicitly enables
the pilot so its default-disabled 404 cannot substitute for an authorization
check. The full installed authorization sweep remains a release gate.
The pinned-image attempt on 2026-11-07 stopped during fixture provisioning because
GHCR refused authentication for the harness's MariaDB mirror. That attempt ran
no browser assertions. A subsequent local rehearsal used cached Apache/PHP image
`sha256:dc3859e461b67bb5e52dcff4015d8b817a05e88dda74f22184001065a33bb8b3`
with the current source and locked dependencies. Its successful browser checks
cover ordinary login, anonymous refusal, a console-only account admitted to the
console but refused asset reads and writes before CSRF validation, missing CSRF,
native form validation, the shipped CSP, commissioning and editing, selective
observation acceptance, ownership preservation, unlink/relink, baseline/reviews,
collection failure, empty imports, stale tabs, cycle and dependent-retirement
refusal, dependency removal, monitoring import and its installed device link,
disposal and journal receipts. This is supplementary evidence on the recorded
runtime, not proof of the pinned CI image or deployment credentials.

`tests/e2e/asset-native.cjs` is registered in the existing native browser CI job.
Run it through `CACTI_E2E_ASSETS_BROWSER=1 tests/e2e/docker/run.sh` after installing
the existing locked browser dependencies. The fixture enables the pilot only on
its primary server and uses ordinary authentication and the production worker.
The runner refuses a non-local origin or a nonempty register. Re-run the pinned
image job with authorized package-read access; its image digests are unchanged.
No authentication, deployment or production-data bypass was introduced for testing.

## Research basis

The design applies [Wang and Strong (1996)](https://courses.washington.edu/geog482/resource/14_Beyond_Accuracy.pdf)
to task-specific quality, [Elmagarmid et al. (2007)](https://archive.nyu.edu/bitstream/2451/27823/2/CeDER-PP-2007-15.pdf)
to explicit identity reconciliation, and [Schuman and Brent (2005)](https://repository.up.ac.za/items/e38a47b6-4e5b-4299-805d-417dc180621d)
to lifecycle decisions. The last study concerns industrial physical assets;
this IT application is an engineering adaptation. [Cox (2008)](https://pubmed.ncbi.nlm.nih.gov/18419665/?dopt=Abstract)
motivates retaining decision evidence instead of presenting ordinal scores as
precise risk measurements. These sources do not validate this implementation or
establish universal numerical acceptance targets.

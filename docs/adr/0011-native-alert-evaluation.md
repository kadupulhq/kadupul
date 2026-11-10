# 0011. Native alert evaluation

## Status

Proposed. This record is the specification for Phase 1 of native alerting.
No production code accompanies it.

## Context

Kadupul has no alerting of its own. The `Alerting` module in
[the architecture document](../architecture.md) owns "alert rules, evaluation,
incidents and notification intent", but `src/Alerting` today only sends
administrator mail through `Application/Port/AdministrativeMailDelivery.php`.
Threshold alerting is provided by the THold plugin, which keeps its own tables
and runs inside the poller.

Phase 1 adds threshold rules and hour-of-week baseline rules that open and close
incidents and queue mail. Later phases each start with their own record. The
facts below constrain where the evaluator takes its input and where it runs.

- **Rates already exist in one place.** `dsstats_poller_output()`
  (`lib/dsstats.php:683`) computes a rate for each COUNTER, DCOUNTER, DERIVE
  and DDERIVE reading. It applies the 32-bit and 64-bit rollover rules and the
  `rrd_maximum` cap (`lib/dsstats.php:799-830`). It writes the raw reading to
  `value` and the rate to `calculated` in `data_source_stats_hourly_last`
  (`lib/dsstats.php:934-940`). It does this only when `dsstats_enable` is `on`
  (`lib/dsstats.php:701`).
- **The table is volatile and has no timestamp.**
  `data_source_stats_hourly_last` is `ENGINE=MEMORY` with the columns
  `local_data_id`, `rrd_name`, `value` and `calculated`
  (`cacti.sql:1443-1449`). A database restart empties it. Counter rates then
  need two cycles to return, because the next cycle has no previous reading and
  stores `NULL` as its rate (`lib/dsstats.php:810-811`). The table carries no
  sample time, so the evaluator cannot tell a fresh row from a stale one by
  reading it alone.
- **The `poller_output` hook carries raw readings.** `process_poller_output`
  calls `api_plugin_hook_function('poller_output', $rrd_update_array)` at
  `lib/poller.php:1290`, immediately after `dsstats_poller_output()` at
  `lib/poller.php:1288`. The array holds the readings that are about to go to
  RRDtool. [ADR 0007](0007-raw-samples-and-rrd-sink-semantics.md) keeps
  rollover and rate rules in RRDtool and does not allow a second copy.
- **`poller_bottom` runs after collection.** `poller.php:986` calls
  `api_plugin_hook('poller_bottom')`, and `poller.php:981` and `poller.php:1071`
  call `dsstats_poller_bottom()` for the main poller. `exec_background()`
  (`lib/poller.php:148`) starts a program without waiting for it.
- **Boost does not bypass dsstats.** `process_poller_output` asks
  `boost_poller_on_demand()` (`lib/poller.php:1238`) whether to write RRD files
  itself. With Boost on it gets `false` (`lib/boost.php:282-315`) and skips its
  own `rrdtool_function_update()`, but it still passes every complete sample to
  `dsstats_poller_output()` (`lib/poller.php:1287-1288`). That function reads no
  Boost setting. With `boost_redirect` on, collectors write each sample to both
  `poller_output` and `poller_output_boost` (`cmd.php:551-570`; Spine
  `src/poller.c:1820-1932`), so the main poller still sees it. Boost's own run
  (`poller_boost.php:305`) calls only `dsstats_boost_bottom()`, which computes
  daily averages, so dsstats does not write a sample twice.
- **Each sample's time is stored beside its rate.** In the same call,
  `dsstats_poller_output()` inserts `(local_data_id, rrd_name, time, value)` into
  `data_source_stats_hourly_cache` (`lib/dsstats.php:708`, `:924-929`), with
  `time` set to the sample time (`:761`) and `value` set to the same computed
  rate that goes to `calculated` (`:934-940`). The cache's key is
  `(local_data_id, time, rrd_name)` (`cacti.sql:1430-1437`). `poller_dsstats.php`
  deletes cache rows older than `dsstats_hourly_duration` minutes, at least 60
  (`poller_dsstats.php:178-185`, `include/global_settings.php:2271`).

The project constraints that apply: domain code imports no Symfony HTTP or
session classes, no Doctrine `EntityManager` and no legacy globals; the web tier
gets no new write credentials toward devices; and an upgrade must not change
behavior until an administrator opts in.

## Decision

1. **Input is computed rates from dsstats.** The evaluator reads
   `calculated` from `data_source_stats_hourly_last` through the
   `RateSource` port. It does not use the `poller_output` hook payload. The
   payload holds raw counters, and rating them again would duplicate the
   rollover rules that ADR 0007 keeps in one place. The port returns, for a
   `(local_data_id, rrd_name)` key, either a rate with its observation time or
   nothing. A `NULL` rate and a missing row are both "nothing".

   The observation time is the newest `time` in
   `data_source_stats_hourly_cache` for the key, read as `UNIX_TIMESTAMP(time)`
   so the reader's session time zone does not change it. The adapter uses it
   only when the cache `value` at that time equals `calculated`. Otherwise the
   two rows come from different samples, for example while the writer is
   between its two inserts, and the key returns nothing. The stored instant is
   right while the poller's PHP time zone matches its database session time
   zone, which Cacti already requires. Proved by
   `tests/integration/AlertRateSourceDatabaseTest.php`, which runs the real
   writer on MariaDB.

   The port returns `calculated` only. `value` holds the raw reading and is not
   a rate. What `calculated` holds depends on the data source type
   (`lib/dsstats.php`):
   - COUNTER, DCOUNTER, DERIVE and DDERIVE: the difference from the previous
     reading divided by the configured `rrd_step` (`:833`, `:858`), not by the
     time that actually elapsed. DCOUNTER and DDERIVE are rounded to whole
     numbers.
   - GAUGE: the reading itself, copied unchanged (`:887-893`).
   - ABSOLUTE: the absolute value of the reading, with no division by the step
     (`:874-880`).
   - Any type: `NULL` for `U`, `nan` or an invalid reading.

2. **Evaluation runs in its own process.** The console command
   `kadupul:alerting:evaluate` evaluates rules. It is launched from the
   `poller_bottom` stage on the main poller through `exec_background()`, so the
   poller does not wait for it. It never runs inside `process_poller_output`,
   which holds the sample-to-RRD path. A named database lock allows one
   evaluator at a time. A second invocation exits at once without evaluating.
   Task 5 chooses between a core call beside `dsstats_poller_bottom()` and a
   hook registration. Either way it launches the command and does not wait.

3. **Rule kinds.**
   - A threshold rule names a series, a comparator (`gt`, `ge`, `lt`, `le`), a
     trigger threshold, a clear threshold and a `for_cycles` count of at
     least 1.
     The clear threshold must lie on the non-breaching side of the trigger
     threshold or equal it. A rule with a gap between the two thresholds has
     hysteresis.
   - A baseline rule names a series, a deviation multiplier and a minimum sample
     count. It breaches when the rate falls outside the hour-of-week mean
     plus or minus the multiplier times the standard deviation. With fewer than
     the minimum samples for that hour of the week, the verdict is `unknown`.
   - Both kinds use the same verdicts, state machine and incident lifecycle.

4. **Unknown is not zero.** Each evaluation of a rule yields one verdict: `ok`,
   `breach` or `unknown`. A series with no fresh rate yields `unknown`. A
   rate is fresh when its observation time (decision 1) is no older than two
   polling intervals of the data source, measured against the evaluator's
   clock. The polling interval is the data source's
   `data_template_data.rrd_step`, the value dsstats divides counter deltas by
   (`lib/dsstats.php:780-790`). A data source with `rrd_step` 0 has no interval
   and yields `unknown`. An observation time more than 60 seconds ahead of the
   evaluator's clock is also not fresh: it would otherwise count as fresh
   until real time passed it and hold the counters that long. Sixty seconds
   allows ordinary clock skew between the poller and the evaluator, which run
   on the same host in Phase 1. Two intervals allow one missed poll. The presence of a
   row never means fresh, and no adapter may default to that. An `unknown`
   verdict neither opens nor closes an incident and does not change the
   consecutive counters. It is shown as such and never counted as `ok` or as
   `breach`.
   A fourth verdict, `hold`, is a rate that lies between the clear and trigger
   thresholds while an incident is open. It counts toward neither breach nor
   recovery and leaves the counters and state unchanged. Later code must never
   treat `hold` as `ok`. A `hold` is a usable observation, so it ends a run of
   `unknown` verdicts: an incident reports `unknown` once per run, and an
   `unknown` that follows a `hold` starts a new run.

5. **State machine and counters.** Per rule, `alert_rule_state` keeps the
   consecutive breach count, the consecutive ok count, the last observation time
   and the current state. Counters advance only when the observation time is
   newer than the stored one, so two evaluator runs between polls do not count
   one sample twice. An observation that is not newer, including an older one
   written late, is skipped and leaves the state unchanged. An incident opens after `for_cycles` consecutive `breach`
   verdicts, where `breach` means the rate is past the trigger threshold. While
   an incident is open, `ok` means the rate has crossed the clear threshold, and
   the incident closes after `for_cycles` consecutive `ok` verdicts. A rate
   between the clear and trigger thresholds then counts as neither, so the
   state and counters hold. With no open incident, any rate not past the
   trigger threshold is `ok`. Together hysteresis and `for_cycles` stop a value
   that oscillates around the threshold from producing an incident per cycle.

   The state row holds every field the state machine needs: whether an
   incident is open, both consecutive counts, the last observation time, and
   whether the open incident's latest verdict was `unknown`, so a reload does
   not report `unknown` again. A run writes the row only when one of those
   changed; a rule that stays `unknown` is not written every cycle. A stored
   row that no sequence of verdicts can produce (a negative count, an ok run
   on a closed rule, `unknown` on a closed rule, a breach run and an ok run at
   once, counts with no observation time) is refused with an error naming the
   rule, never clamped. Counters stop at 4294967295, the largest value of
   their unsigned column. `ok`, `breach` and `hold` enter the state only with
   their observation time; a missing or stale rate enters as `unknown` and has
   none.

6. **Incident lifecycle.** An incident is `open`, `acknowledged`, `closed` or
   `orphaned`. `open` moves to `acknowledged` by user action. Either state moves
   to `closed` by recovery. At most one incident per rule is open or
   acknowledged: `alert_incident.active_rule_id` equals the rule id while it
   is and is `NULL` afterwards, and its unique key enforces the limit.

   A rule's state change and its incident change are written in one
   transaction, state first, and commit together or not at all. Opening an
   incident when one is already active, or closing one when none is, fails
   that transaction: the two tables disagree only if someone edited them.
   The evaluator does not guess; it rolls the rule back and skips it as
   decision 8 describes. A transaction the caller already holds is not
   committed by the evaluator's stores.

   **Orphans.** Each run first looks for rules whose series no longer
   exists: the `data_local` row is gone; its device is gone or marked
   `deleted` (a remote collector's device is flagged before removal,
   `lib/api_device.php`); or the data source no longer has the rule's RRD
   data source name, compared byte for byte. `host_id` 0 is a data source
   without a device and is not an orphan. The rate source cannot tell a
   deleted series from a missing rate, so this check reads the definitions.
   For such a rule, and for a deleted rule, the active incident moves to
   `orphaned` at the evaluator's clock and the state row is deleted, in one
   transaction, and no notification is queued. Neither write reads the
   state row, so an unreadable one is deleted like any other. Only rules with a state row
   or an active incident are handled, so an orphan is ended once. A rule
   whose series is gone is never evaluated again, even while its last rate is
   still fresh, so it cannot open a new incident.

   Conservative choices for the remaining cases:
   - **A disabled device or data source** still exists. Its rate goes stale,
     its rules are `unknown`, and an open incident stays open and is reported
     `unknown` once. Enabling it again resumes evaluation.
   - **A rule deleted while its incident is open** orphans the incident on the
     next run, whatever removed the rule. The rule-editing use case (Task 7)
     orphans it in the same transaction as the delete; the evaluator's check
     covers any other path.
   - **A series that stays `unknown`** keeps its incident open indefinitely.
     There is no age-out: closing it would report a recovery nobody observed.
     It is visible as `unknown`; an operator may acknowledge it, and it ends
     when collection returns and the rate recovers, or when the rule or data
     source is deleted.

7. **Notifications are queued, then delivered.** In the same database
   transaction that changes an incident, the evaluator inserts a row in
   `alert_notification`. Delivery drains that queue after evaluation, inside the
   remaining time budget, through the existing `AdministrativeMailDelivery`
   port, so Phase 1 recipients are the administrators that port already
   selects. A failed attempt leaves the row queued with an attempt count and a
   next-attempt time. After a bounded number of attempts the row is marked
   `failed` and stays visible. A row is marked `sent` only after the port
   reports success. The evaluator never reports delivery from the act of
   queueing. Task 3 creates the `alert_notification` table; the row is written
   through the same per-rule transaction as the state and incident change.

   Task 4 settles the details:
   - **What is queued.** An incident that opens or recovers queues one row
     with the incident id, the transition, and the rate and threshold that
     decided it (the trigger threshold on opening, the clear threshold on
     recovery). An incident that becomes `unknown` changes no incident row,
     so Phase 1 queues nothing for it. An orphaned incident queues nothing.
   - **One transaction, one lock order.** The rule's transaction locks its
     `alert_rule` row (`SELECT ... FOR UPDATE`), then writes the state, the
     incident and the notification, in that order. A rule deleted after its
     batch was read is found missing under the lock and nothing is written.
     A failed notification insert rolls the state and the incident back with
     it, and the next run counts the same observation again.
   - **Claims.** Rows move `queued` to `sending` to `sent`, `suppressed` or
     `failed`, each final.
     A deliverer claims one row at a time with a single conditional `UPDATE`
     that only matches a due row and raises its attempt count. While a row is
     `sending`, `next_attempt_at` is the end of the claim's lease, and the
     settling `UPDATE` matches that value, so a second deliverer can neither
     claim a held row nor settle another's claim. No statement needs
     `SKIP LOCKED`. The lease is 900 seconds, longer than one SMTP send at the
     largest timeout the mail settings accept (300 seconds per read). A row
     whose lease expires is due again, so delivery is at least once: a
     deliverer that dies after the server accepted the mail, or a send that
     outlasts the lease, can send it twice. A claim that expires on the last
     attempt marks the row `failed`.
   - **Retries.** At most 5 attempts. Attempts 2 to 5 wait 60, 300, 900 and
     1800 seconds after the previous failure. The fifth failure marks the row
     `failed`, logs an error with the row, incident, attempts and error, and
     the row is not retried. An administrator account or address that is
     missing counts as a failed attempt, because an operator can fix it while
     the row still has attempts.
   - **Suppressed.** When administrator notification is disabled
     (`notify_admin` off, Cacti's default) or no primary administrator is set,
     nothing is wrong with the transport and no retry could send the mail. The
     row ends `suppressed` on its first claim with that reason in
     `last_error`, and each delivery run logs one warning with the count of
     rows it suppressed, not one line per row. Turning notification on later
     does not resend suppressed rows.
   - **Errors.** `last_error` holds the exception's class name. Only the mail
     port's own `MailDeliveryFailed` adds its message, which is fixed text; any
     other exception's message could hold a DSN, query or credential and is
     not stored.
   - **Content.** The subject names the rule id and the transition and holds
     no stored text. The HTML body (the administrative mail path is HTML)
     names the rule, the series as device description, data source id and RRD
     data source name, the transition, the value, the threshold, the
     observation time in UTC and the path `/alerting/incidents/{id}`, which the
     incident view in Task 7 must serve. Rules have no name column in Phase 1,
     so the rule is named by its id. Device text is HTML-escaped. Only those
     columns are selected; SNMP credentials, data source titles (which can
     substitute device fields), settings and query text never reach the mail.
   - **Separate use case.** `DeliverNotifications` takes a batch size and a
     time budget, checked between batches as in decision 8. Evaluation never
     waits on mail.

8. **Bounded work.** The command evaluates rules in batches. Both the batch size
   and the total time budget are settings, and the command checks the budget
   between batches. Rules not reached are evaluated first in the next run,
   ordered by oldest evaluation. Task 5 sets defaults from a measurement; this
   record does not fix numbers. Delivery has its own bound inside the same
   budget.

   The orphan sweep (decision 6) that starts each run is capped at the batch
   size: at most one batch of orphans, ended by one bounded `DELETE` and one
   bounded `UPDATE` in one transaction. Handled orphans no longer qualify, so
   the rest are ended by later runs, and the rule batches still run every
   time. The cap is a count, not a charge to the time budget, so the first
   batch always runs as before.

   A failure is classified by what it concerns. A stored record of one rule
   that cannot be used (a rule or state row the domain refuses, or an
   incident row that disagrees with the state) is an `UnusableRuleRecord`.
   That rule is rolled back, logged as a warning with its id and the reason
   (column names, never values), counted as `failed` in the report and
   skipped. The run continues and the cursor moves past it, so it never holds
   back the rules after it, or those before it once the cursor wraps. Each
   pass reaches it again and skips it again until an operator corrects or
   deletes the row. Any other failure, such as a lost connection or a failed
   rate read, is not about one rule: it propagates, the run exits 1 and the
   cursor stays where the last completed batch left it.

9. **Inactive is not ok.** Before evaluating, the command checks that the rate
   source can work: `dsstats_enable` is `on` (a missing setting is off, its
   default) and both `data_source_stats_hourly_last` and
   `data_source_stats_hourly_cache` exist. Boost needs no check, because dsstats
   fills both tables with Boost on (Context). If the check fails, it evaluates
   nothing, writes no verdict, reports the reason `rate_source_unavailable`,
   shown as "inactive: rate source unavailable" in the UI, and exits
   with status 2. Exit status 0 means evaluation completed or alerting is
   disabled; status 1 means an error. An empty table on its own does not mean
   unavailable: after a restart it means `unknown`.

10. **Off by default.** A setting `alerting_enable` defaults to off. The
    upgrade creates tables and nothing else. No evaluator is launched and no
    mail is sent until an administrator turns it on and creates a rule.

11. **Coexistence with THold.** Both may run. Native alerting does not read or
    write THold tables or settings. An administrator who defines the same
    condition in both will receive both notifications. Importing THold rules is
    a later, separate command, and this record does not design it.

12. **Authorization.** Two new realms are added: "Alert Rules" for creating,
    editing and deleting rules, and "Alert Incidents" for viewing and
    acknowledging incidents. Realm ids are taken from unused values in
    `$user_auth_realms` (`include/global_arrays.php:1220`) when Task 7 adds
    them.
    A rule may reference only a data source that the editing user can view, and
    the check uses the same visibility policy as the data-source views. The
    check runs when a rule is saved. Viewing or acknowledging an incident also
    requires the realm and visibility of its data source. Evaluation itself runs
    with no user and does not re-authorize rules. Every entry point enforces
    this, not only the forms.

13. **Schema.** Four InnoDB tables: `alert_rule` (definition and owner),
    `alert_rule_state` (counters and last observation), `alert_incident`
    (lifecycle, timestamps, acknowledging user) and `alert_notification` (queue
    with attempt state, and the rate and threshold of the change it reports). They are added to `cacti.sql` and
    `docs/audit_schema.sql`, and created by a new `install/upgrades/1_2_40.php`.
    `include/cacti_version` moves from 1.2.39 to 1.2.40 the way PR #1136 moved
    it, and the version is written only after every step succeeds so a failed
    upgrade can be retried. Persistent tables are used because counters and
    incidents must survive a restart, unlike the MEMORY source. Times are Unix
    seconds in unsigned integer columns, so no value depends on a session time
    zone. `alert_rule.rrd_name` is `varbinary(19)`, so a rule names one exact
    data source name. The step creates each table only when it is missing and
    then reads its engine, collation, columns and keys back; a table of another
    shape, or one it could not create, stops the upgrade unchanged before the
    version is recorded. The resume cursor of decision 8 is the settings row
    `alerting_evaluation_cursor`, written only when it moves.

14. **Boundaries.** `src/Alerting/Domain` holds `ThresholdRule`, `BaselineRule`,
    `Verdict`, `RuleState` and `Incident`. It imports nothing from Symfony, DBAL
    or legacy code. `Application` holds the use cases and the ports
    `RateSource`, `BaselineSource`, `RuleRepository`, `IncidentRepository` and
    `NotificationQueue`, and `AlertingLog`, through which the use cases log,
    because Application code imports no external implementation. DBAL adapters and the console command live in
    `Infrastructure`. Deptrac must pass with `--fail-on-uncovered` and no
    baseline growth. New files carry `declare(strict_types=1);` and the
    GPL-3.0-or-later header.

15. **No device writes.** Phase 1 reads rates and writes only Kadupul tables and
    mail. It pushes no configuration to devices, and the web tier gets no new
    write credentials toward them.

## Consequences

- **dsstats becomes a prerequisite.** Alerting works only while
  `dsstats_enable` is `on`. Operators who turned dsstats off to save database
  load must turn it on. The UI says so when it is off.
- **Restarts cost cycles, not false alarms.** After a database restart rules are
  `unknown` for one cycle (gauges) or two (counters) and then resume. Open
  incidents stay open through that gap and no recoveries are sent. A restart
  during a real outage delays detection by the same amount.
- **Latency is one poll cycle plus launch time.** The evaluator starts after
  collection, so a breach is seen no sooner than the cycle that produced it.
- **Remote pollers are covered only while online.** Only the main poller
  drains `poller_output` and calls dsstats (`poller.php:864`, `:888`). An online
  remote collector writes its samples into the main database (`cmd.php:124-126`,
  `poller.php:29-31`), and the main poller's query takes rows from every
  collector (`lib/poller.php:936-945`), so those series get rates on the main
  database, up to one cycle late when they arrive after the main poller has
  finished draining. A remote collector in offline or recovery mode writes to
  its own database; its samples reach dsstats only when replayed, with their
  original times, so its series are `unknown` while it is cut off and stale
  replays do not count. This is read from the code; no multi-poller run tests
  it.
- **Duplicate notifications are possible** when THold and native rules overlap.
  Operators control that by not defining both.
- **Notification recipients are administrators only** until a later phase adds
  recipient selection.
- **A skipped cycle inflates a counter rate.** Because `calculated` divides by
  the configured step and not the elapsed time, a missed poll cycle makes the
  next rate about twice the true one. The freshness check does not remove this:
  it tests how old the observation is, not how long ago the previous reading
  was. Threshold rules on counters can breach falsely after a missed cycle.
  Phase 1 accepts this, and a `for_cycles` of 2 or more reduces its effect.
- **The freshness rule depends on the hourly cache table.** If a later change
  stops dsstats writing `data_source_stats_hourly_cache`, every series becomes
  `unknown`, not falsely fresh. Reading it means a grouped scan of that MEMORY
  table for the batch's data sources. Task 5 measures the cost when it sets
  the batch defaults. A clock step backwards on the poller can hold counters
  until time passes the last observation. A clock step forwards makes
  observations stale and `unknown`.
- **Mail failure is visible and bounded.** The queue and attempt counts make
  failed delivery an operator-visible state.
- **Compatibility.** No existing table, hook, URL or setting changes. The schema
  step is additive, and `lts/1.2` is untouched.

### Failure behavior

| Condition | Expected behavior | Proved in |
| --- | --- | --- |
| dsstats off | "inactive: rate source unavailable", exit status 2, no verdicts written | Task 2 |
| Boost enabled | Evaluation works as without Boost; no separate inactive reason | Task 2 |
| Database restart empties the MEMORY table | Rules `unknown` for a cycle or two, no recoveries or breaches | Task 3 |
| Rate source read fails | Error, exit status 1; no verdict written, nothing read as `unknown` | Task 3 |
| Incident write fails after the state was saved | Both roll back; the next run counts the same observation again | Task 3 |
| A rule's stored row is unreadable, or its incident disagrees with its state | That rule is rolled back, logged and counted as `failed`; the other rules are evaluated and the cursor moves past it, every run, until the row is fixed | Task 3 |
| More orphans than one batch | One batch of orphans ended per run; the rest on later runs; rule batches still run | Task 3 |
| Value oscillates around the threshold | Hysteresis and `for_cycles` give one incident | Task 1 |
| Data source or device deleted with an incident open | Incident `orphaned`, no recovery mail | Task 3 |
| Rule deleted with an incident open | Incident `orphaned` on the next run, state removed, no mail | Task 3 |
| Device or data source disabled | Rules `unknown`; an open incident stays open | Task 3 |
| Series `unknown` indefinitely | Incident stays open; no age-out | Task 3 |
| Observation time over 60 s ahead of the clock | `unknown` | Task 3 |
| Mail transport fails | Incident kept, notification stays queued, bounded retries, never marked sent | Task 4 |
| Notification insert fails after the state and incident were written | All three roll back; the next run counts the same observation again | Task 4 |
| Rule deleted after its batch was read | No state, incident or notification is written for it | Task 4 |
| Two deliverers run at once | Each row is claimed and sent by one of them | Task 4 |
| Deliverer dies holding a row | The row is due again after the 900 s lease, so the mail may be sent twice; on the last attempt it is marked `failed` | Task 4 |
| Five attempts fail | Row `failed`, error logged, not retried | Task 4 |
| Administrator notification disabled or unconfigured | Row `suppressed` on its first claim, never retried or sent; one warning per run with the count | Task 4 |
| Administrator account or address missing | Each attempt fails and is recorded; after five the row is `failed` | Task 4 |

## Alternatives

- **Use the `poller_output` hook payload.** It is available at the right moment,
  and evaluation could be inline. It holds raw counters, so the evaluator would
  need its own rollover and rate code, which ADR 0007 forbids duplicating.
  Running inside `process_poller_output` would also put alert work on the
  sample-to-RRD path.
- **Read RRD files.** RRDtool already holds rates. Reading them from the
  evaluator needs one RRDtool call per series each cycle and ties alerting to
  file access on the main poller. Rejected for cost and coupling.
- **Extend THold.** It would reuse an existing rule set, but it is a plugin with
  its own schema and hooks, and Phase 1 is a native module with a tested domain.
  Coexistence with an import command later keeps a migration path.
- **Run the evaluator inline in the poller.** Rejected because the poller must
  not wait for evaluation or mail.
- **A new rate table owned by Kadupul.** It would remove the MEMORY and
  `dsstats_enable` dependency. It also means a second writer on the poll path.
  Rejected while dsstats works under Boost.

## Open questions

- **Boost.** Resolved in Task 2: dsstats fills both tables with Boost on (see
  Context). `tests/Unit/Core/Poller/BoostDsstatsHandoffNativeTest.php` runs the
  production `process_poller_output_batch()` with Boost on and off and sees the
  sample reach dsstats in both.
  `tests/integration/AlertRateSourceDatabaseTest.php`
  (`testReadsCalculatedColumnWithBoostEnabled`) runs the real writer with the
  Boost settings on and reads `calculated` back through the adapter.
- **Observation time.** Resolved in Task 2: the time comes from
  `data_source_stats_hourly_cache.time`, paired with `calculated` by equal
  value (decision 1). The two-interval rule stands, measured in the data
  source's `rrd_step` (decision 4).
- **Baseline data.** Hour-of-week mean and standard deviation need a store. The
  existing dsstats aggregates are hourly, daily, weekly, monthly and yearly,
  not hour of the week. Task 6 decides whether `BaselineSource` is fed from the
  existing aggregates or from a Kadupul-owned table.
- **Remote pollers.** Task 2 answered this from the code (see Consequences).
  A test with a real remote collector is still missing.

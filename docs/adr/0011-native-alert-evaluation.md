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
- **Not known:** whether `dsstats_poller_output()` fills the last-value table
  when Boost is enabled. See Open questions.

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
   rate is fresh when its observation time is no older than two polling
   intervals of the data source. The last-value table has no observation time
   and the source of one is an open question. Until a source is established
   and tested, evaluation treats every series as `unknown`. The presence of a
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
   one sample twice. An incident opens after `for_cycles` consecutive `breach`
   verdicts, where `breach` means the rate is past the trigger threshold. While
   an incident is open, `ok` means the rate has crossed the clear threshold, and
   the incident closes after `for_cycles` consecutive `ok` verdicts. A rate
   between the clear and trigger thresholds then counts as neither, so the
   state and counters hold. With no open incident, any rate not past the
   trigger threshold is `ok`. Together hysteresis and `for_cycles` stop a value
   that oscillates around the threshold from producing an incident per cycle.

6. **Incident lifecycle.** An incident is `open`, `acknowledged`, `closed` or
   `orphaned`. `open` moves to `acknowledged` by user action. Either state moves
   to `closed` by recovery. If the data source or its device no longer exists
   (checked against `data_local` and `host` on each evaluation), the incident
   moves to `orphaned`, its rule state resets, and no recovery notification is
   queued. At most one incident per rule is open or acknowledged.

7. **Notifications are queued, then delivered.** In the same database
   transaction that changes an incident, the evaluator inserts a row in
   `alert_notification`. Delivery drains that queue after evaluation, inside the
   remaining time budget, through the existing `AdministrativeMailDelivery`
   port, so Phase 1 recipients are the administrators that port already
   selects. A failed attempt leaves the row queued with an attempt count and a
   next-attempt time. After a bounded number of attempts the row is marked
   `failed` and stays visible. A row is marked `sent` only after the port
   reports success. The evaluator never reports delivery from the act of
   queueing.

8. **Bounded work.** The command evaluates rules in batches. Both the batch size
   and the total time budget are settings, and the command checks the budget
   between batches. Rules not reached are evaluated first in the next run,
   ordered by oldest evaluation. Task 5 sets defaults from a measurement; this
   record does not fix numbers. Delivery has its own bound inside the same
   budget.

9. **Inactive is not ok.** Before evaluating, the command checks that the rate
   source can work: `dsstats_enable` is `on` and the last-value table exists.
   This check does not detect Boost-induced unavailability. Whether Boost
   needs a check, and what it would be, stays open until Task 2 answers the
   Boost question below. If the check fails, it evaluates nothing, writes no
   verdict, reports "inactive: rate source unavailable" in the UI and exits
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
    with attempt state). They are added to `cacti.sql` and
    `docs/audit_schema.sql`, and created by a new `install/upgrades/1_2_40.php`.
    `include/cacti_version` moves from 1.2.39 to 1.2.40 the way PR #1136 moved
    it, and the version is written only after every step succeeds so a failed
    upgrade can be retried. Persistent tables are used because counters and
    incidents must survive a restart, unlike the MEMORY source.

14. **Boundaries.** `src/Alerting/Domain` holds `ThresholdRule`, `BaselineRule`,
    `Verdict`, `RuleState` and `Incident`. It imports nothing from Symfony, DBAL
    or legacy code. `Application` holds the use cases and the ports
    `RateSource`, `BaselineSource`, `RuleRepository`, `IncidentRepository` and
    `NotificationQueue`. DBAL adapters and the console command live in
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
- **Remote pollers are not covered.** Each poller keeps its own MEMORY tables.
  Phase 1 evaluates the main poller's database, so rules over data sources
  polled by a remote poller yield `unknown`. This is inferred from the code
  layout and Task 2 must confirm it.
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
- **The freshness rule adds a dependency** on a time source for observations,
  which the last-value table lacks.
- **Mail failure is visible and bounded.** The queue and attempt counts make
  failed delivery an operator-visible state.
- **Compatibility.** No existing table, hook, URL or setting changes. The schema
  step is additive, and `lts/1.2` is untouched.

### Failure behavior

| Condition | Expected behavior | Proved in |
| --- | --- | --- |
| dsstats off | "inactive: rate source unavailable", exit status 2, no verdicts written | Task 2 |
| Boost enabled | Open item, no behavior promised until Task 2 resolves the Boost question | Task 2 |
| Database restart empties the MEMORY table | Rules `unknown` for a cycle or two, no recoveries or breaches | Task 3 |
| Value oscillates around the threshold | Hysteresis and `for_cycles` give one incident | Task 1 |
| Data source or device deleted with an incident open | Incident `orphaned`, no recovery mail | Task 3 |
| Mail transport fails | Incident kept, notification stays queued, bounded retries, never marked sent | Task 4 |

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
  It stays an option if the Boost question below is answered no.

## Open questions

- **Boost.** It is not known whether `dsstats_poller_output()` populates
  `data_source_stats_hourly_last` when Boost is enabled. Task 2 resolves it with
  a database contract test against the shipped schema. If the answer is no, this
  record is amended: the rate source decision is revisited, and the last
  alternative above is the likely replacement.
- **Observation time.** The last-value table has no time column. Until this is
  resolved every series is `unknown` (decision 4). Task 2 decides
  whether the adapter takes the time from `data_source_stats_hourly_cache.time`
  (`cacti.sql:1430-1437`) or from another source, and amends decision 4 if the
  two-interval rule needs to change.
- **Baseline data.** Hour-of-week mean and standard deviation need a store. The
  existing dsstats aggregates are hourly, daily, weekly, monthly and yearly,
  not hour of the week. Task 6 decides whether `BaselineSource` is fed from the
  existing aggregates or from a Kadupul-owned table.
- **Remote pollers.** See Consequences. Task 2 confirms how the last-value
  table behaves on a remote poller.

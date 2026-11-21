# 0011. Native alert evaluation

## Status

Proposed. The decisions are implemented on the feat/native-alerting branch
and are not released.

## Context

Kadupul has no alerting of its own. The `Alerting` module in
[the architecture document](../architecture.md) owns "alert rules, evaluation,
incidents and notification intent", but before this ADR `src/Alerting` only
sent administrator mail through `Application/Port/AdministrativeMailDelivery.php`.
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

   The evaluate command sets these details:
   - **Launch.** A core call, not a plugin hook registration:
     `alerting_poller_bottom()` in `lib/alerting.php` is called after
     `dsstats_poller_bottom()` in both main-poller blocks of `poller.php`
     (the end of the run and each sub-minute cycle). Call order is not data
     order: `dsstats_poller_bottom()` only starts `poller_dsstats.php` in the
     background (`lib/dsstats.php`), so the evaluator runs alongside that
     cycle's rate update, not after it (see Consequences). It starts nothing unless
     the poller id is 1 and `alerting_enable` is `on`, and nothing when
     `path_php_binary` is not an absolute path to a file, which it logs. It
     passes `exec_background()` the argument array
     `[<base>/bin/console, kadupul:alerting:evaluate, --no-interaction]`, so
     each argument is quoted and the poller returns at once. The command's
     stdout and stderr go to `/dev/null`, as dsstats' do without its log
     setting, so its warnings and errors are written to `cacti.log` instead
     (decision 9).
   - **Locks.** Two MariaDB/MySQL named locks (`GET_LOCK(name, 0)`) on the
     main connection that the stores use: one for evaluation and one for
     delivery. Each name ends in a hash of the schema name, so two
     installations on one server do not share one. The run releases each in
     a `finally`, and the server drops a lock with its session, so a killed
     run never blocks the next one. The evaluation lock is released before
     delivery starts, so a slow mail server never holds back the next
     cycle's evaluation; a run that finds the delivery lock held evaluates
     and leaves the queue to the other deliverer. A run that finds the
     evaluation lock held exits 0 without evaluating or delivering.
     `AlertEvaluatorRunDatabaseTest` proves this with separate sessions on
     MariaDB 10.11.

3. **Rule kinds.**
   - A threshold rule names a series, a comparator (`gt`, `ge`, `lt`, `le`), a
     trigger threshold, a clear threshold and a `for_cycles` count of at
     least 1.
     The clear threshold must lie on the non-breaching side of the trigger
     threshold or equal it. A rule with a gap between the two thresholds has
     hysteresis.
   - A baseline rule names a series, a deviation multiplier k, a clear
     multiplier, a deviation floor, a direction, a minimum sample count and a
     `for_cycles` count. It breaches when the rate falls outside the
     hour-of-week mean plus or minus k times the standard deviation. With
     fewer than the minimum samples for that hour of the week, the verdict is
     `unknown`.
   - Both kinds use the same verdicts, state machine and incident lifecycle.

   Baseline rules work as follows:
   - **Where the history comes from.** No dsstats table holds it.
     `data_source_stats_hourly` and `_daily` have one row per series, keyed
     `(local_data_id, rrd_name)` with no time column (`cacti.sql`), and each
     run overwrites it: the hourly row is the average of whatever
     `data_source_stats_hourly_cache` still holds
     (`dsstats_insert_hourly_data_into_cache()` in `poller_dsstats.php`), and
     the daily, weekly, monthly and yearly rows are RRDtool averages over the
     last period (`dsstats_write_buffer()` in `lib/dsstats.php`). The cache
     keeps only `dsstats_hourly_duration` minutes. So the evaluator keeps its
     own history in `alert_baseline_sample`: for each series and wall-clock
     hour, the count, mean and sum of squared deviations from the mean (M2)
     of the fresh rates (decision 1)
     it read in that hour, and the newest observation time it counted, so a
     sample read by two runs counts once. History starts when the first baseline rule on a
     series is evaluated; rules on the same series share it.
   - **Sample.** One sample is one week's bucket for the hour. The band for
     an observation reads the bucket of the same wall-clock hour of the week
     in each of the previous 8 weeks (`BaselineRule::HISTORY_WEEKS`), never
     the observation's own week. A week without a bucket for that hour, or
     with an empty one, is not a sample. The minimum sample count is 2 to 8
     weeks and defaults to 4: at least 4 of those 8 weeks must have that
     hour, and the buckets together must hold at least 2 observations.
   - **Band.** The band describes the distribution of individual
     observations, the quantity a rule compares, not the spread of hourly
     means: a rate that swings within every hour but has the same hourly
     mean each week still has a wide band. All observations in the sample
     buckets are pooled with Chan's centered-moment merge. Welford's update
     accumulates each bucket without subtracting large squared sums. The
     sample variance is pooled M2 / (n - 1); the deviation is its square root,
     raised to the rule's absolute deviation floor. Non-finite pooled moments
     give an unknown band rather than a spuriously narrow band.
     The floor is absolute, not a fraction of the mean, because the commonest
     zero-variance series, such as an error counter that is always 0, has a
     mean of 0, where a relative floor is zero again.
   - **Time zone.** The hour of the week is taken in the installation time
     zone: PHP's default time zone in the evaluator, which is the CLI
     `date.timezone` of the PHP binary the poller starts it with
     (`path_php_binary`). Traffic follows local working hours, so UTC would
     put local 09:00 and 10:00 in one hour of the week for half the year.
     Weeks are counted in wall-clock hours, so the same hour a week earlier
     is exactly 168 wall-clock hours back even across a daylight saving
     change, where the real gap is 167 or 169 hours. The consequence: the
     hour the clocks skip in spring has no bucket that year, so that hour of
     the week has one sample fewer for 8 weeks; the hour repeated in autumn
     puts both real hours in one bucket, whose observations cover two hours.
     Changing `date.timezone` shifts every later bucket against the stored
     ones until 8 weeks have passed. An unset `date.timezone` is not an
     error: PHP then uses UTC, so hours of the week are UTC hours with no
     warning. Operators set `date.timezone` in the php.ini of the CLI binary
     named by `path_php_binary` before creating baseline rules.
   - **Direction.** `both` (the default) breaches on either side; `above` and
     `below` breach and clear only on their side and treat the other side as
     ok.
   - **Hysteresis.** The clear multiplier is greater than zero and at most k.
     While an incident is open, the rate is `ok` only strictly inside
     mean +/- clear multiplier x deviation, and `hold` between that and the
     trigger edge, as for a threshold rule. A clear multiplier equal to k has
     no gap. The band moves every hour, so the edges an incident is judged
     against move with it.
   - **Edges.** The trigger edge is strict outward: a rate exactly on it is
     inside. A notification reports the trigger edge (on opening) or clear
     edge (on recovery) on the side of the mean the rate is on; a one-sided
     rule reports its own side.
   - **Batches.** Each batch hands the baseline source the fresh observations
     its baseline rules have not yet counted, once per series: one
     multi-row upsert and one read per 500 series of one wall-clock hour,
     both inside the time budget.
   - **Retention.** Each evaluation run prunes once, after its last batch,
     whether or not any baseline rule remains, so the history of a deleted or
     converted rule, or of a series that went stale, still ages out. A prune
     removes buckets older than the oldest week any band at the run's time
     reads (8 weeks), plus one day of grace
     (`DsstatsBaselineSource::GRACE_HOURS`) because a rate observed in an
     earlier hour can still be fresh (two polling intervals) and its band
     reaches that far further back; a day covers polling intervals of at most
     12 hours (about 11.5 hours across a spring-forward night, when 24
     wall-clock hours are 23 real ones). It removes them oldest first, at most
     5,000 (wall-clock hour, data source) pairs per run
     (`BaselineSource::PRUNE_LIMIT`), read and deleted in chunks of 500. Like
     the orphan sweep it is bounded by a count, not charged to the time
     budget. At one run per 5-minute poll cycle that removes up to 60,000
     pairs an hour, against one new pair per data source and hour, so the
     bound keeps up with up to 60,000 data sources, and a backlog (a long
     disabled period or a time zone change) drains over later runs. Pruning
     runs only when the evaluator runs: with `alerting_enable` off nothing is
     pruned or written, and rows left then stay until alerting is enabled
     again. A run whose rate source is unavailable also prunes nothing.
     Housekeeping never stops alerting: a failed prune (a lock wait timeout, a
     deadlock, a lost connection or an unreadable row) is a
     `BaselinePruneFailed` from the port. The adapter wraps every DBAL
     exception, so a connection-level failure during the prune is contained
     like the others; the next step of the run, taking the delivery lock on the
     same connection, fails on a dead connection and the run exits 1.
     The run has already evaluated its rules; it logs one error naming the
     failure's class, without its message or SQL, sets `pruneFailed` in its
     report and completes with its usual exit status. The rows stay and the
     next run retries them.
   - **Unusable.** A baseline rule row the domain refuses (an unknown kind or
     direction, a missing or out-of-range parameter, or columns of the other
     kind set) is an `UnusableRuleRecord` (decision 8). A history bucket that
     cannot be read leaves its series without history, so its rules are
     `unknown`, not built from the remaining weeks.

4. **Unknown is not zero.** An evaluation of a rule yields `ok`, `breach` or
   `unknown`, or `hold` while an incident is open (below). A series with no
   fresh rate yields `unknown`. A rate is fresh when its observation time
   (decision 1) is no older than two
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
   recovery and leaves both consecutive counts and the open state unchanged.
   Later code must never treat `hold` as `ok`. A `hold` is a usable
   observation, so it does end a run of `unknown` verdicts: an incident reports
   `unknown` once per run, and an `unknown` that follows a `hold` starts a new
   run.

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
     next run, whatever removed the rule. The rule-editing use case
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
   queueing. The schema step creates the `alert_notification` table; the row
   is written through the same per-rule transaction as the state and incident
   change.

   Notification queueing and delivery work as follows:
   - **What is queued.** An incident that opens or recovers queues one row
     with the incident id, the transition, and the rate and threshold that
     decided it (the trigger threshold on opening, the clear threshold on
     recovery). An incident that becomes `unknown` changes no incident row,
     so Phase 1 queues nothing for it. An orphaned incident queues nothing.
   - **One transaction, one lock order.** The rule's transaction locks its
     `alert_rule` row (`SELECT ... FOR UPDATE`) and its state row, then
     writes the state, the incident and the notification, in that order.
     The batch is read outside any transaction, so a rule that was deleted
     or edited, whose series was deleted, or whose state was ended after its
     batch was read is found so under the locks and nothing is written; the
     next run evaluates it as it is then.
     A failed notification insert rolls the state and the incident back with
     it, and the next run counts the same observation again.
   - **Claims.** Rows move `queued` to `sending` to `sent`, `suppressed` or
     `failed`, each final.
     A deliverer claims one row at a time with a single conditional `UPDATE`
     that only matches a due row and raises its attempt count. While a row is
     `sending`, `next_attempt_at` is the end of the claim's lease, and the
     settling `UPDATE` matches that value, so a second deliverer can neither
     claim a held row nor settle another's claim. No statement needs
     `SKIP LOCKED`. The lease is 900 seconds. That is a chosen value, not a
     bound on a send: the SMTP timeout of 300 seconds applies to each read, so
     one send
     can take longer, and the legacy `send_mail()` path is not bounded by it
     at all. Lease ownership also compares the claim's expiry with the
     application clocks of the hosts that deliver, so deliverers on hosts
     whose clocks differ by a large part of the lease can take over a live
     claim. A row
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
     data source name (the series the incident recorded when it opened, so a
     notification queued before its rule changed still names that series),
     the transition, the value, the threshold, the
     observation time in UTC and the incident id with the Alert Incidents
     page and its path under the site, `app.php/alerting/incidents/{id}`,
     which the incident view serves; the mail path has no base URL, so it is
     not a link. For an incident opened by a baseline rule, as the
     incident records its rule's kind, the stored threshold is the band
     edge on the rate's side of the mean, so its mail says "Trigger band edge"
     or "Clear band edge" instead of "threshold". Rules have no name column in Phase 1,
     so the rule is named by its id. Device text is HTML-escaped. Only those
     columns are selected; SNMP credentials, data source titles (which can
     substitute device fields), settings and query text never reach the mail.
   - **Separate use case.** `DeliverNotifications` takes a batch size and a
     time budget, checked between batches as in decision 8. Evaluation never
     waits on mail.
   - **Where it runs.** The administrator mail adapters call
     `read_config_option()`, the legacy user lookup and `send_mail()`, which a
     console process does not load. The command therefore runs
     `DeliverNotifications` in the worker `bin/legacy-alerting-deliver.php`,
     which loads the legacy runtime, boots the kernel and takes the use case
     from the container, the way `admin_email()` already reaches the same
     mail path. The worker gets 20 notifications per batch and what is left
     of the run's budget, and is stopped after that budget plus one 900 s
     lease, when every claim it holds has expired anyway. A worker that does
     not report a delivery makes the run exit 1; it logs its own failure to
     `cacti.log` under `ALERTING` by exception class. Queued notifications are delivered
     even while the rate source is unavailable: they report changes already
     decided.

8. **Bounded work.** The command evaluates rules in batches. Both the batch size
   and the total time budget are settings, and the command checks the budget
   between batches. Rules are read in rule-id order from a stored cursor,
   and a run that stops early resumes after the last rule it completed; the
   cursor wraps to the
   lowest id after the last rule. Delivery has its own bound inside the same
   budget.

   The defaults come from a measurement. The settings are
   `alerting_batch_size` (100 to 5000 rules, default 500) and
   `alerting_time_budget` (10 s to 2 minutes, default 30 s), on the Data
   tab of the settings beside Data Source Statistics. A stored value that is
   not a whole number within the caps (10000 rules, 3600 s) reads as its
   default. The command's `--batch-size`, `--budget` and
   `--delivery-batch-size` options override them for one run.

   The measurement used 5,000 threshold rules on 5,000 data sources, 12
   hourly cache rows per series (60,000 rows), MariaDB 10.11.19 in Docker on
   an Apple M3 Max, reached through a forwarded TCP port, PHP 8.4.25, two
   runs. Reading all 5,000 rates with the freshness join took 80 to 82 ms
   (8.2 to 8.4 ms per 500 keys). A run where every series had a new sample,
   so every rule wrote its state in its own transaction, took 4.5 to 5.1 s at
   batch sizes 250 to 5000 (one warm-up outlier of 7.8 s at 100). A run with
   no new samples, where every rule is skipped, took 145 to 148 ms at 500 or
   more and 348 to 354 ms at 100. Batch 500 is where the read cost stops
   falling, and a 30 s budget leaves room for about six times that rule
   count before a run stops early. The cost is per changed rule, one
   transaction each, so it grows with rule count, not with batch size.

   The rate read was measured again after review on MariaDB 11.8.9 in Docker,
   against a cache of 100,000 data sources with 12 rows each (1.2 million
   rows) and the same 500-key batch. The first form of the freshness read
   grouped the cache by data source. Its primary key is a HASH key that serves
   only a lookup on all three of its columns, so `EXPLAIN` showed `type ALL`
   over 1.2 million rows with a temporary table and a filesort, and the read
   took 98 to 112 ms per 500 keys, growing with the size of the cache. The
   read now looks up each series' newest time through a BTREE key on
   `(local_data_id, rrd_name, time)`, which 1.2.40 adds to the cache. `EXPLAIN`
   shows a `range` on `data_template_data`, a `ref` on the new key and an
   `eq_ref` on `data_source_stats_hourly_last`, the read took 6.1 to 6.7 ms
   per 500 keys on the same table, and the rows returned are identical.

   Baseline rules were measured in the same setup with 5,000 baseline
   rules and 8 weeks of history for every hour (6,725,000 rows, about 370
   MB of data and index), two runs. Reading the history of all 5,000 series
   took 86 to 105 ms (9 to 10.5 ms per 500), and recording 5,000 new samples
   took 49 to 56 ms (about 5 ms per 500). A run in which every rule had a new
   sample took 7.8 to 9.0 s; the same 5,000 rules as threshold rules took 7.1
   to 11.0 s on that host that day, so the per-rule state transaction still
   dominates and the baseline reads add about 150 ms per 5,000 rules. A run
   with no new samples does not call the baseline source and took 171 to 250
   ms (one outlier of 1.8 s). The per-run prune, on the same 5,000 series
   with 8 weeks, the day of grace and 3 hours beyond it (6,855,000 rows),
   removed 5,000 rows in 32 to 48 ms per run over two measurements, and
   took under 1 ms once nothing was left.

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
   with status 2. The poller discards the command's output and status, so
   such a run also logs an `ALERTING WARNING` at most once an hour, recorded
   in the `alerting_inactive_warning_lastrun` setting, which is not copied to
   remote collectors. Exit status 0 means evaluation completed or alerting is
   disabled; status 1 means an error. An empty table on its own does not mean
   unavailable: after a restart it means `unknown`.

   The remaining statuses of `kadupul:alerting:evaluate` are:
   - 0 when `alerting_enable` is off, printing
     `inactive: alerting is disabled (alerting_enable is off).`, with no lock
     taken and no mail sent.
   - 0 when another evaluation holds the lock (decision 2), printing
     `skipped: another alert evaluation is running.`
   - 0 when rules were skipped as unusable (decision 8). The run completed
     and moved past them; each is logged as a warning and the count is
     printed. Exiting non-zero for them would report every later run as
     failed until an operator fixed the rows, and nothing waits on the
     status when the poller starts the command.
   - 1 for an invalid option, an unreachable database, a failed evaluation
     or a delivery worker that does not report success. The output names
     the exception class only: driver messages can name the server or hold
     SQL. A failed evaluation is still followed by delivery when the
     delivery lock is free, so notifications already queued, recoveries
     included, are not held back by a failure that repeats every run; the
     run then exits 1 with the evaluation's error.

   Every warning and error of a run, from the command, the evaluator and
   delivery, is written to `cacti.log` as `ALERTING WARNING: ...` or
   `ALERTING ERROR: ...`, through the same writer that the console's other
   cacti.log lines use, with the main database's log settings. A run started
   by the poller has no other output, so this is how an operator sees an
   unreachable database, an unusable rule or a lost lock. The lines carry ids,
   counts, fixed reasons and exception class names, never exception messages
   or stored values. When the database itself is unreachable, the log
   settings read as their defaults, as `read_config_option()` reads them, and
   the error goes to `log/cacti.log`.

10. **Off by default.** A setting `alerting_enable` defaults to off. The
    upgrade creates tables and nothing else. No evaluator is launched and no
    mail is sent until an administrator turns it on and creates a rule.

11. **Coexistence with THold.** Both may run. Native alerting does not read or
    write THold tables or settings. An administrator who defines the same
    condition in both will receive both notifications. Importing THold rules is
    a later, separate command, and this record does not design it.

12. **Authorization.** Two new realms are added: "Alert Rules" (realm 29) for
    creating, editing and deleting rules, and "Alert Incidents" (realm 30) for
    viewing and acknowledging incidents. Both ids were unused in
    `$user_auth_realms` and lie below the plugin realm range. A fresh install
    grants both to the admin account (`cacti.sql`), and the 1.2.40 upgrade
    grants them to user 1 when that account exists, idempotently; other
    accounts and groups receive them through User Management.
    A rule may reference only a data source that the editing user can view, and
    the check uses the same visibility policy as the data-source views: a data
    source without a device is visible, and one with a device is visible when
    the device is (`ManagementVisibility`). The check runs when a rule is
    saved, in the write transaction, together with the evaluator's existence
    check of the series. Listing, editing and deleting a rule require the
    realm and the visibility of the rule's data source. An incident records
    the series it opened on (`alert_incident.local_data_id` and `rrd_name`,
    copied from the rule in the transaction that opens it), and listing,
    viewing and acknowledging it require the realm and the visibility of that
    recorded series, not of the rule's current one, so moving a rule changes
    nothing about who sees its earlier incidents. The incident page shows the
    rule only while it exists and its current series is visible too; when it
    does not, the page still shows the rule's id (and nothing else about
    it), because the incident row records it. This is a known limitation: the
    id of a rule the user cannot see is visible to a user who can see the
    incident. A data
    source that is gone no longer names its device, so its visibility cannot
    be decided: a rule or incident on it fails closed and is visible only to
    a user with full device access, which is the legacy simple device policy
    (`get_simple_device_perms()`: device policy allow and no device
    exceptions); such a user can still delete the rule. Anything the actor
    cannot see answers as not found. Evaluation
    itself runs with no user and does not re-authorize rules. Every entry point
    enforces this, not only the forms.
    A request with no authenticated user gets 401 on every alerting page,
    as on the other migrated Navigation pages, before any read.
    The pages live at `/alerting/rules` and `/alerting/incidents` and are
    reachable whatever `alerting_enable` says; they state when alerting is off
    or the rate source is unavailable. Rule writes and acknowledgements run on
    the evaluator's connection in audited transactions that take the
    evaluator's lock order (rule, state, incident). Deleting a rule, or saving
    it with another series or kind, ends its state and orphans its active
    incident as decision 6 describes, with no notification; notifications
    already queued are still sent. An acknowledgement records the user and
    time on the incident and in the audit log (`alerting.incident.acknowledge`),
    and repeating it keeps the first one.

13. **Schema.** Five InnoDB tables: `alert_rule` (definition and owner),
    `alert_rule_state` (counters and last observation), `alert_incident`
    (lifecycle, timestamps, acknowledging user and the series and rule kind it opened with), `alert_notification` (queue
    with attempt state, and the rate and threshold of the change it reports)
    and `alert_baseline_sample` (hour-of-week history, decision 3).
    `alert_rule.kind` is `threshold` or `baseline`; a threshold rule sets
    `comparator`, `trigger_threshold` and `clear_threshold`, a baseline rule
    sets the `baseline_` columns, and each leaves the other kind's columns
    `NULL`. `alert_baseline_sample` is keyed by wall-clock hour, data source
    and RRD data source name, so a band's read and the age-out are ranges on
    its first column. They are added to `cacti.sql` and
    `docs/audit_schema.sql`, and created by a new `install/upgrades/1_2_40.php`.
    `install/upgrades/1_2_41.php` then adds nullable centered moments, also
    present in the fresh-install and audit schemas. Old buckets remain but
    cannot provide a band until fresh history warms up. The schema version
    becomes 1.2.41 and is written only after every step succeeds so a failed
    upgrade can be retried. Persistent tables are used because counters and
    incidents must survive a restart, unlike the MEMORY source. Times are Unix
    seconds in unsigned integer columns, so no value depends on a session time
    zone. `alert_rule.rrd_name` is `varbinary(19)`, so a rule names one exact
    data source name. The step creates each table only when it is missing and
    then reads its engine, collation, columns and keys back; a table of another
    shape, or one it could not create, stops the upgrade unchanged before the
    version is recorded. The resume cursor of decision 8 is the settings row
    `alerting_evaluation_cursor`, written only when it moves.
    `replicate_out()` copies the `settings` table to remote collectors, so it
    excludes that row by name: it is the main evaluator's runtime
    state, like the `%_lastrun%` rows already excluded. The alerting switch
    and bounds still replicate; a collector never starts the evaluator,
    because the launch checks the poller id.

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
- **Latency is up to two poll cycles plus launch time.** The evaluator starts
  after collection, so a breach is seen no sooner than the cycle that
  produced it, and usually one cycle later (next consequence).
- **Evaluation races the dsstats update.** The poller starts
  `poller_dsstats.php` and then the evaluator, both in the background, and
  does not chain them. The evaluator usually reads the previous cycle's rates,
  so a breach is typically detected about one cycle after the samples that
  show it. The freshness window of two intervals absorbs that one cycle: the
  previous cycle's sample is still fresh. A dsstats run slower than one
  interval leaves the newest sample older than two intervals at the next
  evaluation, and those series read as `unknown` until dsstats catches up.
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
  `unknown`, not falsely fresh. Reading it means one index seek per series on a
  BTREE key that 1.2.40 adds to that MEMORY table. The measurement in decision 8
  found 6.1 to 6.7 ms per 500 data sources on a cache of 1.2 million rows (12
  rows for each of 100,000 data sources); the table's own primary key is a HASH
  key and could not serve the lookup. A clock step backwards
  on the poller can hold counters until time passes the last observation. A
  clock step forwards makes observations stale and `unknown`.
- **Freshness compares Unix seconds.** The command's clock is
  `SymfonyClock` over Symfony's native clock, read as a Unix timestamp, and
  the observation time is `UNIX_TIMESTAMP()` of the stored sample time, so
  daylight saving changes nothing. A poller whose PHP time zone differs from
  its database session time zone stores every sample shifted by the
  difference: later than the clock by more than 60 s reads as `unknown`, and
  earlier by more than two intervals reads as stale and `unknown`.
- **Baseline rules start `unknown`.** A new baseline rule on a series
  without history is `unknown` for at least 4 weeks (its minimum sample
  count), and history is recorded only while alerting runs. A gap in
  collection or a disabled evaluator costs those weeks' samples, not false
  alarms. The history costs one row per series and hour, about 1,500 rows
  and 75 KB per series at 8 weeks and a day; rows of a series whose rules are deleted
  age out after 8 weeks of evaluator runs, bounded per run (decision 3,
  Retention), and stay while alerting is disabled.
- **A baseline learns slow drift and recurring outages.** The band is the
  last 8 weeks' behaviour, so a gradual change becomes normal, and an outage
  that recurs weekly widens the band for that hour.
- **Mail failure is visible and bounded.** The queue and attempt counts make
  failed delivery an operator-visible state.
- **Compatibility.** No existing table, hook, URL or setting changes. The schema
  step is additive, and `lts/1.2` is untouched.

### Failure behavior

| Condition | Expected behavior | Component |
| --- | --- | --- |
| dsstats off | "inactive: rate source unavailable", exit status 2, no verdicts written | Rate source |
| Boost enabled | Evaluation works as without Boost; no separate inactive reason | Rate source |
| Database restart empties the MEMORY table | Rules `unknown` for a cycle or two, no recoveries or breaches | Stores and evaluator |
| Rate source read fails | Error, exit status 1; no verdict written, nothing read as `unknown` | Stores and evaluator |
| Incident write fails after the state was saved | Both roll back; the next run counts the same observation again | Stores and evaluator |
| A rule's stored row is unreadable, or its incident disagrees with its state | That rule is rolled back, logged and counted as `failed`; the other rules are evaluated and the cursor moves past it, every run, until the row is fixed | Stores and evaluator |
| More orphans than one batch | One batch of orphans ended per run; the rest on later runs; rule batches still run | Stores and evaluator |
| Value oscillates around the threshold | Hysteresis and `for_cycles` give one incident | Rule state machine |
| Baseline rule with fewer than its minimum weeks for this hour | `unknown`; an open incident stays open and is reported `unknown` once | Baseline rules |
| Baseline history row unreadable | That series has no history; its rules are `unknown` | Baseline rules |
| Baseline rule row unreadable or of a mixed kind | That rule is skipped as unusable, as above | Baseline rules |
| Baseline history read or write fails | Error, exit status 1; nothing read as `unknown`, cursor unchanged | Baseline rules |
| Baseline history prune fails | The run completes with its rules evaluated and its usual exit status; one ERROR in cacti.log naming the failure's class; the rows are retried next run | Baseline rules |
| Daylight saving change | The same wall-clock hour of the week is compared; the skipped hour has one sample fewer, the repeated hour shares a bucket | Baseline rules |
| Series that never varied | The deviation floor keeps the band open; no breach on the first small change | Baseline rules |
| Data source or device deleted with an incident open | Incident `orphaned`, no recovery mail | Stores and evaluator |
| Rule deleted with an incident open | Incident `orphaned` on the next run, state removed, no mail | Stores and evaluator |
| Device or data source disabled | Rules `unknown`; an open incident stays open | Stores and evaluator |
| Series `unknown` indefinitely | Incident stays open; no age-out | Stores and evaluator |
| Observation time over 60 s ahead of the clock | `unknown` | Stores and evaluator |
| Mail transport fails | Incident kept, notification stays queued, bounded retries, never marked sent | Notification delivery |
| Notification insert fails after the state and incident were written | All three roll back; the next run counts the same observation again | Notification delivery |
| Rule deleted after its batch was read | No state, incident or notification is written for it | Notification delivery |
| Two deliverers run at once | Each row is claimed and sent by one of them | Notification delivery |
| Deliverer dies holding a row | The row is due again after the 900 s lease, so the mail may be sent twice; on the last attempt it is marked `failed` | Notification delivery |
| Five attempts fail | Row `failed`, error logged, not retried | Notification delivery |
| `alerting_enable` off | Command exits 0 with "inactive: alerting is disabled"; the poller starts nothing | Evaluate command |
| A second evaluator starts while one runs | It exits 0 without evaluating or delivering | Evaluate command |
| An evaluation fails | Queued notifications are still delivered if the delivery lock is free; exit status 1, locks released | Evaluate command |
| Database unreachable | Exit status 1; the exception class only, in the output and as an `ALERTING ERROR:` line in `log/cacti.log` | Evaluate command |
| A remote collector finishes its poll | Nothing is started | Evaluate command |
| The delivery worker fails or reports nothing | Exit status 1; the worker and the command each log the exception class to `cacti.log` | Evaluate command |
| Administrator notification disabled or unconfigured | Row `suppressed` on its first claim, never retried or sent; one warning per run with the count | Notification delivery |
| Administrator account or address missing | Each attempt fails and is recorded; after five the row is `failed` | Notification delivery |

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

- **Boost.** Resolved: dsstats fills both tables with Boost on (see
  Context). `tests/Unit/Core/Poller/BoostDsstatsHandoffNativeTest.php` runs the
  production `process_poller_output_batch()` with Boost on and off and sees the
  sample reach dsstats in both.
  `tests/integration/AlertRateSourceDatabaseTest.php`
  (`testReadsCalculatedColumnWithBoostEnabled`) runs the real writer with the
  Boost settings on and reads `calculated` back through the adapter.
- **Observation time.** Resolved: the time comes from
  `data_source_stats_hourly_cache.time`, paired with `calculated` by equal
  value (decision 1). The two-interval rule stands, measured in the data
  source's `rrd_step` (decision 4).
- **Baseline data.** Resolved: the dsstats aggregates hold one
  rolling row per series and no history, so `BaselineSource` reads a
  Kadupul-owned table, `alert_baseline_sample`, that the evaluator fills
  from the rates it already reads (decision 3).
- **Remote pollers.** The code answers this (see Consequences).
  A test with a real remote collector is still missing.

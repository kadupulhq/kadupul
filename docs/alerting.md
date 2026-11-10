<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Native alerting

Native alerting opens an incident when a data source rate breaches a rule, and
closes it when the rate recovers. It queues a mail for each opening and
recovery. It is off by default. The design and its reasons are in
[ADR 0011](adr/0011-native-alert-evaluation.md); this page covers operation.

Phase 1 reads rates only. It sends mail to administrators and pushes nothing to
devices.

## Enable alerting

1. Turn on Data Source Statistics (`dsstats_enable`). See
   [Data source statistics](#data-source-statistics).
2. Turn on **Enable Native Alert Evaluation** (`alerting_enable`) on the Data
   tab of the settings, next to Data Source Statistics. The same tab holds
   **Alert Rules per Batch** (`alerting_batch_size`, default 500) and **Alert
   Evaluation Time Budget** (`alerting_time_budget`, default 30 seconds).
3. Grant the realms below to the users who manage alerting. A fresh install and
   the 1.2.40 upgrade grant both to the `admin` account.
4. Create rules under Management, Alert Rules.
5. Set up mail and the Primary Admin. See [Who gets mail](#who-gets-mail).

The upgrade only creates tables. Nothing is evaluated and no mail is sent until
step 2 is done and a rule exists. The main poller starts the evaluator after
each polling cycle. Remote collectors never start it.

| Realm | Id | Allows |
| --- | --- | --- |
| Alert Rules | 29 | Create, edit and delete rules |
| Alert Incidents | 30 | View and acknowledge incidents |

A user can create or see a rule only on a data source that user can view. An
incident is visible through the data source it opened on. A rule or incident on
a data source that no longer exists is visible only to a user with full device
access. Anything else answers as not found. A request with no signed-in user
gets 401.

The pages are reachable when alerting is off. They say why nothing is
evaluated.

## Who gets mail

Phase 1 sends to the Primary Admin only. The mail goes out when **Notify
Primary Admin of Issues** (`notify_admin`) is on and the Primary Admin
(`admin_user`) is set, enabled, and has an email address. There is no per-rule
recipient list.

If `notify_admin` is off (the default) or no Primary Admin is set, a queued
notification is marked `suppressed` on its first claim. It is not sent later
when you turn notification on. Each delivery run logs one warning with the
count.

## Data source statistics

Alerting reads the rates that Data Source Statistics computes. It does not
rate raw counters itself. With `dsstats_enable` off, or if the dsstats tables
are missing, alerting is inactive: nothing is evaluated, no verdict is written,
the pages show "inactive: rate source unavailable", and the command exits 2.
Rules are never treated as ok.

Boost works. With Boost on, dsstats still receives every sample, so alerting
needs no extra setting.

Online remote collectors write their samples into the main database, so their
series are covered. A collector in offline or recovery mode writes to its own
database, and its series are `unknown` until its samples are replayed. This is
read from the code; no test runs a real remote collector.

## Detection latency

The poller starts the evaluator and the dsstats update at the same time, so the
evaluator usually reads the previous cycle's rates. Expect a breach to be
detected about one polling cycle after the samples that show it, and at most
about two cycles.

A rate is fresh when its sample is no older than two polling intervals of the
data source (2 x `rrd_step`). An older rate, or none, makes the series
`unknown`. If dsstats takes longer than one interval to run, series read as
`unknown` until it catches up. A sample more than 60 seconds ahead of the
evaluator's clock is also `unknown`.

After a database restart the MEMORY rate table is empty. Rules are `unknown`
for one cycle (gauges) or two (counters), and open incidents stay open. A
restart during a real outage delays detection by the same time.

## Rule kinds

Both kinds share the same verdicts, `for_cycles` count and incident lifecycle.
An incident opens after `for_cycles` consecutive breaches and closes after
`for_cycles` consecutive ok readings.

**Threshold rule.** Names a series, a comparator (`gt`, `ge`, `lt`, `le`), a
trigger threshold, a clear threshold and `for_cycles` (1 or more). The clear
threshold must be on the non-breaching side of the trigger, or equal to it. A
gap between the two is hysteresis: while an incident is open, a rate in the gap
changes nothing. Use hysteresis and `for_cycles` of 2 or more to stop a value
that flaps around the threshold from producing many incidents.

**Baseline rule.** Compares the rate with the same hour of the week in the
previous 8 weeks. It breaches when the rate is outside the mean plus or minus k
times the standard deviation, on the side the rule watches (`above`, `below`
or `both`, the default). A narrower clear multiplier closes it. A deviation
floor, in the series' own unit, keeps a series that never varied from breaching
on its first small change.

- **Warm-up.** A baseline rule is `unknown` until at least 4 of the previous 8
  weeks have data for that hour of the week (the minimum is per rule, 2 to 8).
  A new rule therefore does nothing for at least 4 weeks, and the history grows
  only while alerting runs. Time with alerting off or collection down costs
  those weeks.
- **History size.** The evaluator keeps its own history in
  `alert_baseline_sample`, one row per data source, RRD data source name and
  hour. At 8 weeks and a day that is about 1,500 rows and 75 KB per series. A
  measurement with 5,000 series was 6.7 million rows and about 370 MB of data
  and index. Rules on the same series share the history.
- **Pruning.** Each run removes history older than 8 weeks plus one day, even
  for series with no rule left, at most 5,000 hour and data source pairs per
  run, oldest first. Pruning runs only when the evaluator runs. With alerting
  off, old rows stay until you turn it on again.
- **Time zone.** Hours of the week use the PHP time zone of the binary in
  `path_php_binary`. If `date.timezone` is unset, PHP uses UTC without a
  warning. Set `date.timezone` in the CLI `php.ini` before you create baseline
  rules. Changing it later misaligns history for 8 weeks. Daylight saving
  changes compare the same wall-clock hour.
- **Drift.** The band follows the last 8 weeks, so slow drift becomes normal and
  an outage that recurs weekly widens the band for that hour.

Counter rates are the difference between readings divided by the configured
step, not by elapsed time. After a missed poll cycle the next rate is about
twice the true one, and a threshold rule on a counter can breach falsely. A
`for_cycles` of 2 or more reduces this.

## Incidents

An incident is `open`, `acknowledged`, `closed` or `orphaned`. A rule has at
most one active incident. Acknowledging records the user and time, is audited
(`alerting.incident.acknowledge`) and keeps the first acknowledgement if
repeated.

- A series that stays `unknown` keeps its incident open with no age-out. Closing
  it would report a recovery nobody saw.
- A disabled device or data source keeps its incident open and `unknown`.
- Deleting a rule, a data source or a device ends the open incident as
  `orphaned` with no recovery mail. Changing a rule's data source, RRD data
  source name or kind does the same. A notification already queued is still
  sent. The incident page shows the rule's id even if you cannot see the rule.

## Notification statuses

| Status | Meaning |
| --- | --- |
| `queued` | Waiting to be sent, or waiting to retry |
| `sending` | Claimed by a deliverer for 900 seconds |
| `sent` | The mail transport accepted it |
| `failed` | Five attempts failed. It is logged and not retried |
| `suppressed` | Administrator notification is off or no Primary Admin is set. Never sent |

Retries come 60, 300, 900 and 1800 seconds after the previous failure. A
missing administrator account or address counts as a failed attempt, so fixing
it while attempts remain lets the mail go out.

Delivery is at least once. If a deliverer dies after the mail server accepted a
message, or a send outlasts its 900 second claim, the mail can be sent twice.
The claim time is compared across the application clocks of the hosts that
deliver, so keep them in sync.

Only the incident opening and recovery queue mail. Going `unknown` does not.

## Running the command

The poller runs `kadupul:alerting:evaluate` for you. You can run it by hand with
`php bin/console kadupul:alerting:evaluate`. It evaluates rules in batches,
checks the time budget between batches, then sends queued notifications with
the time left. One evaluator runs at a time, and one deliverer.
`--batch-size`, `--budget` and `--delivery-batch-size` override the settings
for one run.

| Exit | Meaning |
| --- | --- |
| 0 | Evaluated; alerting is off; another evaluation holds the lock; or some rules were skipped as unusable |
| 1 | Invalid option, unreachable database, failed evaluation, or a delivery worker that did not report success |
| 2 | dsstats is off or its tables are missing |

A run started by the poller prints nothing. Every warning and error goes to
`cacti.log` as `ALERTING WARNING:` or `ALERTING ERROR:`. The lines hold ids,
counts, fixed reasons and exception class names, never messages or stored
values.

## Failure modes

| Symptom | Cause | What to do |
| --- | --- | --- |
| Pages say "inactive: rate source unavailable"; command exits 2 | dsstats is off or its tables are missing | Turn on Data Source Statistics |
| `ALERTING WARNING` naming a rule id and column names | The rule row is unusable (unknown kind, out-of-range value, mixed columns) | Correct or delete the rule. The evaluator skips it every run and the run still exits 0 |
| `ALERTING ERROR: Baseline history was not pruned` | A lock wait, deadlock, lost connection or unreadable row during pruning | None unless it repeats. The rules were evaluated and the next run retries. A repeating error means history is growing |
| Command exits 1 with the database unreachable | Database down | Fix the database. The error is in `log/cacti.log` |
| Run exits 0 and prints "skipped: another alert evaluation is running" | The evaluation lock is held | None. If it never clears, look for a stuck evaluator process. The lock goes with its database session |
| Notification stays `queued` and the attempt count rises | Mail transport or administrator account failing | Fix mail settings or the Primary Admin's address before the fifth attempt |
| `ALERTING ERROR` with a notification id after five attempts | The row is `failed` and is not retried | Fix the cause. The incident is unaffected |
| Series show `unknown` for a long time | dsstats not running, slower than one interval, device disabled, or a poller or collector cut off | Check Data Source Statistics and polling |
| Alerting does nothing, no log lines | `alerting_enable` off, or `path_php_binary` is not an absolute path to a file (logged) | Check both |

## THold

Native alerting and THold are independent. Native alerting does not read or
write THold tables or settings. If you define the same condition in both, you
get both notifications. Importing THold rules is not part of Phase 1.

## Known limitations

- Recipients are the Primary Admin only.
- Notifications are delivered at least once, not exactly once.
- The incident page shows a rule's id to a user who cannot see the rule.
- Saving a rule needs only the Alert Rules realm and visibility of the data
  source, not the Devices or Data Sources realm.
- A rule has no name; it is identified by its id.
- Remote collectors are covered only while online.
- Counter rates after a missed poll cycle are inflated.
- Evaluation competes with the poller for the database while both run.
- Orphaning lags by up to one run.
- Not measured: the effect of 5,000 rules on poll cycle time. Not yet run in CI:
  the browser theme and content security policy suites, and database engines
  other than MariaDB 10.11.

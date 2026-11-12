# Notification channels and on-call design

Date: 2026-11-12. Status: draft for review.

## Intent

Kadupul installs are run by other people around the world. When an alert
fires, they need it to reach the right person, on the platform their team
uses, in their region: PagerDuty or Opsgenie, SMS or voice through a local
carrier, WhatsApp, LINE, Microsoft Teams, Slack, and so on. Today an alert
reaches nobody except, optionally, the administrator's email.

Kadupul gets three things, each built on the one before:

1. **Channels.** An admin configures a provider and its credentials once,
   then sends through it. Providers are built in or added as plugins.
2. **Providers.** Kadupul ships providers that cover each region, using
   Symfony Notifier bridges where they exist and Kadupul's own bridges where
   they don't.
3. **On-call.** PagerDuty-style routing: services, escalation policies,
   schedules, and each person's own contact rules, all configurable.

Everything is configurable by the install's administrators. No routing,
provider, recipient or schedule is hard-coded.

## Goals

- Any alert transition (opened, resolved) can reach any configured
  destination, with retries and a full delivery record.
- Installers and third parties can add providers as plugins without changing
  Kadupul, and those plugins get the same queue, retries, audit and secret
  handling as built-in providers.
- Built-in coverage of each region the project named: US, Canada, Latin
  America, Europe, Asia, Japan, Australia and the Middle East.
- On-call configured the way PagerDuty configures it, so admins who know
  PagerDuty recognise the model.
- Credentials are encrypted at rest, never shown again after saving, and
  never written to logs or the delivery record.

## Non-goals

- No contributions to symfony/symfony. Missing bridges live in Kadupul.
- No inbound replies (acknowledging by replying to an SMS or chat message)
  in the first release of on-call. The design leaves room for it.
- No mobile app.
- No billing, usage limits or per-provider cost controls.

## Anti-goals (failure even if it technically works)

- An alert that matches no route and silently goes nowhere.
- A plugin that bypasses the queue, retries, audit or secret storage.
- A credential that appears in a log, an exception message, the delivery
  record, a form after saving, or a backup in plain text.
- A provider failure that blocks alert evaluation or other providers.
- Escalation that pages people after the incident was acknowledged or
  resolved.

## Constraints

- PHP 8.4 or later (`composer.json` requires `>=8.4`). Use 8.4 idioms:
  readonly classes and promoted properties, enums with methods, property
  hooks and asymmetric visibility where they replace getters,
  `#[\Override]`, `array_find`/`array_any`/`array_all`, first-class
  callables, `new` in initialisers, and `new Foo()->bar()` without wrapping
  parentheses.
- Symfony 7.4 components. Already installed and to be used: Messenger,
  Scheduler, Workflow, Lock, Clock, HttpClient, Mailer, Form, Validator
  (with Form), Twig, Translation, Console. New: `symfony/notifier` and the
  bridge packages chosen in phase 2.
- Hexagonal domain-driven layout, matching the existing contexts under
  `src/`:
  - `Domain/` is plain PHP. It does not import Symfony, Doctrine or
    any framework class.
  - `Application/` holds use cases and `Port/` interfaces. It may use
    PSR-level contracts but no Symfony component directly.
  - `Infrastructure/` holds the adapters: Dbal stores, Symfony Notifier
    transports, Messenger handlers, Scheduler tasks, Symfony controllers,
    forms and Twig templates.
- Depends on #1474 (native alerting): its notification queue, claims,
  retries and delivery record (`DeliverNotifications`, `NotificationQueue`,
  `ClaimedNotification`) and its incidents. Nothing here starts until #1474
  is merged.
- Schema changes go in a new `install/upgrades/<version>.php` step, the
  same way #1474 adds its tables in `1_2_40.php`, and in `cacti.sql`.
- Every write is audited and rechecks the actor's realm, as the existing
  Symfony editors do (`AuditedPrimaryWrite`, realm checks).
- Strings shown to people go through the `cacti` translation domain.
  Message templates are translatable and per install.
- Licensing: GPL-3.0-or-later for Kadupul code. Bridges may be modelled on
  Symfony's MIT bridges in structure, but must be written fresh.

## Bounded contexts

| Context | Owns | Talks to |
|---|---|---|
| `Alerting` (exists, #1474) | rules, evaluation, incidents, the notification queue | publishes incident transitions |
| `Notification` (new) | channels, providers, plugin registry, secrets, message rendering, delivery | consumes queued notifications; serves `OnCall` |
| `OnCall` (new, phase 3) | services, escalation policies, schedules, users' contact methods and rules, urgency | consumes incident transitions; asks `Notification` to send |

The contexts talk through application ports and domain events, never by
reading each other's tables.

## Part 1: Channels (phase 1)

### Domain (`src/Notification/Domain`)

- `ProviderKey`: a value object, e.g. `twilio-sms`, `pagerduty-events`,
  `whatsapp-cloud`. It is lowercase kebab-case and unique across built-in
  and plugin providers.
- `ProviderKind`: an enum for the shape of a provider:
  - `Message`: sends text to a recipient (SMS, chat, email, push).
  - `IncidentPlatform`: has an incident lifecycle. It triggers,
    acknowledges and resolves under a stable deduplication key
    (PagerDuty, Opsgenie, Splunk On-Call and similar).
  - `Webhook`: a generic signed HTTP POST.
  - `Voice`: places a phone call that reads the message aloud by
    text-to-speech. The outcome is one of answered, no answer, busy or
    failed. On-call notification rules treat "no answer" like "not
    acknowledged".
- `Channel`: an aggregate with an id, a name, a `ProviderKey`, non-secret
  settings, references to secrets, enabled state and a revision. A
  channel is a configured instance of a provider ("Twilio SMS, NOC
  account").
- `Destination`: a channel plus a recipient address in that channel's
  terms (a phone number, a chat id, a PagerDuty routing key, a webhook
  URL).
- `Recipient` addresses are value objects that each provider validates,
  such as an E.164 phone number, an email address or a URL.
- `DeliveryOutcome`: an enum with `Sent`, `RetryableFailure` (with an
  optional retry-after) and `PermanentFailure` (with a reason).
- Domain events: `ChannelConfigured`, `ChannelDisabled`, `SecretRotated`.

### Provider contract (`src/Notification/Application/Port`)

Every provider, built in or plugin, implements one of two interfaces.
`describe()` returns a `ProviderDescription`: its key, kind, display name
(translatable), regions, a settings schema (field name, type, required,
whether it is a secret, validation rules), and recipient format.

- `MessageProvider`: `send(Channel $channel, SecretBag $secrets,
  Recipient $to, RenderedMessage $message): DeliveryOutcome`.
- `IncidentPlatformProvider`: `trigger(...)`, `acknowledge(...)` and
  `resolve(...)`. All three take a `DeduplicationKey` derived from the
  Kadupul incident id, and each returns a `DeliveryOutcome`.
  `acknowledge` is called for the acknowledged transition. Incident
  platforms without an acknowledge API ignore it and report `Sent`.
- `VoiceProvider`: `call(Channel $channel, SecretBag $secrets,
  PhoneNumber $to, RenderedMessage $message): DeliveryOutcome`. Notifier
  has no voice transport, so voice providers are Kadupul bridges calling
  the providers' voice APIs (Twilio Programmable Voice, Vonage Voice API,
  and others in phase 2). The call status arrives asynchronously, so the
  provider polls for it within the delivery's lease and records the
  final status.

The settings schema drives the admin form, so a plugin never ships its own
form or template. A provider gets its secrets only through `SecretBag`,
which exists only for the duration of one call. Providers must not log,
throw or return secret values; the adapter wraps exceptions so that their
messages are never stored unless a redaction pass has run first.

### Symfony Notifier as the transport layer (`Infrastructure/Notifier`)

- `NotifierMessageProvider` adapts any Symfony Notifier
  `TransportFactoryInterface` to `MessageProvider`. It builds the DSN from
  the channel's settings and secrets in memory at send time, creates the
  transport, sends a `SmsMessage` or `ChatMessage`, and maps the result:
  - `TransportExceptionInterface` with HTTP 429 or 5xx becomes
    `RetryableFailure`, honouring `Retry-After`.
  - HTTP 4xx other than 429 becomes `PermanentFailure`.
  - A network error becomes `RetryableFailure`.
- Kadupul does not use Notifier's own channel policy, recipients, async
  transport or `texter`/`chatter` services. It calls transports directly
  inside its own delivery worker, so the #1474 queue stays in charge.
- `MailerMessageProvider` covers email through Symfony Mailer, using the
  existing mail settings.
- `WebhookProvider` is built in: an HTTP POST through HttpClient with a
  JSON body, an HMAC-SHA256 signature header computed with a per-channel
  secret, configurable timeout, and an allowlist that refuses private and
  link-local addresses unless the admin explicitly allows them.

### Plugins

A provider plugin is a Composer package. Discovery:

- The package declares
  `"extra": {"kadupul": {"notifier-providers": ["Vendor\\Pkg\\MyProvider"]}}`
  and is installed into Kadupul's vendor tree.
- A compiler pass (`NotifierProviderPass`) reads
  `vendor/composer/installed.json` when the container is built. It
  registers each listed class as a service tagged
  `kadupul.notifier_provider`, and verifies that the class implements
  `MessageProvider`, `IncidentPlatformProvider` or `VoiceProvider`.
- The container is compiled at install, at upgrade and at cache clear, as
  it is now. Admins add a plugin by running Composer and then clearing the
  cache, which the plugin documentation states.
- A Symfony Notifier bridge package needs only a thin provider class that
  points at its `TransportFactory`.
- A plugin can never take down the install. A class that doesn't
  implement a provider interface, a duplicate key, or a class that throws
  from `describe()` puts that plugin in quarantine instead of failing the
  container build:
  - The compiler pass registers nothing for the quarantined plugin, and
    records why in the container.
  - The admin channel page shows quarantined plugins with the reason.
  - Channels that use a quarantined provider fail their deliveries
    visibly. Alert evaluation and every other provider keep working.
- Plugins run with Kadupul's privileges. Installing one is a trust
  decision, as with existing Kadupul plugins, and the admin page labels
  plugin providers as third-party.

### Secrets

- `SecretVault` port: `store(ChannelId, field, #[\SensitiveParameter]
  string): SecretRef`, `open(ChannelId): SecretBag`, `rotateKey()`.
- `SodiumSecretVault` adapter: XChaCha20-Poly1305
  (`sodium_crypto_aead_xchacha20poly1305_ietf_*`), with the channel id and
  field name as associated data so a ciphertext can't be moved to another
  channel or field.
- The key comes from `KADUPUL_NOTIFIER_KEY` in the environment, or from a
  key file outside the web root with mode 0600, created by the installer.
  It is never `kernel.secret`.
- Every ciphertext records a key id, so the key can be rotated with a
  console command that re-encrypts all rows.
- Memory holding plaintext is wiped with `sodium_memzero` after use where
  PHP allows it.
- Forms never render a stored secret. An empty secret field on save means
  "keep the current value".

### Delivery

- Two levels, so #1474's rows keep their meaning:
  - #1474's `alert_notification` row stays the **event**: one per incident
    transition. #1474 queues only opened and resolved transitions. Phase 1
    adds `acknowledged` as a third transition kind, queued when a Kadupul
    user acknowledges an incident, so incident platforms and other
    destinations learn about it. This is the one change to #1474's
    contract.
  - A new `notification_delivery` table holds one row per event and
    `Destination`, with its own attempts, claim, lease, outcome, redacted
    last error and provider message id.
- An `ExpandNotifications` use case claims due events, resolves their
  destinations (see Routing), inserts the delivery rows, and marks the event
  expanded, all in one transaction.
- `DeliverNotifications` then claims and sends deliveries. It picks the
  provider by `ProviderKey` and maps each `DeliveryOutcome` onto sent,
  retry (with the retry time) or failed.
- Retry limits, delays, leases and the batch budget are those of #1474,
  applied per delivery. A provider's `Retry-After` is used when it is
  longer.
- A `DeliveryQueue` port covers deliveries. `NotificationQueue` keeps its
  methods for events, so #1474's tests still describe its behaviour.
- Existing behaviour is preserved on upgrade. The upgrade step seeds the
  default route with an "administrator email" destination that applies
  #1474's administrator recipients and their notification preferences and
  suppression unchanged. An install that emailed its administrator before
  the upgrade still does.
- Disabled, deleted or failing channels:
  - A delivery whose channel is disabled, or whose provider is missing
    or quarantined, fails at once with a visible reason ("channel
    disabled", "provider unavailable"), never silently. The event's other
    deliveries are unaffected.
  - A channel referenced by any route or delivery can't be hard-deleted,
    only disabled, so the delivery log keeps its history.
  - Each failed delivery raises a warning on the channel list, and the
    admin can retry it from the delivery log.
  - A resolve or acknowledge for a destination whose open delivery failed
    is still attempted, because the incident platform or person may have
    been reached another way.
- The worker runs under Symfony Scheduler and Messenger, with a Lock so
  only one drain runs per install. Slow providers can't stall others: a
  per-provider timeout is enforced through HttpClient, and the batch
  budget from #1474 still applies.
- Idempotency: each delivery has a stable id, sent to providers that
  accept one (PagerDuty `dedup_key`, Opsgenie `alias`, the webhook's
  `X-Kadupul-Delivery` header), so a retry after an unknown outcome can't
  double-page.

### Routing (phase 1)

Routes decide destinations until on-call exists.

- A `NotificationRoute` aggregate holds a name, an order, match conditions
  and a list of `Destination`s.
  - Conditions: rule, device or device tree branch, rule kind,
    transition (opened/resolved), and time windows (days and hours in a
    named IANA time zone).
  - Each route also has a "stop after this route" flag.
- Every matching route sends, unless an earlier match set "stop". A
  destination listed by more than one matching route is sent to once.
- A default route always exists and can't be deleted. An alert that
  matches nothing goes to it, and the admin is warned if the default route
  has no destinations.
- Resolve notifications go to the destinations that received the open
  notification, whatever the routes say now.

### Admin interface

Symfony controllers, Symfony Form and Twig, matching the existing
`/automation/...` editors, under `/notifications/...`:

- Channels: list, create, edit, disable, test send.
- Routes: list, order, create, edit.
- Delivery log: per incident and per channel, with outcome, attempt count
  and the last redacted error, plus a retry action for failed deliveries.
- Message templates: phase 1 ships default templates only, in the
  `cacti` translation domain, so every language Kadupul is translated
  into gets them. A template editor (sandboxed Twig, with a preview
  against a sample incident) comes in a later release.
- A new realm, "Notification Channels", controls these pages. Viewing the
  delivery log needs the Alerting incident realm.

### Message rendering

- `MessageRenderer` port. Templates are per install, per provider kind
  and per transition. They are written in Twig with a sandbox
  (`SecurityPolicy` allowing only the variables listed) and translated
  through the `cacti` domain.
- Variables available: incident id, rule name, rule kind, device, data
  source, value, threshold, observed time (rendered in the install time
  zone), and a link to the incident page.
- Each provider declares a length limit (for example 160 GSM-7 characters
  or 70 UCS-2 characters per SMS segment, and a maximum segment count).
  The renderer truncates to that limit with an ellipsis, without splitting
  a multibyte character.

## Part 2: Providers (phase 2)

Built-in providers are listed by region. "Notifier" means a Symfony 7.4
bridge exists (checked against symfony/symfony `7.4` on 2026-11-12).
"Kadupul" means Kadupul writes the bridge in Notifier's structure under
`src/Notification/Infrastructure/Bridge/<Name>/`, with a
`TransportFactory` and `Transport`, as a plugin would.

| Category | Global, US and Canada | Europe | Latin America | Middle East | Asia | Japan | Australia |
|---|---|---|---|---|---|---|---|
| Incident platforms | PagerDuty (Notifier), Opsgenie (Kadupul), Splunk On-Call (Kadupul), xMatters (Kadupul) | same | same | same | same | same | same |
| SMS and voice | Twilio, Vonage, Plivo, Telnyx, Bandwidth, Sinch, Infobip, Amazon SNS, RingCentral (Notifier) | Esendex, SMSAPI, seven.io, sipgate, GatewayAPI, 46elks, OVHcloud, Brevo, Octopush (Notifier) | Zenvia (Kadupul) | Unifonic (Notifier) | Yunpian (Notifier) | through Twilio, Vonage or Amazon SNS | MessageMedia, ClickSend (Notifier) |
| Messaging apps | Telegram (Notifier), WhatsApp Business Cloud API (Kadupul) | Viber (Kadupul) | WhatsApp (Kadupul) | WhatsApp (Kadupul) | WeCom, DingTalk, Feishu/Lark (Kadupul), KakaoTalk (Kadupul) | LINE Messaging API (Notifier `LineBot`), Chatwork (Notifier) | same as global |
| Team chat | Slack, Microsoft Teams, Google Chat, Discord, Mattermost, Rocket.Chat, Zulip, Matrix (Notifier) | same | same | same | same | same | same |
| Push | Pushover, ntfy, OneSignal, Firebase (Notifier) | same | same | same | same | same | same |
| Always | email (Mailer), webhook | | | | | | |

The Notifier `LineNotify` bridge is excluded because LINE Notify shut down
in 2025.

Each Kadupul bridge ships with:
- tests against recorded HTTP exchanges (Symfony `MockHttpClient`), never
  against live services;
- a description of its settings;
- documentation for obtaining credentials.

Providers ship in batches, ordered by a coverage matrix: one incident
platform, one SMS provider and one messaging app per region first.

## Part 3: On-call (phase 3)

Configured like PagerDuty.

- **Service:** a named thing that alerts belong to, such as "Core
  network". It has an escalation policy, an urgency rule, and optionally
  outbound integrations. Each alert rule, device or tree branch maps to
  one service, and unmapped alerts go to a default service.
- **Escalation policy:** ordered levels. Each level targets users,
  schedules, or outbound destinations (such as a PagerDuty service), and
  has a delay in minutes before the next level. The policy repeats N
  times, then stops and records that it was exhausted.
- **Schedule:** layers of rotations, each with a list of users, a
  rotation length (hours, daily, weekly or custom), a handoff time, and
  optional restrictions to days and hours. Higher layers override lower
  ones, and overrides replace a person for a time range. A schedule has
  its own IANA time zone, and evaluation is correct across daylight saving
  changes.
- **User contact methods and notification rules:** each user adds contact
  methods (a phone number for SMS or voice, a WhatsApp number, a LINE
  user, an email address, a push device), each through an admin-configured
  channel. Each user also sets notification rules per urgency, such as
  "immediately by push; after 5 minutes by SMS; after 10 minutes by
  voice".
  - Self-service, as in PagerDuty. Every user who is a member of any
    schedule or escalation policy can manage their own contact methods
    and rules on a "My on-call profile" page. This needs no realm beyond
    being signed in and on call.
  - An "On-call administration" realm lets admins edit anyone's methods
    and rules, and manage services, policies and schedules.
  - Verification. A contact method is used for paging only after the
    user it belongs to enters a one-time code sent to it.
    - A method an admin enters for someone else stays unverified until
      that user verifies it, and the admin page shows it as unverified.
    - Codes are sent only when that user asks from their own profile.
      Sends are rate-limited per user and per address, and audited.
    - So no one, admins included, can use Kadupul to message an arbitrary
      address through verification.
- **Urgency:** high or low, chosen by service rule or by alert rule. Low
  urgency notifies only inside the service's support hours and doesn't
  escalate outside them.
- **Lifecycle:** an incident is triggered, acknowledged or resolved.
  Acknowledging stops escalation and pending user notification rules.
  Resolving does the same, and notifies everyone who was paged.
  Acknowledgement uses the existing incident acknowledgement in #1474
  (`AcknowledgeOutcome`). A Symfony Workflow state machine mirrors the
  domain transitions, as `config/packages/workflow.yaml` already does for
  monitoring.
- **Timeline:** every page, escalation step, acknowledgement and
  resolution is recorded on the incident with the actor and time.
- **Engine:** a Scheduler task runs every minute. Under a Lock it computes
  due escalation steps and due notification-rule steps, and enqueues
  deliveries through the `Notification` context. Escalation state is
  stored, so a restart resumes the same step instead of starting again.

When phase 3 ships, phase 1 routes become one way to reach a service: a
route can target "service X" instead of a destination.

## Decided by the project

- Configurable everything; nothing hard-coded.
- Built for other people's installs worldwide, with regional providers.
- Plugins by installers and third parties.
- Symfony Notifier as the base for messaging providers; nothing is sent
  upstream to Symfony.
- PagerDuty's configuration model for on-call.
- Symfony components with PHP 8.4 idioms, in a hexagonal domain-driven
  architecture.

## Calls made in this document (not yet confirmed by the project)

- Incident platforms use a Kadupul adapter type with an incident
  lifecycle, not Notifier messages. The PagerDuty Notifier bridge may be
  used underneath if it supports resolve with a deduplication key;
  otherwise Kadupul writes its own.
- Three bounded contexts, with `OnCall` separate from `Notification`.
- Plugin discovery through `extra.kadupul.notifier-providers` in
  `composer.json`, read at container build time.
- Secret key in the environment or a 0600 key file, never `kernel.secret`.
- Routes in phase 1, replaced by services in phase 3.
- A default route that can't be deleted, and resolve messages that follow
  the open message's destinations.
- Webhooks refuse private and link-local addresses by default.
- Inbound replies deferred.
- Phase 1 adds an `acknowledged` transition to #1474's queue, so
  acknowledging in Kadupul acknowledges on incident platforms.
- Two-level queue: #1474's rows stay events, and a new delivery table
  holds one row per destination.
- On upgrade, the default route reproduces today's administrator email
  behaviour.
- Voice calls are in scope as their own provider kind, as Kadupul
  bridges, because PagerDuty-style notification rules include calls.
- Contact methods are self-service, verified only by their owner, and
  rate-limited.
- A broken or duplicate plugin is quarantined, not fatal.
- Phase 1 ships default templates; the template editor comes later.
- Disabled or missing channels fail deliveries visibly, and channels in
  use can only be disabled, not deleted.

## Left to the builder

- Table and column names, indexes, and exact upgrade step numbering.
- Class names beyond the ones named above.
- How the admin forms render the settings schema.
- Batch sizes and per-provider timeouts within the #1474 budget.
- Which providers go in each phase 2 batch, within the coverage rule.

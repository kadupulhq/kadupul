# Notification Channels (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin configure notification channels (provider and encrypted credentials) and routes, and deliver every alerting event to its destinations with retries, a delivery log and plugin providers.

**Architecture:** A new `Notification` bounded context under `src/Notification`, with a plain-PHP `Domain/`, use cases and `Port/` interfaces in `Application/`, and Symfony adapters in `Infrastructure/`:
- Dbal adapters for persistence.
- Notifier, Mailer and HttpClient adapters for sending.
- Scheduler, Lock and Console adapters for the worker.
- Form, Twig and controller adapters for the admin pages.

#1474's `alert_notification` rows become events. A new `notification_delivery` table holds one row per destination.

**Tech Stack:**
- PHP 8.4 and Symfony 7.4: Notifier (new), Mailer, HttpClient, Messenger, Scheduler, Lock, Clock, Form, Twig, Translation and Console.
- Doctrine DBAL and libsodium.
- PHPUnit through `phpunit-symfony.xml`.

**Spec:** `docs/superpowers/specs/2026-11-12-notifier-design.md`, Part 1. Read it before Task 1.

## Global Constraints

- **Prerequisite:** #1474 (`sonar/native-alerting`) is merged into `main`. Start the branch from that `main`.
- **PHP:** `composer.json` requires `php >=8.4`. Use 8.4 idioms:
  - readonly classes and promoted properties;
  - enums with methods;
  - property hooks or asymmetric visibility instead of trivial getters;
  - `#[\Override]` on every interface implementation;
  - `array_find`, `array_any` and `array_all`;
  - `new Foo()->bar()` without wrapping parentheses;
  - `#[\SensitiveParameter]` on every parameter that carries a secret.
- **Layering:**
  - `src/Notification/Domain` imports no `Symfony\`, `Doctrine\` or `Psr\` class.
  - `src/Notification/Application` imports no `Symfony\` or `Doctrine\` class.
  - A test, `NotificationLayeringTest`, enforces both (Task 1).
- **Contexts:** `Notification` reads alerting data only through `Alerting` application ports, never Alerting tables.
- **SPDX headers:**
  - PHP files carry `SPDX-FileCopyrightText: 2026 The Kadupul project and contributors` and `SPDX-License-Identifier: GPL-3.0-or-later`, like the existing `src/Alerting` files.
  - Kadupul bridges are written fresh, not copied from Symfony's MIT bridges.
- **Audited writes:** every write from an admin page goes through `AuditedPrimaryWrite` and rechecks the realm inside the transaction, as `DbalSnmpOptionStore` does.
- **Translation:** user-visible strings use the `cacti` translation domain. Add new msgids to `locales/po/cacti.pot` by running `locales/build_gettext.sh`.
- **Secrets never leave memory as plaintext:**
  - not in logs, exceptions, `notification_delivery.last_error`, rendered forms, the audit trail, the `kernel.secret` or `.env`;
  - the encryption key never comes from `kernel.secret`.
- **Queue limits are #1474's, applied per delivery:**
  - `DeliverNotifications::MAX_ATTEMPTS = 5`;
  - `RETRY_DELAYS = [60, 300, 900, 1800]`;
  - `LEASE_SECONDS = 900`;
  - error text truncated to 255 characters.
- **Commits:** Conventional Commits, `git commit -s`, named files only, no AI attribution, and one changelog fragment per user-visible change in `changelog.d/<slug>.<category>.md`.
- **Security tooling:** after any change to a PHP entry file or a pinned source, run the repository's entry-point classifier and baseline regeneration (see `tests/security/` and `locales/build_gettext.sh`). Commit the updated pins and baselines in the same task.

## Review Focus

1. **Unknown outcome after a lease expiry:** a provider accepted the message but the worker died before recording it. The retry must not page twice. Every send carries the delivery's stable idempotency key. Tested in Task 9 (`testRetryAfterExpiredLeaseReusesIdempotencyKey`) and Task 11 (`X-Kadupul-Delivery` header).
2. **Credentials in exception text:** Notifier transports often put the DSN or the request URL, with its token, into exception messages. Stored errors must be redacted. Tested in Task 10 (`testTransportExceptionTextNeverStoresSecrets`).
3. **Time windows across daylight saving:**
   - A route window of "22:00–06:00 Europe/Berlin" must match correctly on the nights the clocks change.
   - A window naming a time that doesn't exist that night (02:30 on spring-forward) must not crash.
   - Tested in Task 7.
4. **Multibyte message truncation:** a Japanese, Arabic or emoji message cut to an SMS limit must not split a character, and must switch from GSM-7 to UCS-2 counting. Tested in Task 13.
5. **Webhook to internal addresses:** a webhook URL whose host resolves to 127.0.0.1, 169.254.169.254, an RFC 1918 or IPv6 ULA address, or a redirect to one, must be refused unless the admin allowed private targets. Tested in Task 11.

---

### Task 1: Domain model for channels and destinations

**Files:**
- Create in `src/Notification/Domain/`:
  - `ProviderKey.php`
  - `ProviderKind.php`
  - `ChannelId.php`
  - `Channel.php`
  - `ChannelSettings.php`
  - `Recipient.php`
  - `PhoneNumber.php`
  - `EmailAddress.php`
  - `WebhookUrl.php`
  - `OpaqueAddress.php`
  - `Destination.php`
  - `DeliveryOutcome.php`
  - `InvalidNotificationValue.php`
- Test:
  - `tests/Symfony/Notification/Domain/ChannelTest.php`
  - `tests/Symfony/Notification/Domain/RecipientTest.php`
  - `tests/Symfony/Notification/NotificationLayeringTest.php`

**Interfaces:**
- Produces:
  - `final readonly class ProviderKey { public function __construct(public string $value) }`
    - Accepts `/\A[a-z0-9]+(-[a-z0-9]+)*\z/`, 3–64 characters.
    - Throws `InvalidNotificationValue` otherwise.
  - `enum ProviderKind: string { Message='message'; IncidentPlatform='incident_platform'; Voice='voice'; Webhook='webhook' }`
  - `final readonly class ChannelId { public function __construct(public int $value) }`, with `$value >= 1`.
  - `final class Channel`:
    - Properties: `ChannelId $id`, `string $name` (1–100 characters, trimmed), `ProviderKey $provider`, `ChannelSettings $settings`, `bool $enabled`, `int $revision`.
    - Methods: `rename(string): self`, `reconfigure(ChannelSettings): self`, `disable(): self` and `enable(): self`.
    - Each method returns a copy with `revision + 1`.
  - `final readonly class ChannelSettings`:
    - Constructor: `__construct(array<string, string|int|bool> $values)`.
    - Methods: `get(string $name): string|int|bool|null` and `with(string, string|int|bool): self`.
    - It never holds secrets.
  - `interface Recipient { public function value(): string; }`
  - `PhoneNumber`:
    - E.164: `/\A\+[1-9][0-9]{6,14}\z/`.
    - The constructor strips spaces, dashes and parentheses before validating.
  - `EmailAddress`: `filter_var(..., FILTER_VALIDATE_EMAIL)`, at most 254 characters.
  - `WebhookUrl`: the scheme is `https`, or `http` only when the constructor's `allowInsecure: true` is passed, and the URL has a host. Up to 2048 characters.
  - `OpaqueAddress`: 1–512 printable characters, with no control characters. Used for chat ids, routing keys and LINE user ids.
  - `final readonly class Destination { public function __construct(public ChannelId $channel, public Recipient $to) }`
    - `key(): string` returns `channel-id|recipient-class|value`, used for deduplication.
  - `final readonly class DeliveryOutcome`:
    - Static constructors: `sent(?string $providerMessageId = null)`, `retry(string $reason, ?int $retryAfterSeconds = null)` and `fail(string $reason)`.
    - Properties: `DeliveryStatus $status` (an enum with `Sent`, `Retry` and `Failed`), `string $reason`, `?int $retryAfterSeconds` and `?string $providerMessageId`.
    - `$reason` is trimmed to 255 bytes on a UTF-8 boundary.

- [ ] **Step 1: Write the failing tests**

`RecipientTest`:
- `testPhoneNumberNormalisesAndValidatesE164`:
  - `new PhoneNumber('+1 (415) 555-0100')->value() === '+14155550100'`;
  - `'+81 90-1234-5678'` becomes `'+819012345678'`;
  - `'0412 345 678'`, `'+0123'` and `'+1234567890123456'` throw `InvalidNotificationValue`.
- `testWebhookUrlRequiresHttpsUnlessAllowed`:
  - `'http://x.test/h'` throws;
  - `new WebhookUrl('http://x.test/h', allowInsecure: true)` passes;
  - `'https:///nohost'` throws.
- `testOpaqueAddressRefusesControlCharacters`: `"abc\n"` throws.

`ChannelTest`:
- `testEveryChangeIncrementsRevision`;
- `testNameIsTrimmedAndBounded`: `''` throws, and 101 characters throws;
- `testDestinationKeyDistinguishesRecipientTypes`: the same text as a `PhoneNumber` and as an `OpaqueAddress` gives different keys;
- `testOutcomeReasonIsCutOnUtf8Boundary`: a 300-byte reason of `'é'` repeated gives `strlen <= 255` and passes `mb_check_encoding`.

`NotificationLayeringTest::testDomainAndApplicationStayFrameworkFree`: scan every PHP file under `src/Notification/Domain` and `src/Notification/Application` with `token_get_all`, and assert that no `use` statement or fully qualified name starts with `Symfony\` or `Doctrine\`. Domain files also may not use `Psr\`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit -c phpunit-symfony.xml --filter 'RecipientTest|ChannelTest|NotificationLayeringTest'`

Expected: errors, because the classes don't exist.

- [ ] **Step 3: Implement the classes listed in Interfaces**
- [ ] **Step 4: Run the tests to verify they pass** (same command, expected: OK)
- [ ] **Step 5: Commit**: `feat(notification): add the channel and destination domain model`

---

### Task 2: Provider contract and registry with quarantine

**Files:**
- Create in `src/Notification/Application/Port/`:
  - `MessageProvider.php`
  - `IncidentPlatformProvider.php`
  - `VoiceProvider.php`
  - `Provider.php`
  - `ProviderDescription.php`
  - `SettingField.php`
  - `SettingType.php`
  - `SecretBag.php`
  - `RenderedMessage.php`
  - `DeduplicationKey.php`
- Create `src/Notification/Application/ProviderRegistry.php`.
- Create `src/Notification/Application/QuarantinedProvider.php`.
- Test: `tests/Symfony/Notification/Application/ProviderRegistryTest.php`.

**Interfaces:**
- Consumes: Task 1 types.
- Produces:
  - `interface Provider { public function describe(): ProviderDescription; }`
  - `interface MessageProvider extends Provider { public function send(Channel $channel, SecretBag $secrets, Recipient $to, RenderedMessage $message, string $idempotencyKey): DeliveryOutcome; }`
  - `interface IncidentPlatformProvider extends Provider`:
    - `trigger(Channel, SecretBag, Recipient $routingKey, RenderedMessage, DeduplicationKey): DeliveryOutcome`
    - `acknowledge(Channel, SecretBag, Recipient $routingKey, DeduplicationKey): DeliveryOutcome`
    - `resolve(Channel, SecretBag, Recipient $routingKey, DeduplicationKey): DeliveryOutcome`
  - `interface VoiceProvider extends Provider { public function call(Channel, SecretBag, PhoneNumber, RenderedMessage, string $idempotencyKey): DeliveryOutcome; }`
    - Interface only in phase 1; no implementation.
  - `ProviderDescription`:
    - Constructor: `__construct(public ProviderKey $key, public ProviderKind $kind, public string $label, public list<string> $regions, public list<SettingField> $settings, public class-string<Recipient> $recipientType, public int $maxMessageLength, public bool $thirdParty = false)`.
    - `$label` is a translatable msgid.
    - `$regions` uses the codes `global`, `us`, `ca`, `latam`, `eu`, `me`, `asia`, `jp` and `au`.
  - `SettingField`:
    - Constructor: `__construct(public string $name, public SettingType $type, public string $label, public bool $required, public bool $secret, public ?string $pattern = null, public list<string> $choices = [])`.
    - `enum SettingType { Text, Url, Integer, Choice, Boolean }`.
  - `final class SecretBag`:
    - Constructor: `__construct(#[\SensitiveParameter] array<string,string> $values)`.
    - Methods: `get(string): string`, which throws `\OutOfBoundsException` with the field name only, and `wipe(): void`, which calls `sodium_memzero` on each value.
    - `__debugInfo()` returns `['fields' => array_keys(...)]`, and `__serialize()` throws.
  - `RenderedMessage`: `__construct(public string $subject, public string $body, public string $incidentUrl)`.
  - `DeduplicationKey`:
    - `forIncident(int $incidentId, string $installId): self`.
    - `$value` is `kadupul-<installId>-incident-<id>`.
  - `ProviderRegistry`:
    - Constructor: `__construct(iterable<Provider> $providers, list<QuarantinedProvider> $quarantinedAtBuild = [])`.
    - Methods: `get(ProviderKey): ?Provider`, `descriptions(): list<ProviderDescription>` and `quarantined(): list<QuarantinedProvider>`.
  - `QuarantinedProvider`: `__construct(public string $class, public string $package, public string $reason)`.

- [ ] **Step 1: Write the failing tests**

`ProviderRegistryTest`:
- `testDuplicateKeysQuarantineBothProviders`: two fakes describing `twilio-sms` give `get()` null, and `quarantined()` has 2 entries whose reasons contain `'duplicate provider key twilio-sms'`.
- `testProviderWhoseDescribeThrowsIsQuarantined`: the fake throws `RuntimeException('boom')`. The reason contains `boom`, and the other providers still resolve.
- `testBuildTimeQuarantineIsReported`.
- `testSecretBagNeverSerialisesOrDumpsValues`: `print_r($bag, true)` doesn't contain the value, and `serialize($bag)` throws.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit -c phpunit-symfony.xml --filter ProviderRegistryTest`

Expected: errors.

- [ ] **Step 3: Implement the ports and the registry.** The registry calls `describe()` once per provider inside `try`, in its constructor.
- [ ] **Step 4: Run the tests to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): define the provider contract and registry`

---

### Task 3: Encrypted secret vault

**Files:**
- Create `src/Notification/Application/Port/SecretVault.php`.
- Create `src/Notification/Application/Port/SecretRef.php`.
- Create `src/Notification/Infrastructure/Secret/SodiumSecretVault.php`.
- Create `src/Notification/Infrastructure/Secret/NotifierKeyring.php`.
- Create `src/Notification/Infrastructure/Secret/SecretCiphertextStore.php`, the port the Dbal store implements in Task 5.
- Create `src/Notification/Infrastructure/Symfony/RotateNotifierKeyCommand.php`.
- Test: `tests/Symfony/Notification/Infrastructure/SodiumSecretVaultTest.php`.

**Interfaces:**
- Produces:
  - `interface SecretVault`:
    - `store(ChannelId, string $field, #[\SensitiveParameter] string $plaintext): void`
    - `open(ChannelId): SecretBag`
    - `forget(ChannelId, string $field): void`
    - `fields(ChannelId): list<string>`
  - `NotifierKeyring`:
    - Constructor: `__construct(?string $environmentKey, string $keyFile)`.
    - The environment variable `KADUPUL_NOTIFIER_KEY` holds a base64 32-byte key. Failing that, the file `<install>/var/notifier.key` must have mode `0600`; otherwise it throws `RuntimeException('The notifier key file must have mode 0600.')`.
    - The file holds one `id:base64key` line per key, and the last line is current.
    - Methods: `current(): array{string $id, string $key}`, `byId(string): string` and `rotate(): string` (appends a new key and returns its id).
  - `SodiumSecretVault`:
    - XChaCha20-Poly1305 IETF with a random 24-byte nonce.
    - Associated data: `"kadupul-notifier|{$channelId}|{$field}"`.
    - The stored row holds `key_id`, `nonce` and `ciphertext`.
  - `notifier:rotate-key` console command: rotates the key, re-encrypts every row in one transaction, and prints the count of rows re-encrypted.

- [ ] **Step 1: Write the failing tests**

`SodiumSecretVaultTest` uses an in-memory `SecretCiphertextStore` fake and a temporary key file:
- `testRoundTrip`.
- `testCiphertextMovedToAnotherChannelOrFieldFailsToOpen`: copy the row from channel 1 to channel 2, then `open(2)` throws `RuntimeException`, and the message contains neither the plaintext nor the key.
- `testTamperedCiphertextFails`.
- `testKeyFileWithWrongModeIsRefused`: mode 0644 throws the exact message above.
- `testRotationReencryptsAndOldKeyStillOpensUntilRewritten`.
- `testPlaintextNeverInExceptionMessages`: run every failure path and assert that no message contains `'s3cr3t-value'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit -c phpunit-symfony.xml --filter SodiumSecretVaultTest`

- [ ] **Step 3: Implement.** Call `sodium_memzero` on decrypted buffers once they've been copied into a `SecretBag`.
- [ ] **Step 4: Run the tests to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): encrypt channel credentials with libsodium`

---

### Task 4: Schema, realm and default route

**Files:**
- Create `install/upgrades/1_2_41.php`. Use the next number after the latest file in `install/upgrades/` at branch time, and register it the way `1_2_40.php` is registered.
- Modify `cacti.sql` to add the same tables for new installs.
- Modify `include/global_arrays.php`, the realm list, to add the "Notification Channels" realm. Use the next free realm id after 30, checked against `user_auth_realm` usage. Call it `REALM_NOTIFICATION_CHANNELS` in this plan.
- Test: `tests/Symfony/Notification/NotificationSchemaUpgradeTest.php`, in the database suite. Model it on `AlertingSchemaUpgradeTest`, and add it to the `--filter` list in `.github/workflows/ci.yml` next to `AlertingSchemaUpgradeTest`.

**Interfaces:**
- Produces these tables, all InnoDB, utf8mb4_unicode_ci, Dynamic:
  - `notification_channel`: `id` int unsigned auto_increment PK, `name` varchar(100), `provider_key` varchar(64), `settings` JSON stored as mediumtext, `enabled` char(2) default 'on', `revision` int unsigned, `created_at` int unsigned and `updated_at` int unsigned.
  - `notification_channel_secret`: (`channel_id`, `field` varchar(64)) PK, `key_id` varchar(32), `nonce` varbinary(24) and `ciphertext` blob.
  - `notification_route`: `id`, `name`, `position` int unsigned, `conditions` mediumtext JSON, `stop_after` char(2), `is_default` char(2) unique when 'on', `revision`.
  - `notification_route_destination`: `id`, `route_id`, `channel_id`, `recipient_type` varchar(32), `recipient` varchar(2048), and KEY `route_id`.
  - `notification_delivery`:
    - Columns: `id` bigint PK, `notification_id` bigint, `channel_id`, `recipient_type`, `recipient`, `destination_key` varchar(255), `status` varchar(16) default 'queued', `attempts`, `next_attempt_at`, `lease_until` (nullable), `sent_at` (nullable), `last_error` varchar(255), `provider_message_id` varchar(255), `idempotency_key` char(36).
    - Keys: UNIQUE (`notification_id`, `destination_key`), KEY (`status`, `next_attempt_at`), KEY `channel_id`.
  - `alert_notification.status` gains the value `expanded`, a string, so no schema change is needed there.
- The upgrade also:
  - inserts the default route (`is_default='on'`, `position` 0);
  - gives it one destination with `recipient_type='administrator'`, `recipient='*'` and channel id 0, a reserved built-in meaning "#1474 administrator email";
  - grants the new realm to user 1, verified the way `upgrade_alerting_realms()` verifies its grants.

- [ ] **Step 1: Write the failing test**
  - `testUpgradeCreatesTablesMatchingDefinition`: use the signature-comparison helper pattern from `1_2_40.php`.
  - `testDefaultRouteKeepsAdministratorEmail`: exactly one route with `is_default='on'` and one `administrator` destination.
  - `testUpgradeIsIdempotent`: running it twice leaves one default route.
  - `testAdminReceivesRealm`.
- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit-symfony.xml --filter NotificationSchemaUpgradeTest`

Expected: errors. It needs the database test environment; see how CI runs `AlertingSchemaUpgradeTest`.

- [ ] **Step 3: Implement the upgrade, `cacti.sql` and the realm**
- [ ] **Step 4: Run the test to verify it passes**
- [ ] **Step 5: Regenerate the security baselines and pins** for the changed entry and include files, and rebuild the locales
- [ ] **Step 6: Commit**: `feat(notification): add the notification schema, realm and default route`

---

### Task 5: Dbal stores for channels, secrets and routes

**Files:**
- Create in `src/Notification/Application/Port/`:
  - `ChannelStore.php`
  - `ChannelCatalog.php`
  - `RouteStore.php`
  - `RouteCatalog.php`
- Create in `src/Notification/Infrastructure/Dbal/`:
  - `DbalChannelStore.php`
  - `DbalSecretCiphertextStore.php`
  - `DbalRouteStore.php`
  - `NotificationRealmCheck.php`
- Modify `config/services.yaml` to add a `Kadupul\Notification\` resource block. Mirror the `../src/Alerting/` block, including its controller exclusion.
- Test:
  - `tests/Symfony/Notification/Infrastructure/DbalChannelStoreTest.php`
  - `tests/Symfony/Notification/Infrastructure/DbalRouteStoreTest.php`
  - Both run in the database suite, following existing Dbal store tests.

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces:
  - `ChannelStore`:
    - `create(int $actorId, string $name, ProviderKey, ChannelSettings, array<string,string> #[\SensitiveParameter] $secrets): ChannelId`
    - `update(int $actorId, ChannelId, int $expectedRevision, string $name, ChannelSettings, array<string,?string> #[\SensitiveParameter] $secrets): void`. A `null` secret keeps the current value, and `''` clears it.
    - `disable(int $actorId, ChannelId, int $expectedRevision): void`
    - A stale revision throws `StaleChannelRevision`.
  - `ChannelCatalog`: `find(ChannelId): ?Channel`, `all(): list<Channel>` and `isReferenced(ChannelId): bool`.
  - `RouteStore`:
    - `save(int $actorId, NotificationRoute, int $expectedRevision): RouteId`
    - `reorder(int $actorId, list<RouteId>): void`
    - `delete(int $actorId, RouteId): void`. Deleting the default route throws `DefaultRouteIsPermanent`.
  - `RouteCatalog`: `ordered(): list<NotificationRoute>` and `default(): NotificationRoute`.
  - The audit trail entries for channels contain the setting names that changed and `secret fields changed: <names>`, never the values.

- [ ] **Step 1: Write the failing tests**
  - `testCreateStoresSecretsEncryptedAndSettingsPlain`: read the raw row and assert the ciphertext doesn't contain the plaintext.
  - `testNullSecretKeepsValue`.
  - `testStaleRevisionRefused`.
  - `testReferencedChannelCannotBeHardDeleted`: no delete method exists, and `disable` works.
  - `testAuditNamesFieldsNotValues`.
  - `testWriteWithoutRealmIsRefusedInsideTransaction`.
  - `testDefaultRouteCannotBeDeleted`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**, using `AuditedPrimaryWrite` as in `DbalSnmpOptionStore`
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): persist channels, secrets and routes`

---

### Task 6: Acknowledged events from Alerting

**Files:**
- Create `src/Alerting/Application/Port/NotificationKind.php`.
- Modify:
  - `src/Alerting/Application/Port/NotificationQueue.php`
  - `src/Alerting/Application/Port/NotificationContent.php`
  - `src/Alerting/Infrastructure/Dbal/DbalNotificationQueue.php`
  - `src/Alerting/Infrastructure/Dbal/DbalIncidentStore.php`, in `acknowledge()`
  - `tests/Symfony/Alerting/Fakes/InMemoryNotificationQueue.php`
- Test:
  - Extend `tests/Symfony/Alerting/Application/DeliverNotificationsTest.php`.
  - Add `tests/Symfony/Alerting/Infrastructure/AcknowledgementQueuesNotificationTest.php` to the database suite.

**Interfaces:**
- Produces:
  - `enum NotificationKind: string { Opened='opened'; Recovered='recovered'; BecameUnknown='became_unknown'; Acknowledged='acknowledged'; public static function fromTransition(Transition): self }`
    - `fromTransition(Transition::None)` throws.
  - `NotificationQueue::enqueue(int $incidentId, NotificationKind $kind, float $value, float $threshold, int $queuedAt): void`. Existing callers pass `NotificationKind::fromTransition($t)`.
  - `NotificationContent::$kind` is `NotificationKind`, and the rename ripples through `NotificationMail`.
  - `DbalIncidentStore::acknowledge()` enqueues `Acknowledged`, with the incident's last value and threshold, in the same transaction as the acknowledgement.

- [ ] **Step 1: Write the failing tests**
  - `testAcknowledgementQueuesOneEventInSameTransaction`: if the acknowledgement is rolled back, no row exists.
  - `testRepeatAcknowledgeQueuesNothing`: an `AcknowledgeOutcome` of already-acknowledged adds no row.
  - `testAdministratorMailForAcknowledgedIsNotSent`: until routing exists, an acknowledged event is marked sent without email, keeping today's administrator email behaviour.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run every Alerting test**: `vendor/bin/phpunit -c phpunit-symfony.xml tests/Symfony/Alerting`. Expected: OK
- [ ] **Step 5: Commit**: `feat(alerting): queue an event when an incident is acknowledged`

---

### Task 7: Routes and the route resolver

**Files:**
- Create in `src/Notification/Domain/Routing/`:
  - `NotificationRoute.php`
  - `RouteId.php`
  - `RouteCondition.php`
  - `TimeWindow.php`
  - `AlertFacts.php`
- Create `src/Notification/Application/ResolveDestinations.php`.
- Create `src/Notification/Application/Port/DeliveryHistory.php`.
- Test:
  - `tests/Symfony/Notification/Domain/TimeWindowTest.php`
  - `tests/Symfony/Notification/Application/ResolveDestinationsTest.php`

**Interfaces:**
- Produces:
  - `AlertFacts`: `__construct(public int $incidentId, public int $ruleId, public string $ruleKind, public ?int $deviceId, public list<int> $treeBranchIds, public NotificationKind $kind, public \DateTimeImmutable $at)`.
  - `RouteCondition` is a readonly union of optional matchers:
    - `?list<int> $ruleIds`
    - `?list<int> $deviceIds`
    - `?list<int> $treeBranchIds`
    - `?list<string> $ruleKinds`
    - `?list<NotificationKind> $kinds`
    - `?TimeWindow $window`
    - `matches(AlertFacts): bool`. Null means "any".
  - `TimeWindow`:
    - Constructor: `__construct(\DateTimeZone $zone, list<int> $isoDays, string $from, string $to)`. Days are 1–7, and times are `HH:MM`. A window where `$from > $to` spans midnight, and its day is the day it starts.
    - `contains(\DateTimeImmutable): bool` converts to `$zone` first and compares minutes since local midnight, so DST gaps and overlaps never throw.
  - `NotificationRoute`: `__construct(public RouteId $id, public string $name, public int $position, public RouteCondition $condition, public list<Destination> $destinations, public bool $stopAfter, public bool $isDefault, public int $revision)`.
  - `DeliveryHistory::destinationsReachedForOpen(int $incidentId): list<Destination>`. Returns the destinations of the latest `Opened` event's deliveries, whatever their outcome.
  - `ResolveDestinations::__invoke(AlertFacts): list<Destination>`:
    - For `Recovered`, `BecameUnknown` and `Acknowledged`, it returns `DeliveryHistory::destinationsReachedForOpen()` when that is non-empty. Otherwise it uses the routes.
    - For `Opened`, it applies the routes in `position` order. Every match adds its destinations, and a match with `stopAfter` ends the scan.
    - Destinations are deduplicated by `Destination::key()`, keeping the first.
    - When nothing matches, it returns the default route's destinations.

- [ ] **Step 1: Write the failing tests**

`TimeWindowTest`:
- `testOvernightWindowSpansMidnight`: 22:00–06:00. 23:30 Monday is in, and 05:59 Tuesday is in, because Monday's window covers it.
- `testSpringForwardGapDoesNotThrow`: Europe/Berlin on 2026-03-29, with a 02:00–03:00 window. 01:59 local time is out, and 03:00 is out.
- `testFallBackOverlapMatchesBothOccurrences`: 2026-10-25 02:30 Europe/Berlin, at both UTC offsets, matches a 02:00–03:00 window.
- `testTokyoWindowEvaluatedInTokyo`: 09:00–18:00 Asia/Tokyo. 2026-11-12T01:00Z, which is 10:00 JST, is in.

`ResolveDestinationsTest`:
- `testAllMatchingRoutesSendUntilStop`;
- `testDuplicateDestinationsSentOnce`;
- `testNoMatchFallsBackToDefault`;
- `testRecoveryFollowsOpenDestinationsEvenAfterRoutesChange`;
- `testAcknowledgementWithoutOpenHistoryUsesRoutes`.

- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): route alert events to destinations`

---

### Task 8: Expanding events into deliveries

**Files:**
- Create `src/Notification/Application/ExpandNotifications.php`.
- Create `src/Notification/Application/Port/DeliveryQueue.php`.
- Create `src/Notification/Application/Port/AlertFactsSource.php`.
- Create `src/Notification/Infrastructure/Dbal/DbalDeliveryQueue.php`.
- Create `src/Notification/Infrastructure/Dbal/DbalDeliveryHistory.php`.
- Create `src/Notification/Infrastructure/Alerting/AlertingFactsSource.php`, an adapter over Alerting's `IncidentCatalog` and rule catalogs.
- Modify `src/Alerting/Application/Port/NotificationQueue.php` to add `markExpanded(ClaimedNotification $claim): bool`.
- Test:
  - `tests/Symfony/Notification/Application/ExpandNotificationsTest.php`, using fakes;
  - `tests/Symfony/Notification/Infrastructure/DbalDeliveryQueueTest.php`, in the database suite.

**Interfaces:**
- Produces:
  - `DeliveryQueue`:
    - `insertMany(int $notificationId, list<Destination>, int $now): int`. Ignores duplicates of the same (`notification_id`, `destination_key`) and generates a UUIDv4 `idempotency_key` for each row.
    - `failExpiredClaims(int $now, int $maxAttempts): int`
    - `due(int $now, int $maxAttempts, int $limit): list<int>`
    - `claim(int $id, int $now, int $maxAttempts, int $leaseUntil): ?ClaimedDelivery`
    - `markSent(ClaimedDelivery, int $at, ?string $providerMessageId): bool`
    - `markAttemptFailed(ClaimedDelivery, string $error, ?int $retryAt): bool`
  - `ClaimedDelivery`: `__construct(public int $id, public int $notificationId, public Destination $destination, public int $attempts, public string $idempotencyKey, public NotificationContent $content)`.
  - `ExpandNotifications::__invoke(int $batchSize, float $budgetSeconds): int`:
    1. Claim due events through `NotificationQueue`.
    2. Resolve their destinations with Task 7.
    3. Call `insertMany`, then `markExpanded`, in one transaction through Alerting's `TransactionRunner`.
    4. Return the number of deliveries created.
  - An event whose destination list is empty is impossible, because the default route always exists. If it happens anyway, the event is marked failed with `'no destination: the default route has none'` and logged as an error.

- [ ] **Step 1: Write the failing tests**
  - `testEventExpandsToOneDeliveryPerDestinationAtomically`: if the insert throws, the event stays queued.
  - `testReexpandingCreatesNoDuplicates`.
  - `testEmptyDefaultRouteFailsVisibly`.
  - `testIdempotencyKeyIsStablePerDelivery`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): expand alert events into per-destination deliveries`

---

### Task 9: Delivering through providers

**Files:**
- Create `src/Notification/Application/DeliverToDestinations.php`.
- Create `src/Notification/Infrastructure/Provider/AdministratorEmailProvider.php`. It wraps #1474's `NotifyAdministrator`, so that path keeps its preferences and suppression.
- Modify `src/Alerting/Application/RunAlerting.php`. After evaluation it calls `ExpandNotifications`, then `DeliverToDestinations`, instead of `DeliverNotifications`. Keep `DeliverNotifications` only if other callers remain; otherwise delete it and move its constants to `DeliverToDestinations`.
- Test: `tests/Symfony/Notification/Application/DeliverToDestinationsTest.php`.

**Interfaces:**
- Consumes: `ProviderRegistry` (Task 2), `SecretVault` (Task 3), `ChannelCatalog` (Task 5), `DeliveryQueue` (Task 8) and `MessageRenderer` (Task 13, through its port; use a fake renderer until then).
- Produces: `DeliverToDestinations::__invoke(int $batchSize, float $budgetSeconds): DeliveryReport`, reusing #1474's `DeliveryReport` tallies.
  - Channel id 0 with `administrator` goes to `AdministratorEmailProvider`.
  - A missing, disabled or quarantined channel or provider fails at once with `'channel disabled'`, `'channel deleted'` or `'provider unavailable: <key>'`, with no retry.
  - `IncidentPlatformProvider` is called with `trigger` for `Opened`, `acknowledge` for `Acknowledged`, and `resolve` for `Recovered` and `BecameUnknown`.
  - `RetryableFailure` sets the retry time to `now + max(RETRY_DELAYS[attempts-1], retryAfterSeconds ?? 0)`.
  - Every provider call is wrapped in `try/finally` and `$secrets->wipe()`. A `\Throwable` escaping a provider becomes `retry('provider error: ' . get_class($e))`. The exception message is never stored.

- [ ] **Step 1: Write the failing tests**
  - `testOutcomesMapToQueueStates`.
  - `testRetryAfterHeaderExtendsDelay`: with `retryAfterSeconds` 3600, the delay is 3600.
  - `testRetryAfterExpiredLeaseReusesIdempotencyKey`: the fake provider records the keys it receives, and both attempts carry the same key.
  - `testDisabledChannelFailsImmediatelyAndOthersStillSend`.
  - `testIncidentPlatformLifecycleMapping`.
  - `testProviderExceptionMessageIsNeverStored`: the exception message contains `'token=abc'`, and the stored error does not.
  - `testAdministratorEmailBehaviourUnchanged`: port the cases from `DeliverNotificationsTest` that cover suppression and preferences.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run `tests/Symfony/Alerting` and `tests/Symfony/Notification`**. Expected: OK
- [ ] **Step 5: Commit**: `feat(notification): deliver through providers with per-destination retries`

---

### Task 10: Symfony Notifier adapter and the first two bridges

**Files:**
- Modify `composer.json` and `composer.lock`: add `symfony/notifier`, `symfony/twilio-notifier` and `symfony/slack-notifier` at `^7.4`. Twilio is the reference SMS bridge, and Slack the reference chat bridge. The rest come in phase 2.
- Create `src/Notification/Infrastructure/Notifier/NotifierMessageProvider.php`.
- Create `src/Notification/Infrastructure/Notifier/DsnBuilder.php`.
- Create `src/Notification/Infrastructure/Notifier/TransportFailureClassifier.php`.
- Create `src/Notification/Infrastructure/Notifier/Redactor.php`.
- Create `src/Notification/Infrastructure/Provider/TwilioSmsProvider.php`.
- Create `src/Notification/Infrastructure/Provider/SlackProvider.php`.
- Test:
  - `tests/Symfony/Notification/Infrastructure/NotifierMessageProviderTest.php`
  - `tests/Symfony/Notification/Infrastructure/RedactorTest.php`

**Interfaces:**
- Produces:
  - `NotifierMessageProvider`:
    - `abstract class NotifierMessageProvider implements MessageProvider`.
    - Constructor: `__construct(TransportFactoryInterface $factory, HttpClientInterface $http)`.
    - Subclasses implement `describe()` and `dsn(Channel, SecretBag): Dsn`.
    - `send()` builds the DSN in memory, calls `$factory->create($dsn)`, sends `SmsMessage` or `ChatMessage` depending on the description's `recipientType`, and returns `sent($sentMessage->getMessageId())`.
  - `TransportFailureClassifier::classify(\Throwable): DeliveryOutcome` sorts `TransportExceptionInterface` by `getResponse()->getStatusCode()`:
    - 429 or 5xx: `retry`, reading `Retry-After` as seconds or as an HTTP date.
    - Other 4xx: `fail`.
    - No response: `retry`.
  - `Redactor::redact(string $text, SecretBag $secrets, Dsn $dsn): string` removes every secret value, the DSN user and password, every `token=`/`key=`/`password=` query value, and `Authorization` headers. It then truncates to 255 bytes on a UTF-8 boundary.
  - The Notifier `texter`/`chatter` services and the Messenger async routing for Notifier are not configured. `config/packages/notifier.yaml` must not exist.

- [ ] **Step 1: Write the failing tests** (`MockHttpClient` only; no live calls)
  - `testTwilioSendsSmsAndReturnsMessageId`: the mock asserts the POST to `api.twilio.com/.../Messages.json` with `To=+14155550100`.
  - `testRateLimitedIsRetriedWithRetryAfter`: 429 with `Retry-After: 120` gives `retry`, `retryAfterSeconds` 120.
  - `testServerErrorRetries`: 503 gives `retry`.
  - `testClientErrorFails`: 400 gives `fail`.
  - `testNetworkErrorRetries`.
  - `testTransportExceptionTextNeverStoresSecrets`: the mock returns 401 with a body echoing the auth token. The stored reason contains neither the token nor the account SID's password part.
  - `RedactorTest` covers each pattern.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Run `composer require` with the three packages**, then implement
- [ ] **Step 4: Run them to verify they pass.** Also run the repository's vendor-drift and supply-chain checks that CI runs on `composer.lock` changes; see `.github/workflows/vendor-drift.yml`.
- [ ] **Step 5: Commit**: `feat(notification): send through Symfony Notifier, starting with Twilio and Slack`

---

### Task 11: Signed webhook provider

**Files:**
- Create `src/Notification/Infrastructure/Provider/WebhookProvider.php`.
- Create `src/Notification/Infrastructure/Provider/WebhookTargetGuard.php`.
- Test: `tests/Symfony/Notification/Infrastructure/WebhookProviderTest.php`.

**Interfaces:**
- Produces: the provider key `webhook`, kind `Webhook`, and the settings `url` (Url, required), `signing_secret` (secret, required), `allow_private_targets` (Boolean, default false) and `timeout_seconds` (Integer, 1–30, default 10).
  - The request is a POST with a JSON body: `{"event": kind, "incident_id", "rule_id", "rule_kind", "device", "value", "threshold", "observed_at" (RFC 3339 UTC), "url", "delivery_id"}`.
  - Headers:
    - `X-Kadupul-Delivery: <idempotency key>`
    - `X-Kadupul-Timestamp: <unix>`
    - `X-Kadupul-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<body>" with signing_secret>`
  - HttpClient options: `max_redirects => 0` and `timeout => timeout_seconds`.
    - Before connecting, `WebhookTargetGuard::assertAllowed(string $host, bool $allowPrivate): string` resolves the host with `dns_get_record` (A and AAAA). Each address is refused if it is loopback, private (RFC 1918), link-local (169.254/16, fe80::/10), ULA (fc00::/7), CGNAT (100.64/10), 0.0.0.0/8 or multicast. The guard returns one allowed IP.
    - The request pins that IP through HttpClient's `resolve` option, so the address checked is the address used.
  - Status 2xx is `sent`. 3xx is `fail('redirects are not followed')`. Other statuses go through `TransportFailureClassifier`.

- [ ] **Step 1: Write the failing tests**
  - `testSignatureVerifiesWithSecret`: recompute the HMAC in the test.
  - `testDeliveryHeaderCarriesIdempotencyKey`.
  - `testPrivateAndMetadataTargetsRefused`: use a data provider over 127.0.0.1, 10.0.0.5, 172.16.0.1, 192.168.1.1, 169.254.169.254, `[::1]`, `[fd00::1]` and 100.64.0.1. The resolver is injected, so no real DNS is used.
  - `testPrivateTargetAllowedWhenConfigured`.
  - `testRedirectIsNotFollowed`.
  - `testResolvedAddressIsPinned`: the mock asserts the request uses the IP the guard returned.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement.** Inject the resolver as a `\Closure(string): list<string>` that defaults to `dns_get_record`.
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): add a signed webhook provider that refuses internal targets`

---

### Task 12: Plugin discovery

**Files:**
- Create `src/Notification/Infrastructure/Symfony/NotifierProviderPass.php`.
- Modify `src/Kernel.php` to register the pass in `build()`, or in the kernel's existing `process()` if passes are added there.
- Test:
  - `tests/Symfony/Notification/Infrastructure/NotifierProviderPassTest.php`
  - Fixture: `tests/Fixtures/notifier-plugins/installed.json`

**Interfaces:**
- Produces:
  - The pass reads `%kernel.project_dir%/vendor/composer/installed.json`, in both the Composer 2 `{"packages": [...]}` format and the list format.
  - For each package's `extra.kadupul.notifier-providers` class list, it registers each class as an autowired service tagged `kadupul.notifier_provider`.
  - It quarantines the class, without failing the build, when the class doesn't exist, or when it implements none of `MessageProvider`, `IncidentPlatformProvider` or `VoiceProvider`.
  - It passes `list<QuarantinedProvider>` to `ProviderRegistry` as the `$quarantinedAtBuild` argument.
  - Built-in providers (Tasks 9–11) are tagged through `_instanceof` in `services.yaml`.
  - Plugin descriptions are marked `thirdParty: true` by the registry, whatever they declare.

- [ ] **Step 1: Write the failing tests**
  - `testListedProviderIsRegisteredAndTagged`.
  - `testMissingClassIsQuarantinedNotFatal`.
  - `testClassNotImplementingContractIsQuarantined`.
  - `testPluginIsMarkedThirdParty`.
  - `testNoInstalledJsonMeansNoPlugins`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them to verify they pass.** Also boot the real kernel in the test environment (`bin/console debug:container --tag=kadupul.notifier_provider`) and confirm the built-in providers are listed
- [ ] **Step 5: Commit**: `feat(notification): discover provider plugins from Composer metadata`

---

### Task 13: Message rendering

**Files:**
- Create `src/Notification/Application/Port/MessageRenderer.php`.
- Create `src/Notification/Infrastructure/Rendering/TranslatedMessageRenderer.php`.
- Create `src/Notification/Domain/MessageLength.php`.
- Test:
  - `tests/Symfony/Notification/Domain/MessageLengthTest.php`
  - `tests/Symfony/Notification/Infrastructure/TranslatedMessageRendererTest.php`

**Interfaces:**
- Produces:
  - `MessageRenderer::render(NotificationContent, ProviderDescription): RenderedMessage`. It uses the default msgids, one subject and one body per `NotificationKind`, in the `cacti` domain, with placeholders `%rule%`, `%device%`, `%value%`, `%threshold%`, `%observed_at%` and `%url%`.
  - The time is formatted in the install time zone with `IntlDateFormatter` (MEDIUM, SHORT) for the current locale.
  - `MessageLength::fit(string $text, int $maxUnits, bool $sms): string`:
    - For SMS, the text counts as GSM-7 when every character is in the GSM 03.38 basic set or its extension table. Extension characters count 2.
    - Otherwise it counts in UTF-16 code units (UCS-2).
    - It cuts at grapheme boundaries (`grapheme_extract`) and appends `…`. `…` itself forces UCS-2, so when GSM-7 text is cut, the ellipsis is `...`.
    - A description's `maxMessageLength` is the unit limit across all its segments.

- [ ] **Step 1: Write the failing tests**
  - `testGsmTextCountsSevenBitUnitsAndExtensionsAsTwo`: `'€'` counts 2.
  - `testJapaneseSwitchesToUcs2AndIsNotSplit`: `'サーバー停止'` repeated, cut to 70 units, gives valid UTF-8 with no partial grapheme.
  - `testEmojiFamilyIsNotSplit`: `'👨‍👩‍👧'` stays whole or is dropped whole.
  - `testArabicRendersAndFits`.
  - `testRenderUsesInstallTimezone`.
  - `testUnknownPlaceholderIsLeftLiteral`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement, then run `locales/build_gettext.sh`**
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): render translated messages within provider limits`

---

### Task 14: Worker scheduling

**Files:**
- Create `src/Notification/Infrastructure/Symfony/DeliverNotificationsCommand.php` (`notifications:deliver`).
- Create `src/Notification/Infrastructure/Symfony/NotificationSchedule.php`, a `#[AsSchedule('notifications')]` provider that runs the command's handler every 60 seconds.
- Modify the poller launcher that runs #1474's alerting, so that it runs expansion and delivery after evaluation. See `tests/Fixtures/alerting-poller-launcher-stubs.php` for where.
- Test: `tests/Symfony/Notification/Infrastructure/DeliverNotificationsCommandTest.php`.

**Interfaces:**
- Produces: the command options `--batch=50` and `--budget=20` (seconds).
  - It takes a `LockFactory` lock named `kadupul-notification-delivery`, with a TTL equal to the budget plus 60.
  - It exits 0 when another run holds the lock, printing `Another notification delivery is running.`
  - It prints the `DeliveryReport` tallies.

- [ ] **Step 1: Write the failing tests**
  - `testSecondRunExitsWhenLockHeld`.
  - `testRunsExpandThenDeliver`.
  - `testBudgetIsHonoured`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): run delivery on a schedule under a lock`

---

### Task 15: Channel admin pages and test send

**Files:**
- Create `src/Notification/Infrastructure/Symfony/Controller/ChannelController.php`.
- Create `src/Notification/Infrastructure/Symfony/Form/ChannelType.php`, built from the `SettingField` list.
- Create the templates:
  - `templates/notification/channels/index.html.twig`
  - `templates/notification/channels/edit.html.twig`
- Create `src/Notification/Application/SendTestMessage.php`.
- Modify the menu registration that the alerting pages use, so a Notifications entry appears.
- Test: `tests/Symfony/Notification/Infrastructure/ChannelControllerTest.php`, following the existing Symfony controller tests for the alerting pages.

**Interfaces:**
- Routes:
  - `GET /notifications/channels`
  - `GET|POST /notifications/channels/new?provider=<key>`
  - `GET|POST /notifications/channels/{id}/edit`
  - `POST /notifications/channels/{id}/disable`
  - `POST /notifications/channels/{id}/enable`
  - `POST /notifications/channels/{id}/test` (recipient field)
- All of them require console access and `REALM_NOTIFICATION_CHANNELS`, use CSRF token ids `notification_channel_edit` and `notification_channel_action` (add both to `stateless_token_ids` in `config/packages/framework.yaml`), and carry revisions.
- Secret fields:
  - render as `PasswordType` with `always_empty: true`;
  - the help text says "Saved. Leave empty to keep."
- The list shows each provider's label, regions and a "third-party" badge, plus a separate panel of quarantined plugins with their reasons.
- Test send:
  - It sends synchronously through the same provider path as a delivery.
  - It is rate-limited to 5 per channel per 10 minutes through `symfony/rate-limiter`. If that package is not installed, use a counter in `notification_channel`; don't add the dependency only for this.
  - It is audited and shows the redacted outcome.

- [ ] **Step 1: Write the failing tests**
  - `testListRequiresRealm`.
  - `testCreateStoresEncryptedSecretAndRedirects`.
  - `testEditNeverRendersSecret`: the response body doesn't contain the stored value.
  - `testEmptySecretKeepsValue`.
  - `testStaleRevisionShowsConflict`.
  - `testTestSendReportsRedactedOutcome`.
  - `testTestSendRateLimited`.
  - `testQuarantinedPluginsShown`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement, then rebuild the locales and regenerate the entry-point pins and baselines**
- [ ] **Step 4: Run them to verify they pass**, including `tests/security` verifiers run by `regen`-equivalent commands in CI
- [ ] **Step 5: Commit**: `feat(notification): add the channel admin pages and test send`

---

### Task 16: Route admin pages and delivery log

**Files:**
- Create `src/Notification/Infrastructure/Symfony/Controller/RouteController.php`.
- Create `src/Notification/Infrastructure/Symfony/Controller/DeliveryLogController.php`.
- Create the forms and the templates under `templates/notification/routes/` and `templates/notification/deliveries/`.
- Create `src/Notification/Application/RetryDelivery.php`.
- Test:
  - `tests/Symfony/Notification/Infrastructure/RouteControllerTest.php`
  - `tests/Symfony/Notification/Infrastructure/DeliveryLogControllerTest.php`

**Interfaces:**
- Routes:
  - `GET /notifications/routes`
  - `GET|POST /notifications/routes/new`
  - `GET|POST /notifications/routes/{id}/edit`
  - `POST /notifications/routes/order`
  - `POST /notifications/routes/{id}/delete`
  - `GET /notifications/deliveries?incident=&channel=&status=`
  - `POST /notifications/deliveries/{id}/retry`
- Routes need `REALM_NOTIFICATION_CHANNELS`. The delivery log needs Alerting's incident realm (30).
- The time-window editor offers an IANA time zone select (`\DateTimeZone::listIdentifiers()`) that defaults to the install time zone.
- The default route has no delete button. The edit page warns when it has no destinations: "Alerts that match no route will not be delivered."
- `RetryDelivery::__invoke(int $actorId, int $deliveryId): void`:
  - It resets a `failed` delivery to `queued` with attempts 0 and `next_attempt_at = now`.
  - It is audited.
  - It refuses `sent` deliveries.

- [ ] **Step 1: Write the failing tests**
  - `testDefaultRouteCannotBeDeletedFromUi`.
  - `testEmptyDefaultRouteWarns`.
  - `testReorderPersists`.
  - `testDeliveryLogFiltersAndShowsRedactedError`.
  - `testRetryRequeuesFailedOnly`.
  - `testDeliveryLogRequiresIncidentRealm`.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement, then rebuild the locales and regenerate the pins and baselines**
- [ ] **Step 4: Run them to verify they pass**
- [ ] **Step 5: Commit**: `feat(notification): add route editing and the delivery log`

---

### Task 17: Documentation and changelog

**Files:**
- Create `docs/notifications.md`. It covers:
  - concepts: channels, routes, deliveries and the default route;
  - how to set the key (`KADUPUL_NOTIFIER_KEY` or `var/notifier.key` with mode 0600) and rotate it;
  - per-provider credential steps for Twilio, Slack, webhook and administrator email;
  - webhook signature verification, with a 10-line PHP example;
  - how to write a provider plugin: the `composer.json` `extra` block, the interfaces, and clearing the cache after `composer require`;
  - a security note that plugins run with Kadupul's privileges.
- Create `changelog.d/notification-channels.added.md`.
- Modify `docs/README.md` or the docs index to link the new page.
- Test: run `python3 tools/changelog.py check` and the repository's Markdown link check, if any; also check every relative link in `docs/notifications.md`.

- [ ] **Step 1: Write the docs and the fragment**
- [ ] **Step 2: Run `python3 tools/changelog.py check`**. Expected: OK
- [ ] **Step 3: Commit**: `docs(notification): document channels, routes and provider plugins`

---

## Not in this plan

Phase 2 (regional provider bridges, voice providers, Opsgenie and the other incident platforms) and phase 3 (on-call) get their own plans after this one ships, as the spec describes.

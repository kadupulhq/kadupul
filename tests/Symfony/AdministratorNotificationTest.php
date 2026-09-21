<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Alerting\Application\Command\NotifyAdministrator;
use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\AdministrativeMailDelivery;
use Kadupul\Alerting\Application\Port\AdministratorNotificationPreferences;
use Kadupul\Alerting\Application\Port\AdministratorRecipients;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;
use Kadupul\Alerting\Domain\AdministratorNotificationStatus;
use Kadupul\Alerting\Domain\AdministratorRecipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdministratorNotificationTest extends TestCase
{
    public static function suppression(): iterable
    {
        yield 'unset takes priority' => [0, false, null, AdministratorNotificationStatus::NotConfigured, false];
        yield 'disabled' => [1, false, null, AdministratorNotificationStatus::Disabled, false];
        yield 'unknown account' => [1, true, null, AdministratorNotificationStatus::InvalidAccount, true];
        yield 'empty address' => [1, true, new AdministratorRecipient('', 'Admin'), AdministratorNotificationStatus::MissingAddress, true];
    }

    #[DataProvider('suppression')]
    public function testSuppressedNotificationsNeverAttemptDelivery(int $id, bool $enabled, ?AdministratorRecipient $recipient, AdministratorNotificationStatus $expected, bool $lookup): void
    {
        $preferences = $this->createMock(AdministratorNotificationPreferences::class);
        $preferences->expects(self::once())->method('policy')->willReturn(new AdministratorNotificationPolicy($id, $enabled));
        $recipients = $this->createMock(AdministratorRecipients::class);
        $recipients->expects($lookup ? self::once() : self::never())->method('find')->with($id)->willReturn($recipient);
        $delivery = $this->createMock(AdministrativeMailDelivery::class);
        $delivery->expects(self::never())->method('send');
        self::assertSame($expected, (new NotifyAdministrator($preferences, $recipients, $delivery))('Warning', '<b>Body</b>'));
    }

    public function testDeliveryFailureIsNotRetried(): void
    {
        $preferences = $this->createMock(AdministratorNotificationPreferences::class);
        $preferences->method('policy')->willReturn(new AdministratorNotificationPolicy(7, true));
        $recipient = new AdministratorRecipient('admin@example.test', 'Admin');
        $recipients = $this->createMock(AdministratorRecipients::class);
        $recipients->expects(self::once())->method('find')->with(7)->willReturn($recipient);
        $delivery = $this->createMock(AdministrativeMailDelivery::class);
        $delivery->expects(self::once())->method('send')->with($recipient, 'Warning', '<b>Body</b>')->willThrowException(new MailDeliveryFailed());
        $this->expectException(MailDeliveryFailed::class);
        (new NotifyAdministrator($preferences, $recipients, $delivery))('Warning', '<b>Body</b>');
    }

    public static function compatibility(): iterable
    {
        yield 'zero username retains legacy authentication' => [['settings_smtp_username' => '0', 'settings_how' => '2'], [], true, null];
        yield 'port zero' => [['settings_smtp_port' => '0'], [], true, null];
        yield 'port above range' => [['settings_smtp_port' => '65536'], [], true, null];
        yield 'port integer overflow' => [['settings_smtp_port' => '999999999999999999999999'], [], true, null];
        yield 'malformed port' => [['settings_smtp_port' => '25x'], [], true, null];
        yield 'sender name with newline' => [['settings_from_name' => "Primary\nSender"], [], true, null];
        yield 'recipient name with newline' => [[], ['recipient' => ['email_address' => 'admin@example.test', 'full_name' => "Primary\nAdmin"]], true, null];
        yield 'native mail' => [['settings_how' => '0'], [], true, null];
        yield 'implicit sender name lookup' => [['settings_from_name' => ''], [], true, null];
        yield 'sendmail' => [['settings_how' => '1'], [], true, null];
        yield 'failover hosts' => [['settings_smtp_host' => 'first.example;second.example'], [], true, null];
        yield 'host with protocol' => [['settings_smtp_host' => 'tls://smtp.example:587'], [], true, null];
        yield 'legacy subject substitution' => [[], ['subject' => 'Warning |date_time|'], true, null];
        yield 'legacy body substitution' => [[], ['body' => '<SUBJECT>'], true, null];
        yield 'disabled' => [['notify_admin' => ''], [], false, 'notifications disabled'];
        yield 'no admin' => [['admin_user' => '0'], [], false, 'account not set'];
        yield 'unknown admin' => [[], ['recipient' => []], false, 'invalid user'];
        yield 'missing email' => [[], ['recipient' => ['email_address' => '', 'full_name' => 'Admin']], false, 'does not have an email'];
        yield 'lookup failure' => [[], ['lookup_error' => true], false, 'could not be confirmed'];
        yield 'legacy failure' => [['settings_how' => '0'], ['legacy_error' => 'private-smtp-secret'], true, 'could not be confirmed'];
    }

    #[DataProvider('compatibility')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyBridgePreservesPreferencesAndCompatibility(array $settings, array $fixture, bool $legacy, ?string $warning): void
    {
        $GLOBALS['fixture'] = self::fixture($settings, $fixture);
        $GLOBALS['evidence'] = ['logs' => [], 'recipients' => [], 'legacy' => []];
        $_SERVER['APP_ENV'] = 'test';
        $_SERVER['APP_DEBUG'] = '1';
        require __DIR__ . '/admin_notification_functions.php';
        require dirname(__DIR__, 2) . '/include/admin_notifications.php';
        \kadupul_notify_administrator($fixture['subject'] ?? 'Administrative warning', $fixture['body'] ?? '<strong>Storage needs attention.</strong>');
        $result = $GLOBALS['evidence'];
        self::assertCount($legacy ? 1 : 0, $result['legacy']);
        self::assertCount($warning === null ? 0 : 1, $result['logs']);
        if ($warning !== null) {
            self::assertStringContainsString($warning, $result['logs'][0][0]);
            self::assertFalse($result['logs'][0][1]);
            self::assertSame('SYSTEM', $result['logs'][0][2]);
            self::assertStringNotContainsString('private-', json_encode($result['logs']));
        }
        if (isset($settings['notify_admin']) || ($settings['admin_user'] ?? '') === '0') {
            self::assertSame([], $result['recipients']);
        } else {
            self::assertSame([[7]], $result['recipients']);
        }
        if ($legacy) {
            self::assertSame('"' . ($fixture['recipient']['full_name'] ?? 'Primary Admin') . '" <admin@example.test>', $result['legacy'][0][0]);
            $sender = $settings['settings_from_name'] ?? 'Kadupul diagnostic';
            self::assertSame($sender === '' ? 'sender@example.test' : $sender . ' <sender@example.test>', $result['legacy'][0][1]);
            self::assertSame($fixture['subject'] ?? 'Administrative warning', $result['legacy'][0][2]);
            self::assertSame($fixture['body'] ?? '<strong>Storage needs attention.</strong>', $result['legacy'][0][3]);
            self::assertSame(['', '', true], array_slice($result['legacy'][0], 4));
        }
    }

    public function testProductionLegacyNotificationDoesNotRequireApplicationSecret(): void
    {
        $client = new Process([PHP_BINARY, __DIR__ . '/admin_notification_client.php'], dirname(__DIR__, 2), ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => false]);
        $client->setInput(json_encode(self::fixture(['settings_how' => '0'], []), JSON_THROW_ON_ERROR));
        self::assertSame(0, $client->run(), $client->getErrorOutput());
        $result = json_decode($client->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $result['legacy']);
        self::assertSame([], $result['logs']);
    }

    public static function bridge(array $settings, array $fixture = []): array
    {
        $client = new Process([PHP_BINARY, __DIR__ . '/admin_notification_client.php'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $client->setTimeout(15);
        $client->setInput(json_encode(self::fixture($settings, $fixture), JSON_THROW_ON_ERROR));
        self::assertSame(0, $client->run(), $client->getErrorOutput());

        return json_decode($client->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function fixture(array $settings, array $fixture): array
    {
        return array_replace([
            'settings' => array_replace([
                'admin_user' => '7', 'notify_admin' => 'on', 'settings_how' => '2',
                'settings_from_email' => 'sender@example.test', 'settings_from_name' => 'Kadupul diagnostic',
                'settings_smtp_host' => '127.0.0.1', 'settings_smtp_port' => '25', 'settings_smtp_timeout' => '2',
                'settings_smtp_secure' => 'none',
            ], $settings),
            'recipient' => ['email_address' => 'admin@example.test', 'full_name' => 'Primary Admin'],
        ], $fixture);
    }
}

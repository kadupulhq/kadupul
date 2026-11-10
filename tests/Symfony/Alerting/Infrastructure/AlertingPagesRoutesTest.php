<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Infrastructure;

use Kadupul\Alerting\Application\Port\AlertingSettings;
use Kadupul\Alerting\Application\Port\IncidentAccess;
use Kadupul\Alerting\Application\Port\IncidentCatalog;
use Kadupul\Alerting\Application\Port\IncidentStore;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Application\Port\RuleAccess;
use Kadupul\Alerting\Application\Port\RuleCatalog;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\ReadModel\IncidentDetail;
use Kadupul\Alerting\Application\ReadModel\IncidentPage;
use Kadupul\Alerting\Application\ReadModel\IncidentView;
use Kadupul\Alerting\Application\ReadModel\NotificationView;
use Kadupul\Alerting\Application\ReadModel\RulePage;
use Kadupul\Alerting\Application\ReadModel\RuleView;
use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rule and incident routes through the kernel with their ports replaced.
 * Every catalog and store call is recorded, so a refusal can be shown to
 * have happened before any of them.
 */
final class AlertingPagesRoutesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private Kernel $kernel;
    private ?Actor $actor;
    private ?AlertingAccessDenied $denial = null;
    /** @var list<string> */
    private array $reads = [];
    /** @var list<array<int, mixed>> */
    private array $writes = [];
    private RuleStore&MockObject $ruleStore;
    private IncidentStore&MockObject $incidentStore;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        foreach ([RuleAccess::class, IncidentAccess::class] as $port) {
            $access = $this->createStub($port);
            $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denial !== null ? throw $this->denial : $this->actor);
            $container->set($port, $access);
        }

        $rule = new RuleView(7, 101, 'traffic_in', true, 'Core <router>', new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2), null, 'rev-7', 3);
        $rules = $this->createStub(RuleCatalog::class);
        $rules->method('page')->willReturnCallback(function (int $actor, int $page) use ($rule): RulePage {
            $this->reads[] = 'rules.page';
            return new RulePage([$rule], 1, $page, 25);
        });
        $rules->method('find')->willReturnCallback(function (int $actor, int $id) use ($rule): ?RuleView {
            $this->reads[] = 'rules.find';
            return $id === 7 ? $rule : null;
        });
        $container->set(RuleCatalog::class, $rules);
        $incident = new IncidentView(3, new Incident(3, 7, IncidentStatus::Open, self::NOW - 600), $rule, null);
        $incidents = $this->createStub(IncidentCatalog::class);
        $incidents->method('page')->willReturnCallback(function (int $actor, bool $active, int $page) use ($incident): IncidentPage {
            $this->reads[] = 'incidents.page';
            return new IncidentPage([$incident], 1, $page, 25, $active);
        });
        $incidents->method('find')->willReturnCallback(function (int $actor, int $id, int $now) use ($incident): ?IncidentDetail {
            $this->reads[] = 'incidents.find';
            return $id === 3 ? new IncidentDetail($incident, [NotificationView::fromStored(1, 'opened', 'sending', 2, self::NOW - 600, self::NOW - 1, null, 'Mail server <refused>', self::NOW)]) : null;
        });
        $container->set(IncidentCatalog::class, $incidents);
        $this->ruleStore = $this->createMock(RuleStore::class);
        $container->set(RuleStore::class, $this->ruleStore);
        $this->incidentStore = $this->createMock(IncidentStore::class);
        $container->set(IncidentStore::class, $this->incidentStore);
        $settings = $this->createStub(AlertingSettings::class);
        $settings->method('current')->willReturnCallback(fn(): AlertingConfiguration => new AlertingConfiguration($this->enabled));
        $container->set(AlertingSettings::class, $settings);
        $rates = $this->createStub(RateSource::class);
        $rates->method('available')->willReturn(true);
        $container->set(RateSource::class, $rates);
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/kadupul/']);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'rule list' => ['GET', '/alerting/rules'];
        yield 'rule create' => ['GET', '/alerting/rules/new'];
        yield 'rule create submit' => ['POST', '/alerting/rules/new'];
        yield 'rule edit' => ['GET', '/alerting/rules/7/edit'];
        yield 'rule edit submit' => ['POST', '/alerting/rules/7/edit'];
        yield 'rule delete' => ['GET', '/alerting/rules/7/delete'];
        yield 'rule delete submit' => ['POST', '/alerting/rules/7/delete'];
        yield 'incident list' => ['GET', '/alerting/incidents'];
        yield 'incident detail' => ['GET', '/alerting/incidents/3'];
        yield 'incident acknowledge' => ['POST', '/alerting/incidents/3/acknowledge'];
    }

    #[DataProvider('routes')]
    public function testUnauthenticatedRequestsAreRefusedBeforeAnyRead(string $method, string $uri): void
    {
        $this->actor = null;
        $this->expectNoWrites();
        $response = $this->request($method, $uri);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->reads);
    }

    #[DataProvider('routes')]
    public function testRequestsWithoutTheRealmAreRefusedBeforeAnyRead(string $method, string $uri): void
    {
        $this->denial = new AlertingAccessDenied(false);
        $this->expectNoWrites();
        $response = $this->request($method, $uri);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->reads);
        self::assertStringNotContainsString('Core', (string) $response->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'rule list' => ['/alerting/rules'];
        yield 'rule create' => ['/alerting/rules/new'];
        yield 'rule edit' => ['/alerting/rules/7/edit'];
        yield 'rule delete' => ['/alerting/rules/7/delete'];
        yield 'incident list' => ['/alerting/incidents'];
        yield 'incident list, all' => ['/alerting/incidents?status=all'];
        yield 'incident detail' => ['/alerting/incidents/3'];
    }

    #[DataProvider('pages')]
    public function testGrantedActorsSeeThePagesWithStoredTextEscaped(string $uri): void
    {
        $this->expectNoWrites();
        $response = $this->request('GET', $uri);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $content = (string) $response->getContent();
        self::assertStringNotContainsString('<router>', $content);
        self::assertStringNotContainsString('<refused>', $content);
    }

    public function testRulesAndIncidentsOutsideTheActorsViewAreNotFound(): void
    {
        $this->expectNoWrites();
        foreach (['GET /alerting/rules/8/edit', 'POST /alerting/rules/8/edit', 'GET /alerting/rules/8/delete', 'POST /alerting/rules/8/delete', 'GET /alerting/incidents/4', 'POST /alerting/incidents/4/acknowledge', 'GET /alerting/incidents/4294967296'] as $call) {
            [$method, $uri] = explode(' ', $call);
            self::assertSame(404, $this->request($method, $uri, ['_token' => 'csrf-token'])->getStatusCode(), $call);
        }
        self::assertSame(404, $this->request('GET', '/alerting/rules/0/edit')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/alerting/incidents/01')->getStatusCode());
    }

    public function testAnUntouchedRuleFormIsRefusedWithoutAWrite(): void
    {
        $this->expectNoWrites();
        // The defaults a GET renders, posted back unchanged.
        $xpath = $this->xpath($this->request('GET', '/alerting/rules/new'));
        $fields = [];
        foreach ($xpath->query('//form//input[@name] | //form//select[@name]') as $input) {
            \assert($input instanceof \DOMElement);
            $name = $input->getAttribute('name');
            if ($input->tagName === 'select') {
                $selected = $xpath->query('option[@selected]', $input)->item(0) ?? $xpath->query('option', $input)->item(0);
                $value = $selected instanceof \DOMElement ? $selected->getAttribute('value') : '';
            } elseif ($input->getAttribute('type') === 'radio') {
                if (!$input->hasAttribute('checked')) {
                    continue;
                }
                $value = $input->getAttribute('value');
            } else {
                $value = $input->getAttribute('value');
            }
            $fields[substr($name, strlen('alert_rule['), -1)] = $value;
        }
        unset($fields['_token']);
        self::assertSame('threshold', $fields['kind']);
        self::assertSame('both', $fields['baseline_direction']);
        self::assertSame('4', $fields['baseline_min_samples']);

        $response = $this->request('POST', '/alerting/rules/new', $fields + ['_token' => 'csrf-token']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter the data source ID as a whole number', (string) $response->getContent());
        // A POST with no form at all is not a submission either.
        self::assertSame(422, $this->kernel->handle(Request::create('/alerting/rules/new', 'POST', [], server: ['HTTP_ORIGIN' => 'http://localhost']))->getStatusCode());
    }

    public function testInvalidFieldsAreRefusedBeforeTheStore(): void
    {
        $this->expectNoWrites();
        $valid = ['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '90', 'clear_threshold' => '80', 'baseline_direction' => 'both', 'baseline_min_samples' => '4', '_token' => 'csrf-token'];
        foreach ([
            ['local_data_id' => '1e3'], ['local_data_id' => '4294967296'], ['local_data_id' => ['1']], ['rrd_name' => 'traffic-in'],
            ['trigger_threshold' => 'INF'], ['trigger_threshold' => '1e999'], ['clear_threshold' => '95'], ['comparator' => 'eq'],
            ['kind' => 'other'], ['for_cycles' => '0'],
        ] as $change) {
            $response = $this->request('POST', '/alerting/rules/new', $change + $valid);
            self::assertSame(422, $response->getStatusCode(), json_encode($change, JSON_THROW_ON_ERROR));
        }
    }

    public function testAValidRuleIsCreatedForTheActor(): void
    {
        $this->ruleStore->expects(self::once())->method('create')->with(42, self::callback(static fn(RuleDefinition $rule): bool => $rule->series->localDataId === 101 && $rule->condition instanceof ThresholdRule && $rule->condition->trigger === 90.5))->willReturn(12);
        $response = $this->request('POST', '/alerting/rules/new', ['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '90.5', 'clear_threshold' => '80', 'baseline_direction' => 'both', 'baseline_min_samples' => '4', '_token' => 'csrf-token']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/alerting/rules/12/edit?saved=1', $response->headers->get('Location'));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function mutations(): iterable
    {
        $rule = ['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '90', 'clear_threshold' => '80', 'revision' => 'rev-7'];
        yield 'create' => ['/alerting/rules/new', 'alert_rule', $rule];
        yield 'edit' => ['/alerting/rules/7/edit', 'alert_rule', $rule];
        yield 'delete' => ['/alerting/rules/7/delete', 'alert_rule_delete', ['revision' => 'rev-7']];
        yield 'acknowledge' => ['/alerting/incidents/3/acknowledge', 'alert_incident_acknowledge', []];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('mutations')]
    public function testAMissingOrWrongCsrfTokenIsRejected(string $uri, string $prefix, array $fields): void
    {
        $this->expectNoWrites();
        foreach ([null, 'wrong'] as $token) {
            $body = $token === null ? $fields : $fields + ['_token' => $token];
            $response = $this->kernel->handle(Request::create($uri, 'POST', [$prefix => $body === [] ? ['x' => ''] : $body], server: ['HTTP_ORIGIN' => 'http://localhost']));
            self::assertSame(422, $response->getStatusCode(), $uri . ' ' . var_export($token, true));
        }
        // A foreign origin is refused even with the token.
        $response = $this->kernel->handle(Request::create($uri, 'POST', [$prefix => $fields + ['_token' => 'csrf-token']], server: ['HTTP_ORIGIN' => 'https://attacker.example']));
        self::assertSame(422, $response->getStatusCode());
    }

    public function testAcknowledgingRedirectsAndAnEndedIncidentIsReported(): void
    {
        $this->incidentStore->expects(self::exactly(2))->method('acknowledge')->with(42, 3, self::NOW)
            ->willReturnOnConsecutiveCalls(AcknowledgeOutcome::Acknowledged, AcknowledgeOutcome::Ended);
        $this->kernel->getContainer()->get('test.service_container')->set(\Kadupul\Alerting\Application\Port\Clock::class, new \Kadupul\Tests\Alerting\Fakes\MockClockAdapter(new \Symfony\Component\Clock\MockClock('@' . self::NOW)));
        $response = $this->request('POST', '/alerting/incidents/3/acknowledge', ['_token' => 'csrf-token']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/alerting/incidents/3?acknowledged=1', $response->headers->get('Location'));
        $ended = $this->request('POST', '/alerting/incidents/3/acknowledge', ['_token' => 'csrf-token']);
        self::assertSame(409, $ended->getStatusCode());
        self::assertStringContainsString('This incident has ended and cannot be acknowledged.', (string) $ended->getContent());
    }

    public function testTheIncidentPageShowsAnExpiredClaimAsRetrying(): void
    {
        $xpath = $this->xpath($this->request('GET', '/alerting/incidents/3'));
        self::assertSame('Retrying', trim($xpath->query('//section[@aria-labelledby="incident-notifications"]//tbody/tr/td[2]')->item(0)?->textContent ?? ''));
        self::assertSame(1, $xpath->query('//form[contains(@action, "/alerting/incidents/3/acknowledge")]//button')->length);
    }

    public function testThePagesExplainWhyNothingIsEvaluatedWhenAlertingIsOff(): void
    {
        $this->enabled = false;
        foreach (['/alerting/rules', '/alerting/incidents', '/alerting/rules/new'] as $uri) {
            $response = $this->request('GET', $uri);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Alerting is turned off', (string) $response->getContent(), $uri);
        }
    }

    public function testTheRuleListIsRenderedInTheUsersSavedFrenchLocale(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('i18n_language_support', '1'), ('i18n_auto_detection', '0')");
        $pdo->exec('CREATE TABLE plugin_config(id INTEGER, directory TEXT)');
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $locale = $this->createStub(LocalePreference::class);
        $locale->method('preferredLocale')->willReturn('fr-FR');
        $container->set(LocalePreference::class, $locale);

        $response = $this->kernel->handle(Request::create('/alerting/rules', 'GET', [], ['Cacti' => 'session'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']));
        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringContainsString('<html lang="fr">', $content);
        self::assertStringContainsString('<h1>Règles d’alerte</h1>', $content);
        self::assertStringContainsString('Débit supérieur à 90, rétabli à 80 ou moins', $content);
        self::assertStringContainsString('Source de données 101 (traffic_in) sur Core &lt;router&gt;', $content);

        // The shared confirmation template's buttons come from this domain too.
        $delete = (string) $this->kernel->handle(Request::create('/alerting/rules/7/delete', 'GET', [], ['Cacti' => 'session']))->getContent();
        self::assertStringContainsString('>Continuer</button>', $delete);
        self::assertStringContainsString('>Annuler</a>', $delete);
    }

    private function expectNoWrites(): void
    {
        foreach (['create', 'update', 'delete'] as $method) {
            $this->ruleStore->expects(self::never())->method($method);
        }
        $this->incidentStore->expects(self::never())->method('acknowledge');
    }

    /** @param array<string, mixed> $fields */
    private function request(string $method, string $uri, array $fields = []): Response
    {
        if ($method === 'GET') {
            return $this->kernel->handle(Request::create($uri));
        }
        $prefix = match (true) {
            str_ends_with($uri, '/delete') => 'alert_rule_delete',
            str_ends_with($uri, '/acknowledge') => 'alert_incident_acknowledge',
            default => 'alert_rule',
        };

        return $this->kernel->handle(Request::create($uri, 'POST', $fields === [] ? [] : [$prefix => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML((string) $response->getContent()));

        return new \DOMXPath($document);
    }
}

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
use Kadupul\Alerting\Application\ReadModel\SeriesView;
use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
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
    /** A valid threshold rule on the visible series, as the form posts it. */
    private const array RULE = ['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'comparator' => 'gt', 'trigger_threshold' => '90', 'clear_threshold' => '80', 'baseline_direction' => 'both', 'baseline_min_samples' => '4'];

    private Kernel $kernel;
    private ?Actor $actor;
    private ?\Throwable $denial = null;
    /** Thrown by the next catalog read, as a database failure would be. */
    private ?\Throwable $readFailure = null;
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
            $this->failRead();
            return new RulePage([$rule], 1, $page, 25);
        });
        $rules->method('find')->willReturnCallback(function (int $actor, int $id) use ($rule): ?RuleView {
            $this->reads[] = 'rules.find';
            $this->failRead();
            return match ($id) {
                7 => $rule,
                9 => new RuleView(9, 101, 'traffic_in', true, null, new BaselineRule(3.0, 2.5, 0.25, 2, BaselineDirection::Above, 6), null, 'rev-9', null),
                // A stored row the domain refuses: it can still be opened, corrected or deleted.
                10 => new RuleView(10, 101, 'traffic_in', true, null, null, 'alert_rule 10: comparator is not one the rule form writes.', 'rev-10', null),
                default => null,
            };
        });
        $container->set(RuleCatalog::class, $rules);
        $incident = new IncidentView(3, new Incident(3, 7, IncidentStatus::Open, self::NOW - 600), new SeriesView($rule->localDataId, $rule->rrdName, $rule->seriesExists, $rule->device), 7, $rule, null);
        $incidents = $this->createStub(IncidentCatalog::class);
        $incidents->method('page')->willReturnCallback(function (int $actor, bool $active, int $page) use ($incident): IncidentPage {
            $this->reads[] = 'incidents.page';
            $this->failRead();
            return new IncidentPage([$incident], 1, $page, 25, $active);
        });
        $incidents->method('find')->willReturnCallback(function (int $actor, int $id, int $now) use ($incident): ?IncidentDetail {
            $this->reads[] = 'incidents.find';
            $this->failRead();
            if ($id === 5) {
                // Opened on data source 101 of a rule since moved where the actor cannot see.
                return new IncidentDetail(new IncidentView(5, new Incident(5, 8, IncidentStatus::Orphaned, self::NOW - 900, self::NOW - 60), new SeriesView(101, 'traffic_in', false, null), 8, null, null), []);
            }
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

    public function testTheIncidentPageNamesItsRecordedSeriesAndHidesAnInvisibleRule(): void
    {
        $response = $this->request('GET', '/alerting/incidents/5');
        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response);
        $details = trim(preg_replace('/\s+/', ' ', $xpath->query('//main/dl')->item(0)?->textContent ?? '') ?? '');
        self::assertStringContainsString('Rule 8', $details);
        self::assertStringContainsString('Data source 101 (traffic_in) Series gone', $details);
        self::assertSame(0, $xpath->query('//a[contains(@href, "/alerting/rules/8")]')->length, 'no link to a rule the viewer cannot see');
        self::assertSame(0, $xpath->query('//form[contains(@action, "/acknowledge")]')->length);
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

    public function testTheRuleListStatesThatARateAtTheClearValueDoesNotClear(): void
    {
        // ThresholdRule clears only strictly past the clear value, so the label
        // must not say "at or below".
        $content = (string) $this->request('GET', '/alerting/rules')->getContent();
        self::assertStringContainsString('Rate greater than 90, clears below 80', $content);
        self::assertStringNotContainsString('at or below 80', $content);
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
        self::assertStringContainsString('Débit supérieur à 90, rétabli sous 80', $content);
        self::assertStringContainsString('Source de données 101 (traffic_in) sur Core &lt;router&gt;', $content);

        // The shared confirmation template's buttons come from this domain too.
        $delete = (string) $this->kernel->handle(Request::create('/alerting/rules/7/delete', 'GET', [], ['Cacti' => 'session']))->getContent();
        self::assertStringContainsString('>Continuer</button>', $delete);
        self::assertStringContainsString('>Annuler</a>', $delete);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedQueries(): iterable
    {
        yield 'rule list, unknown parameter' => ['/alerting/rules?sort=name'];
        yield 'rule list, flag other than 1' => ['/alerting/rules?saved=2'];
        yield 'rule list, page zero' => ['/alerting/rules?page=0'];
        yield 'rule list, page as a list' => ['/alerting/rules?page[]=1'];
        yield 'incident list, unknown filter' => ['/alerting/incidents?status=closed'];
        yield 'incident list, filter as a list' => ['/alerting/incidents?status[]=all'];
        yield 'incident list, unknown parameter' => ['/alerting/incidents?saved=1'];
        yield 'incident detail, unknown parameter' => ['/alerting/incidents/3?page=1'];
        yield 'incident detail, flag other than 1' => ['/alerting/incidents/3?acknowledged=yes'];
    }

    #[DataProvider('malformedQueries')]
    public function testAMalformedQueryIsRefusedBeforeAnyRead(string $uri): void
    {
        $this->expectNoWrites();
        $response = $this->request('GET', $uri);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertMatchesRegularExpression('/\AInvalid alert (rule list|incident list|incident) request\.\z/', (string) $response->getContent());
        self::assertSame([], $this->reads);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failedReads(): iterable
    {
        yield 'rule list' => ['GET', '/alerting/rules', 'Unable to load alert rules. Reload before retrying.'];
        yield 'rule edit' => ['GET', '/alerting/rules/7/edit', 'Unable to load the alert rule. Reload before retrying.'];
        yield 'rule edit submit' => ['POST', '/alerting/rules/7/edit', 'Unable to load the alert rule. Reload before retrying.'];
        yield 'rule delete' => ['GET', '/alerting/rules/7/delete', 'Unable to load the alert rule. Reload before retrying.'];
        yield 'incident list' => ['GET', '/alerting/incidents', 'Unable to load alert incidents. Reload before retrying.'];
        yield 'incident detail' => ['GET', '/alerting/incidents/3', 'Unable to load the alert incident. Reload before retrying.'];
        yield 'incident acknowledge' => ['POST', '/alerting/incidents/3/acknowledge', 'Unable to load the alert incident. Reload before retrying.'];
    }

    #[DataProvider('failedReads')]
    public function testAFailedReadAnswers502WithoutTheErrorOrAWrite(string $method, string $uri, string $message): void
    {
        $this->expectNoWrites();
        $this->readFailure = new \RuntimeException('SQLSTATE[HY000] mysql:host=db.example;password=hunter2');

        $response = $this->request($method, $uri, ['_token' => 'csrf-token']);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame($message, (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testAFailedAuthorizationReadAnswers502BeforeTheRuleForm(): void
    {
        $this->expectNoWrites();
        $this->denial = new \RuntimeException('SQLSTATE[HY000] mysql:host=db.example;password=hunter2');

        foreach (['GET', 'POST'] as $method) {
            $response = $this->request($method, '/alerting/rules/new', ['_token' => 'csrf-token']);
            self::assertSame(502, $response->getStatusCode(), $method);
            self::assertSame('Unable to authorize alert rule access.', (string) $response->getContent());
        }
        self::assertSame([], $this->reads);
    }

    public function testAPostWithoutAFormIsNotASubmission(): void
    {
        $this->expectNoWrites();
        foreach (['/alerting/rules/7/edit', '/alerting/rules/7/delete', '/alerting/incidents/3/acknowledge'] as $uri) {
            $response = $this->request('POST', $uri);
            self::assertSame(422, $response->getStatusCode(), $uri);
            self::assertStringContainsString('The form was not submitted. Reload before retrying.', (string) $response->getContent(), $uri);
        }
    }

    public function testAValidEditIsSavedWithTheRevisionTheFormShowed(): void
    {
        $this->ruleStore->expects(self::once())->method('update')->with(42, 7, 'rev-7', self::callback(static fn(RuleDefinition $rule): bool => $rule->condition instanceof ThresholdRule && $rule->condition->trigger === 95.0));

        $response = $this->request('POST', '/alerting/rules/7/edit', ['trigger_threshold' => '95', 'revision' => 'rev-7', '_token' => 'csrf-token'] + self::RULE);

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/alerting/rules/7/edit?saved=1', $response->headers->get('Location'));
    }

    public function testAValidDeleteRemovesTheRevisionTheFormShowed(): void
    {
        $this->ruleStore->expects(self::once())->method('delete')->with(42, 7, 'rev-7');

        $response = $this->request('POST', '/alerting/rules/7/delete', ['revision' => 'rev-7', '_token' => 'csrf-token']);

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/alerting/rules?deleted=1', $response->headers->get('Location'));
    }

    /** @return iterable<string, array{\Throwable, int, ?string}> */
    public static function storeOutcomes(): iterable
    {
        yield 'revoked while saving' => [new AlertingAccessDenied(false), 403, null];
        yield 'signed out while saving' => [new AlertingAccessDenied(true), 401, null];
        yield 'changed since the form was shown' => [new RuleSubmissionRefused(\Kadupul\Alerting\Application\Port\RuleStore::STALE), 409, \Kadupul\Alerting\Application\Port\RuleStore::STALE];
        yield 'deleted since the form was shown' => [new RuleSubmissionRefused(\Kadupul\Alerting\Application\Port\RuleStore::NOT_FOUND), 404, \Kadupul\Alerting\Application\Port\RuleStore::NOT_FOUND];
        yield 'series no longer available' => [new RuleSubmissionRefused(\Kadupul\Alerting\Application\Port\RuleStore::SERIES_UNAVAILABLE), 422, \Kadupul\Alerting\Application\Port\RuleStore::SERIES_UNAVAILABLE];
        yield 'database failure' => [new \RuntimeException('SQLSTATE[40001] password=hunter2'), 502, null];
    }

    #[DataProvider('storeOutcomes')]
    public function testARefusedOrFailedRuleWriteIsAnsweredWithItsOutcome(\Throwable $error, int $code, ?string $message): void
    {
        $this->ruleStore->method('update')->willThrowException($error);
        $this->ruleStore->method('delete')->willThrowException($error);

        foreach ([
            '/alerting/rules/7/edit' => [self::RULE + ['revision' => 'rev-7', '_token' => 'csrf-token'], 'Save outcome is uncertain. Reload before retrying.'],
            '/alerting/rules/7/delete' => [['revision' => 'rev-7', '_token' => 'csrf-token'], 'Delete outcome is uncertain. Check the alert rule list before retrying.'],
        ] as $uri => [$fields, $uncertain]) {
            $response = $this->request('POST', $uri, $fields);
            $content = (string) $response->getContent();
            self::assertSame($code, $response->getStatusCode(), $uri);
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertStringNotContainsString('hunter2', $content);
            if ($error instanceof AlertingAccessDenied) {
                self::assertSame('Access denied.', $content, $uri);
            } else {
                self::assertStringContainsString(htmlspecialchars($message ?? $uncertain, ENT_QUOTES), $content, $uri);
            }
        }
    }

    /** @return iterable<string, array{\Throwable|AcknowledgeOutcome, int, string}> */
    public static function acknowledgementOutcomes(): iterable
    {
        yield 'gone since the page was shown' => [AcknowledgeOutcome::NotFound, 404, 'Alert incident not found.'];
        yield 'already acknowledged by another' => [AcknowledgeOutcome::AlreadyAcknowledged, 303, ''];
        yield 'revoked while saving' => [new AlertingAccessDenied(false), 403, 'Access denied.'];
        yield 'database failure' => [new \RuntimeException('SQLSTATE[40001] password=hunter2'), 502, 'Acknowledgement outcome is uncertain. Reload the incident before retrying.'];
    }

    #[DataProvider('acknowledgementOutcomes')]
    public function testEachAcknowledgementOutcomeIsAnsweredWithItsStatus(\Throwable|AcknowledgeOutcome $outcome, int $code, string $message): void
    {
        $call = $this->incidentStore->expects(self::once())->method('acknowledge')->with(42, 3, self::anything());
        $outcome instanceof AcknowledgeOutcome ? $call->willReturn($outcome) : $call->willThrowException($outcome);

        $response = $this->request('POST', '/alerting/incidents/3/acknowledge', ['_token' => 'csrf-token']);
        $content = (string) $response->getContent();

        self::assertSame($code, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', $content);
        if ($code === 303) {
            self::assertSame('/alerting/incidents/3?acknowledged=1', $response->headers->get('Location'));
        } else {
            self::assertStringContainsString($message, $content);
        }
    }

    public function testTheEditFormShowsABaselineRuleAndAnUnusableRowAsStored(): void
    {
        $this->expectNoWrites();
        $baseline = $this->xpath($this->request('GET', '/alerting/rules/9/edit'));
        $value = static fn(\DOMXPath $xpath, string $field): string => ($xpath->query('//input[@name="alert_rule[' . $field . ']"]')->item(0) ?? new \DOMElement('x'))->getAttribute('value');
        self::assertSame(['3', '2.5', '0.25', '6', '2', 'rev-9'], array_map(static fn(string $field): string => $value($baseline, $field), ['baseline_deviations', 'baseline_clear_deviations', 'baseline_floor', 'baseline_min_samples', 'for_cycles', 'revision']));
        self::assertSame('baseline', $baseline->query('//input[@name="alert_rule[kind]"][@checked]')->item(0)?->getAttribute('value'));
        self::assertSame('above', $baseline->query('//select[@name="alert_rule[baseline_direction]"]/option[@selected]')->item(0)?->getAttribute('value'));

        // An unusable row keeps its series and revision; the condition fields start from the defaults.
        $unusable = $this->xpath($this->request('GET', '/alerting/rules/10/edit'));
        self::assertSame(['101', 'traffic_in', '', '', 'rev-10'], array_map(static fn(string $field): string => $value($unusable, $field), ['local_data_id', 'rrd_name', 'trigger_threshold', 'clear_threshold', 'revision']));
    }

    private function failRead(): void
    {
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
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

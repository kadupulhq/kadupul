<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfilePreferences;
use Kadupul\Graphing\Application\Port\DataSourceProfileStore;
use Kadupul\Graphing\Application\Query\DataSourceProfileAccessDenied;
use Kadupul\Graphing\Domain\DataSourceProfile;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Domain\DataSourceProfilePage;
use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DataSourceProfileRoutesTest extends TestCase
{
    private Kernel $kernel;
    private DataSourceProfileStore&MockObject $store;
    private ?Actor $actor;
    private bool $denied = false;
    /** @var array<int, DataSourceProfile> */
    private array $profiles = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(DataSourceProfileAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new DataSourceProfileAccessDenied(false) : $this->actor);
        $container->set(DataSourceProfileAccess::class, $access);
        $this->profiles = [
            1 => new DataSourceProfile(1, '5 Minute <b>', 300, 600, 0.5, true, 2, 1, 'revision-1', [1, 2, 3, 4], [
                new DataSourceProfileRra(11, 1, 'Daily', 1, 600, 86400),
                new DataSourceProfileRra(12, 1, 'Weekly', 6, 700, 604800),
            ]),
            2 => new DataSourceProfile(2, 'Unused', 60, 120, 0.25, false, 0, 0, 'revision-2', [1]),
            3 => new DataSourceProfile(3, 'Templates only', 30, 1200, 0.5, false, 0, 4, 'revision-3', [1, 3], [
                new DataSourceProfileRra(31, 3, 'Raw', 1, 1500, 3600),
            ]),
        ];
        $this->store = $this->createMock(DataSourceProfileStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('defaultHasData')->willReturn(false);
        $this->store->method('defaults')->willReturn(['step' => 300, 'heartbeat' => 600]);
        $this->store->method('list')->willReturnCallback(fn(DataSourceProfileFilters $filters): DataSourceProfilePage => new DataSourceProfilePage(array_values($this->profiles), count($this->profiles), $filters));
        $this->store->method('find')->willReturnCallback(fn(int $id): ?DataSourceProfile => $this->profiles[$id] ?? null);
        $this->store->method('findMany')->willReturnCallback(fn(array $ids): array => array_intersect_key($this->profiles, array_flip($ids)));
        $container->set(DataSourceProfileStore::class, $this->store);
        $container->set(DataSourceProfilePreferences::class, $this->createMock(DataSourceProfilePreferences::class));
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/kadupul/']);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testListShowsUsageAndKeepsEveryRowSelectableForDuplication(): void
    {
        $response = $this->get('/graphing/data-source-profiles?sort_column=name');
        self::assertSame(200, $response->getStatusCode());
        $xpath = $this->xpath($response);
        self::assertSame(3, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        $rows = [];
        foreach ($xpath->query('//tbody/tr') as $row) {
            $rows[] = array_map(static fn(\DOMNode $cell): string => trim($cell->textContent), array_slice(iterator_to_array($xpath->query('td', $row)), 1));
        }
        self::assertSame([
            ['5 Minute <b>', 'Yes', 'No', 'Yes', 'Every 5 Minutes', '10 Minutes', '2', '1'],
            ['Unused', '', 'Yes', 'No', 'Every Minute', '2 Minutes', '0', '0'],
            ['Templates only', '', 'No', 'No', 'Every 30 Seconds', '20 Minutes', '0', '4'],
        ], $rows);
        self::assertSame('/kadupul/data_sources.php?reset=true&profile=1', $xpath->query('//tbody/tr[1]/td[8]/a')->item(0)->getAttribute('href'));
        self::assertSame('/kadupul/data_templates.php?reset=true&profile=3', $xpath->query('//tbody/tr[3]/td[9]/a')->item(0)->getAttribute('href'));
        self::assertStringNotContainsString('<b>', (string) $response->getContent());
    }

    public function testMalformedFiltersAreRefused(): void
    {
        foreach (['?rows=0', '?filter[]=x', '?sort_column=hash', '?has_data=yes', '?page=0', '?deleted=2'] as $query) {
            self::assertSame(400, $this->get('/graphing/data-source-profiles' . $query)->getStatusCode(), $query);
        }
    }

    public function testReadOnlyProfilesDisableTheirStructureAndHideArchiveChanges(): void
    {
        $xpath = $this->xpath($this->get('/graphing/data-source-profiles/1/edit'));
        self::assertStringContainsString('(Read Only)', $xpath->query('//h1')->item(0)->textContent);
        foreach (['data_source_profile[step]', 'data_source_profile[x_files_factor]', 'data_source_profile[consolidation_function_id][]'] as $name) {
            self::assertGreaterThan(0, $xpath->query('//*[@name="' . $name . '" and @disabled]')->length, $name);
        }
        self::assertSame(0, $xpath->query('//*[@name="data_source_profile[heartbeat]" and @disabled]')->length);
        self::assertSame(0, $xpath->query('//a[contains(@href, "/rras/new") or contains(@href, "/delete")]')->length);
        self::assertSame(['2 Days, 2 Hours', '2 Weeks, 14 Hours'], array_map(static fn(\DOMNode $cell): string => trim($cell->textContent), iterator_to_array($xpath->query('//section//tbody/tr/td[2]'))));
        self::assertStringContainsString('42 KBytes per Data Sources and 284 Bytes for the Header', (string) $xpath->document->saveHTML());

        $open = $this->xpath($this->get('/graphing/data-source-profiles/3/edit'));
        self::assertSame(0, $open->query('//*[@disabled and starts-with(@name, "data_source_profile[")]')->length);
        self::assertSame(1, $open->query('//a[contains(@href, "/graphing/data-source-profiles/3/rras/new")]')->length);
        self::assertSame(1, $open->query('//a[contains(@href, "/graphing/data-source-profiles/3/rras/31/delete")]')->length);
    }

    public function testSavingPassesOnlySubmittedStructureAndReportsHeartbeatTuning(): void
    {
        $calls = [];
        $this->store->method('save')->willReturnCallback(static function (...$arguments) use (&$calls): array {
            $calls[] = $arguments;
            return ['id' => 1, 'retune' => true];
        });
        // A browser omits the disabled structural fields of a read-only profile.
        $response = $this->postForm('/graphing/data-source-profiles/1/edit', 'data_source_profile', ['name' => 'Renamed', 'heartbeat' => '900', 'revision' => 'revision-1']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/graphing/data-source-profiles/1/edit?saved=1&retune=1', $response->headers->get('Location'));
        self::assertSame([[42, 1, 'Renamed', null, 900, null, [], false, 'revision-1']], $calls);
        self::assertStringContainsString('Use RRDtool&#039;s &#039;tune&#039; function', (string) $this->get('/graphing/data-source-profiles/1/edit?saved=1&retune=1')->getContent());

        $this->postForm('/graphing/data-source-profiles/new', 'data_source_profile', ['name' => 'Created', 'step' => '60', 'heartbeat' => '120', 'x_files_factor' => '0.5', 'consolidation_function_id' => ['4', '1'], 'default' => '1'], 'data_source_profile_create');
        self::assertSame([42, null, 'Created', 60, 120, '0.5', [1, 4], true, null], $calls[1]);
    }

    public function testRefusalsKeepTheFormWithTheirStatus(): void
    {
        $this->store->method('save')->willReturnCallback(static fn(int $actor, ?int $id, string $name): array => throw new \InvalidArgumentException(match ($name) {
            'Forged' => DataSourceProfileStore::READ_ONLY,
            'Stale' => 'The Data Source Profile changed since you opened this form. Reload before saving.',
            default => 'Enter an X-Files Factor from 0 up to but not including 1.',
        }));
        foreach (['Forged' => [409, 'become read only'], 'Stale' => [409, 'changed since'], 'Bad' => [422, 'X-Files Factor from 0']] as $name => [$status, $message]) {
            $response = $this->postForm('/graphing/data-source-profiles/2/edit', 'data_source_profile', ['name' => $name, 'step' => '60', 'heartbeat' => '120', 'x_files_factor' => '2', 'revision' => 'revision-2']);
            self::assertSame($status, $response->getStatusCode(), $name);
            self::assertStringContainsString($message, (string) $response->getContent());
        }
    }

    public function testMalformedProfileFormsNeverReachTheStore(): void
    {
        $this->store->expects(self::never())->method('save');
        foreach ([
            ['name' => ['x'], 'revision' => 'revision-2'],
            ['name' => 'P', 'step' => '45', 'revision' => 'revision-2'],
            ['name' => 'P', 'step' => ['60'], 'revision' => 'revision-2'],
            ['name' => 'P', 'consolidation_function_id' => ['9'], 'revision' => 'revision-2'],
            ['name' => 'P', 'consolidation_function_id' => ['1', '2', '3', '4', '1'], 'revision' => 'revision-2'],
            ['name' => 'P', 'heartbeat' => '1e3', 'revision' => 'revision-2'],
            ['name' => 'P', 'surprise' => '1', 'revision' => 'revision-2'],
        ] as $fields) {
            self::assertSame(422, $this->postForm('/graphing/data-source-profiles/2/edit', 'data_source_profile', $fields)->getStatusCode(), json_encode($fields));
        }
        $response = $this->kernel->handle(Request::create('/graphing/data-source-profiles/2/edit', 'POST', ['data_source_profile' => ['name' => 'P', 'revision' => 'revision-2']]));
        self::assertSame(422, $response->getStatusCode(), 'a form without CSRF evidence is refused');
    }

    public function testArchiveFormsOfferTheLegacyLevelsAndValidateRows(): void
    {
        $levels = static fn(\DOMXPath $xpath): array => array_map(static fn(\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="data_source_profile_rra[steps]"]/option')));
        self::assertSame(['1'], $levels($this->xpath($this->get('/graphing/data-source-profiles/2/rras/new'))));
        self::assertSame(['60', '300', '600', '900', '1800', '3600', '7200', '10800', '14400', '21600', '43200', '86400', '604800'], $levels($this->xpath($this->get('/graphing/data-source-profiles/3/rras/new'))));
        self::assertSame(['1'], $levels($this->xpath($this->get('/graphing/data-source-profiles/1/rras/11/edit'))));
        $weekly = $this->xpath($this->get('/graphing/data-source-profiles/1/rras/12/edit'));
        self::assertSame(['600', '900', '1800', '3600', '7200', '10800', '14400', '21600', '43200', '86400', '604800'], $levels($weekly));
        self::assertSame('1800', $weekly->query('//select[@name="data_source_profile_rra[steps]"]/option[@selected]')->item(0)?->getAttribute('value'));
        self::assertStringContainsString('(Some Elements Read Only)', $weekly->query('//h1')->item(0)->textContent);
        self::assertSame(2, $weekly->query('//*[(@name="data_source_profile_rra[steps]" or @name="data_source_profile_rra[rows]") and @disabled]')->length);
        self::assertSame(404, $this->get('/graphing/data-source-profiles/2/rras/12/edit')->getStatusCode());

        $calls = [];
        $this->store->method('saveArchive')->willReturnCallback(static function (...$arguments) use (&$calls): int {
            $calls[] = $arguments;
            return 40;
        });
        foreach (['0', '-1', '1.5', '4294967296', 'many', ['1']] as $rows) {
            $response = $this->postForm('/graphing/data-source-profiles/3/rras/new', 'data_source_profile_rra', ['name' => 'Hourly', 'steps' => '3600', 'rows' => $rows, 'timespan' => '86400']);
            self::assertSame(422, $response->getStatusCode(), json_encode($rows));
        }
        self::assertSame([], $calls);
        $response = $this->postForm('/graphing/data-source-profiles/3/rras/new', 'data_source_profile_rra', ['name' => 'Hourly', 'steps' => '3600', 'rows' => '4294967295', 'timespan' => '86400']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[42, 3, null, 'Hourly', 3600, 4294967295, 86400, null]], $calls);
        // The weekly archive of the read-only profile: the browser omits steps and rows.
        $this->postForm('/graphing/data-source-profiles/1/rras/12/edit', 'data_source_profile_rra', ['name' => 'Renamed', 'timespan' => '1209600', 'revision' => $this->profiles[1]->archives[1]->revision]);
        self::assertSame([42, 1, 12, 'Renamed', null, null, 1209600, $this->profiles[1]->archives[1]->revision], $calls[1]);
    }

    public function testArchiveDeletionCarriesItsRevision(): void
    {
        $calls = [];
        $this->store->method('deleteArchive')->willReturnCallback(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });
        $archive = $this->profiles[3]->archives[0];
        self::assertStringContainsString('RRA Name: Raw', (string) $this->get('/graphing/data-source-profiles/3/rras/31/delete')->getContent());
        self::assertSame(303, $this->postForm('/graphing/data-source-profiles/3/rras/31/delete', 'data_source_profile_rra_delete', ['revision' => $archive->revision])->getStatusCode());
        self::assertSame([[42, 3, 31, $archive->revision]], $calls);
    }

    public function testDeleteNamesWhyAProfileIsKeptAndRefusesIt(): void
    {
        $this->store->expects(self::never())->method('delete');
        $body = (string) $this->get('/graphing/data-source-profiles/actions/delete?ids[]=1&ids[]=3')->getContent();
        self::assertStringContainsString('5 Minute &lt;b&gt; (default)', $body);
        self::assertStringContainsString('used by 0 data sources and 4 templates', $body);
        self::assertStringContainsString('can not be deleted', $body);
        self::assertSame(422, $this->postAction('/graphing/data-source-profiles/actions/delete?ids[]=3', '[3]', [3 => 'revision-3'])->getStatusCode());
    }

    public function testDuplicateCarriesRevisionsTheTitleFormatAndTheFilters(): void
    {
        $calls = [];
        $this->store->method('duplicate')->willReturnCallback(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });
        self::assertStringContainsString('&lt;profile_title&gt; (1)', (string) $this->get('/graphing/data-source-profiles/actions/duplicate?ids[]=1')->getContent());
        $response = $this->postAction('/graphing/data-source-profiles/actions/duplicate?ids[]=1&filter=five', '[1]', [1 => 'revision-1'], ['title_format' => 'Copy of <profile_title>']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/graphing/data-source-profiles?duplicated=1&filter=five&rows=-1&page=1&sort_column=step&sort_direction=ASC&has_data=false', $response->headers->get('Location'));
        self::assertSame([[42, [1], [1 => 'revision-1'], 'Copy of <profile_title>']], $calls);
        foreach (['ids=1', 'ids[]=0', 'ids[]=1.5', 'ids[a]=1', implode('&', array_map(static fn(int $id): string => 'ids[]=' . $id, range(1, 101)))] as $query) {
            self::assertSame(400, $this->get('/graphing/data-source-profiles/actions/duplicate?' . $query)->getStatusCode(), $query);
        }
        self::assertSame(404, $this->get('/graphing/data-source-profiles/actions/delete?ids[]=9')->getStatusCode());
    }

    public function testLegacyUrlsRedirectSafeNavigationAndExpirePostedForms(): void
    {
        $this->store->expects(self::never())->method('save');
        $locations = [
            '/graphing/data-source-profiles/legacy' => '/graphing/data-source-profiles',
            '/graphing/data-source-profiles/legacy?clear=1&header=false' => '/graphing/data-source-profiles?clear=1',
            '/graphing/data-source-profiles/legacy?action=edit' => '/graphing/data-source-profiles/new',
            '/graphing/data-source-profiles/legacy?action=edit&id=3&header=false' => '/graphing/data-source-profiles/3/edit',
            '/graphing/data-source-profiles/legacy?action=item_edit&profile_id=3' => '/graphing/data-source-profiles/3/rras/new',
            '/graphing/data-source-profiles/legacy?action=item_edit&id=31&profile_id=3' => '/graphing/data-source-profiles/3/rras/31/edit',
            '/graphing/data-source-profiles/legacy?action=item_remove_confirm&id=31&profile_id=3' => '/graphing/data-source-profiles/3/rras/31/delete',
        ];
        foreach ($locations as $uri => $location) {
            $response = $this->get($uri);
            self::assertSame(302, $response->getStatusCode(), $uri);
            self::assertSame($location, $response->headers->get('Location'), $uri);
        }
        self::assertStringContainsString('has_data=true', (string) $this->get('/graphing/data-source-profiles/legacy?has_data=true&rows=-1')->headers->get('Location'));
        self::assertSame(409, $this->kernel->handle(Request::create('/graphing/data-source-profiles/legacy', 'POST', ['action' => 'actions', 'drp_action' => '1']))->getStatusCode());
        foreach (['save', 'actions', 'item_remove'] as $action) {
            self::assertSame(405, $this->get('/graphing/data-source-profiles/legacy?action=' . $action)->getStatusCode(), $action);
        }
        foreach (['?action[]=x', '?action=edit&id=1e2', '?action=item_edit', '?action=item_remove_confirm&profile_id=3', '?rows=0', '?action=purge'] as $query) {
            self::assertSame(400, $this->get('/graphing/data-source-profiles/legacy' . $query)->getStatusCode(), $query);
        }
    }

    /** @return array<string, array{string, int, string}> */
    public static function lookups(): array
    {
        return [
            'span at the profile interval' => ['action=ajax_span&profile_id=1&span=1&rows=600', 200, '2 Days, 2 Hours'],
            'span at a level' => ['action=ajax_span&profile_id=1&span=3600&rows=775', 200, '1 Month, 2 Days, 7 Hours'],
            'span without rows' => ['action=ajax_span&profile_id=1&span=1&rows=', 200, 'N/A'],
            'span with zero rows' => ['action=ajax_span&profile_id=1&span=1&rows=0', 200, 'N/A'],
            'span of a missing profile' => ['action=ajax_span&profile_id=9&span=1&rows=600', 200, ''],
            'profile size from stored functions' => ['action=ajax_size&type=profile&id=1&cfs=0', 200, '42 KBytes per Data Sources and 284 Bytes for the Header'],
            'profile size from checked functions' => ['action=ajax_size&type=profile&id=1&cfs=1', 200, '11 KBytes per Data Sources and 284 Bytes for the Header'],
            'archive size' => ['action=ajax_size&type=rra&id=3&rows=1500', 200, '24 KBytes per Data Source'],
            'archive size without rows' => ['action=ajax_size&type=rra&id=3&rows=', 200, 'Enter a valid number of Rows to obtain the RRA size.'],
            'non-integer rows' => ['action=ajax_span&profile_id=1&span=1&rows=abc', 400, 'Invalid profile request.'],
            'array rows' => ['action=ajax_size&type=rra&id=3&rows[]=1', 400, 'Invalid profile request.'],
            'oversized rows' => ['action=ajax_span&profile_id=1&span=1&rows=4294967296', 400, 'Invalid profile request.'],
            'negative level' => ['action=ajax_span&profile_id=1&span=-60&rows=10', 400, 'Invalid profile request.'],
            'too many functions' => ['action=ajax_size&type=profile&id=1&cfs=5', 400, 'Invalid profile request.'],
            'fractional profile' => ['action=ajax_size&type=profile&id=1.5', 400, 'Invalid profile request.'],
        ];
    }

    /** @param array<string, string> $parameters */
    #[\PHPUnit\Framework\Attributes\DataProvider('lookups')]
    public function testLegacySizeAndSpanLookupsKeepTheirText(string $query, int $status, string $body): void
    {
        $response = $this->get('/graphing/data-source-profiles/legacy?' . $query);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, $response->getContent());
        if ($status === 200) {
            self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        }
    }

    public function testDeniedAccessStopsBeforeReadingTheStore(): void
    {
        $this->denied = true;
        foreach (['find', 'findMany', 'list', 'defaults'] as $method) {
            $this->store->expects(self::never())->method($method);
        }
        foreach (['/graphing/data-source-profiles', '/graphing/data-source-profiles/new', '/graphing/data-source-profiles/1/edit', '/graphing/data-source-profiles/1/rras/new', '/graphing/data-source-profiles/1/rras/11/edit', '/graphing/data-source-profiles/1/rras/11/delete', '/graphing/data-source-profiles/actions/delete?ids[]=2', '/graphing/data-source-profiles/legacy?action=ajax_size&type=profile&id=1'] as $uri) {
            self::assertSame(403, $this->get($uri)->getStatusCode(), $uri);
        }
        $this->denied = false;
        $this->actor = null;
        self::assertSame(401, $this->get('/graphing/data-source-profiles')->getStatusCode());
        self::assertSame(401, $this->get('/graphing/data-source-profiles/legacy?action=ajax_span&profile_id=1&span=1&rows=1')->getStatusCode());
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML((string) $response->getContent()));
        return new \DOMXPath($document);
    }

    /** @param array<string, mixed> $fields */
    private function postForm(string $uri, string $prefix, array $fields, ?string $token = null): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', [$prefix => $fields + ['_token' => $token ?? 'csrf-token']], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }

    /**
     * @param array<int, string> $revisions
     * @param array<string, string> $extra
     */
    private function postAction(string $uri, string $selection, array $revisions, array $extra = []): Response
    {
        return $this->postForm($uri, 'data_source_profile_action', ['selection' => $selection, 'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)] + $extra);
    }
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Port\GraphTemplateInputCatalog;
use Kadupul\Graphing\Application\Port\GraphTemplateInputEditor;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputItem;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphTemplateInputRoutesTest extends TestCase
{
    private Kernel $kernel;
    private GraphTemplateInputCatalog&MockObject $catalog;
    private GraphTemplateInputEditor&MockObject $editor;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $offline = false;
    private bool $emptyTemplate = false;
    private mixed $urlPath = '/cacti/';
    private ?\Throwable $accessFailure = null;
    /** @var ?\Closure(list<mixed>): \Throwable */
    private ?\Closure $editorFailure = null;
    private GraphTemplateInput $input;
    /** @var list<array{string, list<mixed>}> */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(GraphTemplateInputAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->accessFailure !== null ? throw $this->accessFailure : ($this->denied ? throw new GraphTemplateInputAccessDenied(false) : $this->actor));
        $container->set(GraphTemplateInputAccess::class, $access);
        $this->input = new GraphTemplateInput(5, 1, 'Legend <b>', 'Shown <i>', 'text_format', [11]);
        $this->catalog = $this->createMock(GraphTemplateInputCatalog::class);
        $this->catalog->method('templateName')->willReturnCallback(fn(int $id): ?string => $this->offline ? throw new \RuntimeException('SQLSTATE[HY000] [2002] secret host') : ($id === 1 ? 'Traffic <script>' : null));
        $this->catalog->method('find')->willReturnCallback(fn(int $template, int $id): ?GraphTemplateInput => $template === 1 && $id === 5 ? $this->input : null);
        $this->catalog->method('items')->willReturnCallback(fn(int $template, ?int $input): array => $this->emptyTemplate ? [] : [
            new GraphTemplateInputItem(11, 1, 4, 1, $input === 5),
            new GraphTemplateInputItem(12, 2, 9, 4, false),
            new GraphTemplateInputItem(13, 3, 99, 7, false),
        ]);
        $container->set(GraphTemplateInputCatalog::class, $this->catalog);
        $this->editor = $this->createMock(GraphTemplateInputEditor::class);
        foreach (['save', 'remove'] as $method) {
            $this->editor->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): int {
                if ($this->editorFailure !== null) {
                    throw ($this->editorFailure)($arguments);
                }
                $text = json_encode($arguments, JSON_THROW_ON_ERROR);
                if (str_contains($text, '"stale"')) {
                    throw new \InvalidArgumentException(GraphTemplateInputEditor::STALE);
                }
                if (str_contains($text, '"Broken"')) {
                    throw new \RuntimeException('database gone');
                }
                $this->writes[] = [$method, $arguments];
                return 5;
            });
        }
        $container->set(GraphTemplateInputEditor::class, $this->editor);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturnCallback(fn(): array => ['collector_id' => 1, 'url_path' => $this->urlPath]);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testTheEditorKeepsTheLegacyFieldsChoicesAndItemLabels(): void
    {
        $response = $this->get('/graphing/graph-templates/1/inputs/5/edit');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private'));
        $xpath = $this->xpath($response);
        self::assertStringContainsString('Graph Item Inputs [edit graph: Traffic <script>]', $xpath->query('//h1')->item(0)->textContent);
        self::assertSame('Legend <b>', $xpath->query('//input[@name="graph_template_input[name]"]')->item(0)->getAttribute('value'));
        // graph_template_input.name is varchar(255): the browser limit matches the server's.
        self::assertSame('255', $xpath->query('//input[@name="graph_template_input[name]"]')->item(0)->getAttribute('maxlength'));
        self::assertSame('Shown <i>', $xpath->query('//textarea[@name="graph_template_input[description]"]')->item(0)->textContent);
        // The option order and labels of the recorded legacy golden.
        self::assertSame([
            'graph_type_id' => 'Graph Item Type', 'task_item_id' => 'Data Source', 'color_id' => 'Color', 'alpha' => 'Opacity/Alpha Channel',
            'consolidation_function_id' => 'Consolidation Function', 'cdef_id' => 'CDEF Function', 'vdef_id' => 'VDEF Function', 'shift' => 'Shift Data',
            'value' => 'Value', 'gprint_id' => 'GPRINT Type', 'textalign' => 'Text Alignment (TEXTALIGN)', 'text_format' => 'Text Format',
            'hard_return' => 'Insert Hard Return', 'line_width' => 'Line Width', 'dashes' => 'Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])',
            'dash_offset' => 'Dash Offset (dash-offset=offset)', 'sequence' => 'Sequence',
        ], $this->options($xpath));
        self::assertSame('text_format', $xpath->query('//select[@name="graph_template_input[column_name]"]/option[@selected]')->item(0)->getAttribute('value'));
        $labels = [];
        foreach ($xpath->query('//input[@name="graph_template_input[items][]"]') as $box) {
            $label = $xpath->query('//label[@for="' . $box->getAttribute('id') . '"]')->item(0);
            $labels[] = [$box->getAttribute('value'), $box->hasAttribute('checked'), trim($label->textContent), $xpath->query('strong', $label)->length];
        }
        self::assertSame([['11', true, 'Item #1: LINE1 (AVERAGE)', 1], ['12', false, 'Item #2: GPRINT (LAST)', 0], ['13', false, 'Item #3: 99 (7)', 1]], $labels);
        self::assertSame('/cacti/graph_templates.php?action=template_edit&id=1', $xpath->query('//main//a[last()]')->item(0)->getAttribute('href'));
        self::assertSame(0, $xpath->query('//@*[starts-with(name(), "on")]')->length, 'no inline handlers');
        self::assertSame(0, $xpath->query('//script[not(@src)]')->length);
        self::assertStringNotContainsString('<script>', (string) $response->getContent());
        self::assertSame('', $this->kernel->handle(Request::create('/graphing/graph-templates/1/inputs/5/edit', 'HEAD'))->getContent());
    }

    public function testANewInputStartsEmptyAndAnEmptyTemplateSaysSo(): void
    {
        $xpath = $this->xpath($this->get('/graphing/graph-templates/1/inputs/new'));
        self::assertSame('', $xpath->query('//input[@name="graph_template_input[name]"]')->item(0)->getAttribute('value'));
        self::assertSame('graph_type_id', $xpath->query('//select[@name="graph_template_input[column_name]"]/option[@selected]')->item(0)?->getAttribute('value') ?? 'graph_type_id');
        self::assertSame(0, $xpath->query('//input[@checked]')->length);
        self::assertSame('Create', trim($xpath->query('//button[@type="submit"]')->item(0)->textContent));

        $this->emptyTemplate = true;
        self::assertStringContainsString('<em>No Items</em>', (string) $this->get('/graphing/graph-templates/1/inputs/new')->getContent());
    }

    public function testSavingPassesTheSubmittedValuesAndReturnsToTheTemplateBelowUrlPath(): void
    {
        $response = $this->postForm('/graphing/graph-templates/1/inputs/5/edit', 'graph_template_input', ['name' => 'Renamed', 'description' => '', 'column_name' => 'color_id', 'items' => ['12', '11'], 'revision' => $this->input->revision]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/cacti/graph_templates.php?action=template_edit&id=1', $response->headers->get('Location'));
        self::assertSame([['save', [42, 1, 5, 'Renamed', '', 'color_id', [11, 12], $this->input->revision]]], $this->writes);

        $this->writes = [];
        self::assertSame(303, $this->postForm('/graphing/graph-templates/1/inputs/new', 'graph_template_input', ['name' => 'New', 'column_name' => 'text_format'])->getStatusCode());
        self::assertSame([['save', [42, 1, null, 'New', '', 'text_format', [], null]]], $this->writes);
    }

    public function testAnUnusableUrlPathFallsBackToTheRoot(): void
    {
        foreach (['//evil.example/', 'https://evil.example/', "/x\n", null] as $path) {
            $this->urlPath = $path;
            $response = $this->postForm('/graphing/graph-templates/1/inputs/new', 'graph_template_input', ['name' => 'New', 'column_name' => 'text_format']);
            self::assertSame('/graph_templates.php?action=template_edit&id=1', $response->headers->get('Location'), var_export($path, true));
        }
    }

    public function testMalformedFormsAreRefusedBeforeTheEditor(): void
    {
        foreach ([
            ['name' => ['x'], 'column_name' => 'text_format'],
            ['name' => 'N', 'column_name' => ['text_format']],
            ['name' => 'N', 'column_name' => 'local_graph_id'],
            ['name' => 'N', 'column_name' => 'text_format, sleep(1)'],
            ['name' => 'N', 'column_name' => 'text_format', 'items' => ['21']],
            ['name' => 'N', 'column_name' => 'text_format', 'items' => '11'],
            ['name' => 'N', 'column_name' => 'text_format', 'items' => [['11']]],
            ['name' => 'N', 'column_name' => 'text_format', 'save_component_input' => '1'],
        ] as $fields) {
            self::assertSame(422, $this->postForm('/graphing/graph-templates/1/inputs/5/edit', 'graph_template_input', $fields + ['revision' => $this->input->revision])->getStatusCode(), json_encode($fields));
        }
        $response = $this->kernel->handle(Request::create('/graphing/graph-templates/1/inputs/5/edit', 'POST', ['graph_template_input' => ['name' => 'N', 'column_name' => 'text_format']]));
        self::assertSame(422, $response->getStatusCode(), 'a form without CSRF evidence is refused');
        $legacyShape = $this->kernel->handle(Request::create('/graphing/graph-templates/1/inputs/5/edit', 'POST', ['action' => 'save', 'name' => 'N', 'column_name' => 'text_format', 'i_11' => 'on'], server: ['HTTP_ORIGIN' => 'http://localhost']));
        self::assertSame(422, $legacyShape->getStatusCode(), 'the legacy field names are not accepted');
        self::assertSame([], $this->writes);
    }

    public function testRefusedAndUncertainSavesKeepTheFormWithTheirStatus(): void
    {
        $stale = $this->postForm('/graphing/graph-templates/1/inputs/5/edit', 'graph_template_input', ['name' => 'N', 'column_name' => 'text_format', 'items' => ['12'], 'revision' => 'stale']);
        self::assertSame(409, $stale->getStatusCode());
        $xpath = $this->xpath($stale);
        self::assertStringContainsString('The Graph Item Input changed since you opened this form. Reload before saving.', (string) $stale->getContent());
        self::assertSame(['12'], array_map(static fn(\DOMElement $box): string => $box->getAttribute('value'), iterator_to_array($xpath->query('//input[@name="graph_template_input[items][]"][@checked]'))), 'the refused form keeps the submitted items');
        self::assertSame(3, $xpath->query('//input[@name="graph_template_input[items][]"]')->length);
        $response = $this->postForm('/graphing/graph-templates/1/inputs/5/edit', 'graph_template_input', ['name' => 'Broken', 'column_name' => 'text_format', 'revision' => $this->input->revision]);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Save outcome is uncertain', (string) $response->getContent());
        self::assertStringNotContainsString('database gone', (string) $response->getContent());
        $this->editorFailure = static fn(array $arguments): \Throwable => match ($arguments[3]) {
            'Denied' => new GraphTemplateInputAccessDenied(false),
            'Foreign' => new \InvalidArgumentException(GraphTemplateInputEditor::RELATIONSHIP),
            default => new \InvalidArgumentException('Enter a name of 1 to 255 characters.'),
        };
        foreach (['Denied' => 403, 'Foreign' => 409, 'Other' => 422] as $name => $status) {
            self::assertSame($status, $this->postForm('/graphing/graph-templates/1/inputs/new', 'graph_template_input', ['name' => $name, 'column_name' => 'text_format'])->getStatusCode(), $name);
        }
    }

    public function testRemovalIsAConfirmedPostThatCarriesTheRevision(): void
    {
        $page = $this->get('/graphing/graph-templates/1/inputs/5/delete');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Graph Item Input: Legend &lt;b&gt;', (string) $page->getContent());
        self::assertSame([], $this->writes, 'GET never deletes');
        self::assertSame(422, $this->kernel->handle(Request::create('/graphing/graph-templates/1/inputs/5/delete', 'POST', ['graph_template_input_delete' => ['revision' => $this->input->revision]]))->getStatusCode());
        self::assertSame(409, $this->postForm('/graphing/graph-templates/1/inputs/5/delete', 'graph_template_input_delete', ['revision' => 'stale'])->getStatusCode());
        self::assertSame([], $this->writes);
        $response = $this->postForm('/graphing/graph-templates/1/inputs/5/delete', 'graph_template_input_delete', ['revision' => $this->input->revision]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cacti/graph_templates.php?action=template_edit&id=1', $response->headers->get('Location'));
        self::assertSame([['remove', [42, 1, 5, $this->input->revision]]], $this->writes);

        $this->editorFailure = static fn(array $arguments): \Throwable => $arguments[3] === 'x' ? new GraphTemplateInputAccessDenied(false) : new \RuntimeException('gone');
        self::assertSame(403, $this->postForm('/graphing/graph-templates/1/inputs/5/delete', 'graph_template_input_delete', ['revision' => 'x'])->getStatusCode());
        $uncertain = $this->postForm('/graphing/graph-templates/1/inputs/5/delete', 'graph_template_input_delete', ['revision' => 'y']);
        self::assertSame(502, $uncertain->getStatusCode());
        self::assertStringContainsString('Delete outcome is uncertain', (string) $uncertain->getContent());
        self::assertSame('', $this->kernel->handle(Request::create('/graphing/graph-templates/1/inputs/5/delete', 'HEAD'))->getContent());
    }

    public function testMissingTemplatesInputsAndOutOfRangeIdsAreNotFound(): void
    {
        foreach (['/graphing/graph-templates/9/inputs/new', '/graphing/graph-templates/1/inputs/6/edit', '/graphing/graph-templates/2/inputs/5/edit',
            '/graphing/graph-templates/1/inputs/6/delete', '/graphing/graph-templates/16777216/inputs/new', '/graphing/graph-templates/1/inputs/16777216/edit',
            '/graphing/graph-templates/0/inputs/new', '/graphing/graph-templates/01/inputs/new', '/graphing/graph-templates/1/inputs/1e2/edit'] as $uri) {
            self::assertSame(404, $this->get($uri)->getStatusCode(), $uri);
        }
        self::assertSame([], $this->writes);
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeAnyRead(): void
    {
        $this->catalog->expects(self::never())->method('templateName');
        $this->catalog->expects(self::never())->method('find');
        $this->catalog->expects(self::never())->method('items');
        $paths = ['/graphing/graph-templates/1/inputs/new', '/graphing/graph-templates/1/inputs/5/edit', '/graphing/graph-templates/1/inputs/5/delete', '/graphing/graph-templates/inputs/legacy?action=input_edit&graph_template_id=1'];
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach ($paths as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->kernel->handle(Request::create($path, 'POST', server: ['HTTP_ORIGIN' => 'http://localhost']))->getStatusCode(), 'POST ' . $path);
            }
        }
        self::assertSame([], $this->writes);
    }

    public function testAnUnreachableDatabaseIsAControlledServiceUnavailable(): void
    {
        $this->offline = true;
        foreach (['/graphing/graph-templates/1/inputs/new', '/graphing/graph-templates/1/inputs/5/delete'] as $path) {
            $response = $this->get($path);
            self::assertSame(503, $response->getStatusCode(), $path);
            self::assertSame('Graph Item Inputs are unavailable. Reload before retrying.', $response->getContent());
            self::assertSame('30', $response->headers->get('Retry-After'));
        }
        $this->accessFailure = new \RuntimeException('SQLSTATE[HY000] [2002] secret host');
        foreach (['/graphing/graph-templates/1/inputs/new', '/graphing/graph-templates/1/inputs/5/edit', '/graphing/graph-templates/1/inputs/5/delete', '/graphing/graph-templates/inputs/legacy?graph_template_id=1'] as $path) {
            $response = $this->get($path);
            self::assertSame(503, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('secret', (string) $response->getContent());
        }
    }

    public function testLegacyUrlsRedirectSafeNavigationAndExpirePostedForms(): void
    {
        $locations = [
            '?action=input_edit&graph_template_id=1' => '/graphing/graph-templates/1/inputs/new',
            '?graph_template_id=1' => '/graphing/graph-templates/1/inputs/new',
            '?action=&graph_template_id=1&id=0' => '/graphing/graph-templates/1/inputs/new',
            '?action=input_edit&id=5&graph_template_id=1&header=false' => '/graphing/graph-templates/1/inputs/5/edit',
            '?action=input_remove&id=5&graph_template_id=1&nostate=true' => '/graphing/graph-templates/1/inputs/5/delete',
        ];
        foreach ($locations as $query => $location) {
            $response = $this->get('/graphing/graph-templates/inputs/legacy' . $query);
            self::assertSame(302, $response->getStatusCode(), $query);
            self::assertSame($location, $response->headers->get('Location'), $query);
        }
        self::assertSame(405, $this->get('/graphing/graph-templates/inputs/legacy?action=save&graph_template_id=1')->getStatusCode());
        $posted = $this->kernel->handle(Request::create('/graphing/graph-templates/inputs/legacy?action=input_remove&id=5&graph_template_id=1', 'POST', ['__csrf_magic' => 'sid:x,1']));
        self::assertSame(409, $posted->getStatusCode());
        foreach (['?action[]=input_edit&graph_template_id=1', '?action=input_edit', '?action=input_edit&graph_template_id=1e2', '?action=input_edit&graph_template_id=01',
            '?action=input_edit&graph_template_id=16777216', '?action=input_edit&graph_template_id[]=1', '?action=input_edit&graph_template_id=1&id=-1',
            '?action=input_remove&graph_template_id=1', '?action=input_remove&graph_template_id=1&id=0', '?action=purge&graph_template_id=1'] as $query) {
            self::assertSame(400, $this->get('/graphing/graph-templates/inputs/legacy' . $query)->getStatusCode(), $query);
        }
        self::assertSame([], $this->writes);
    }

    public function testFrenchIsSelectedFromTheUserPreference(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $preference = $this->createStub(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-FR');
        $container->set(LocalePreference::class, $preference);
        foreach (['/graphing/graph-templates/1/inputs/5/edit' => ['Type de champ', 'Élément n° 1: LINE1 (AVERAGE)', 'Couleur'], '/graphing/graph-templates/1/inputs/5/delete' => ['Supprimer l\'entrée d\'élément de graphique']] as $path => $texts) {
            $request = Request::create($path, cookies: ['Cacti' => 'fixture']);
            $html = html_entity_decode((string) $this->kernel->handle($request)->getContent(), ENT_QUOTES);
            self::assertSame('fr', $request->getLocale(), $path);
            self::assertStringContainsString('<html lang="fr">', $html, $path);
            foreach ($texts as $text) {
                self::assertStringContainsString($text, $html, $path);
            }
            self::assertStringContainsString('Legend <b>', $html, 'stored text is not translated');
        }
        $legacy = Request::create('/graphing/graph-templates/inputs/legacy?action=purge&graph_template_id=1', cookies: ['Cacti' => 'fixture']);
        self::assertSame('Requête d\'entrée d\'élément de graphique non valide.', $this->kernel->handle($legacy)->getContent());
    }

    public function testGermanKeepsTheLegacyTranslationsFromTheUserPreference(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $preference = $this->createStub(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('de-DE');
        $container->set(LocalePreference::class, $preference);
        $request = Request::create('/graphing/graph-templates/1/inputs/5/edit', cookies: ['Cacti' => 'fixture']);
        $html = html_entity_decode((string) $this->kernel->handle($request)->getContent(), ENT_QUOTES);

        self::assertSame('de_DE', $request->getLocale());
        // The legend's msgid is no longer in the shipped .po files, so only
        // graph_template_input.de_DE.yaml can supply this legacy translation.
        self::assertStringContainsString('<legend>Zugeordnete Diagramm Elemente</legend>', $html);
        self::assertStringContainsString('Element #1: LINE1 (AVERAGE)', $html);
        self::assertStringNotContainsString('Associated Graph Items', $html);
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getContent()));
        return new \DOMXPath($document);
    }

    /** @return array<string, string> */
    private function options(\DOMXPath $xpath): array
    {
        $options = [];
        foreach ($xpath->query('//select[@name="graph_template_input[column_name]"]/option') as $option) {
            $options[$option->getAttribute('value')] = $option->textContent;
        }
        return $options;
    }

    /** @param array<string, mixed> $fields */
    private function postForm(string $uri, string $prefix, array $fields): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', [$prefix => $fields + ['_token' => 'csrf-token']], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}

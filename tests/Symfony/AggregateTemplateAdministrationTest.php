<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateCatalog;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateEditor;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateRevision;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\AggregateTemplateConflict;
use Kadupul\AggregateTemplate\Application\Query\AggregateTemplateAccessDenied;
use Kadupul\AggregateTemplate\Infrastructure\Symfony\Form\AggregateTemplateType;
use Kadupul\IdentityAccess\Application\Port\AuthenticatedSession;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AggregateTemplateAdministrationTest extends TestCase
{
    public function testCriteriaRejectsUntrustedSortAndPageOptions(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new AggregateTemplateCriteria(sort: 'name; DROP TABLE aggregate_graph_templates');
    }

    public function testRevisionChangesWhenAggregateSettingsOrItemOrderChanges(): void
    {
        $template = ['id' => 4, 'name' => 'Aggregate', 'graph_template_id' => 2];
        $graph = ['width' => '500'];
        $items = [
            ['sequence' => 0, 'graph_templates_item_id' => 7, 'item_skip' => ''],
            ['sequence' => 1, 'graph_templates_item_id' => 8, 'item_skip' => 'on'],
        ];
        $revision = AggregateTemplateRevision::fromState($template, $graph, $items);
        self::assertNotSame($revision, AggregateTemplateRevision::fromState($template, ['width' => '600'], $items));
        $reordered = [$items[1], $items[0]];
        $reordered[0]['sequence'] = 0;
        $reordered[1]['sequence'] = 1;
        self::assertNotSame($revision, AggregateTemplateRevision::fromState($template, $graph, $reordered));
    }

    public function testItemCheckboxesBindThroughTheNestedAggregateForm(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $forms = $kernel->getContainer()->get('test.service_container')->get(FormFactoryInterface::class);
            $data = $this->newTemplateData();
            $data['template']['items'] = [['id' => 8, 'sequence' => 0, 'forceSkip' => false, 'colorTemplate' => 0, 'skip' => false, 'total' => false]];
            $form = $forms->create(AggregateTemplateType::class, $data['template'], [
                'graph_templates' => ['Source graph' => 3], 'graph_types' => ['Keep' => 0], 'totals' => ['None' => 1],
                'total_types' => ['Similar' => 1], 'order_types' => ['No reordering' => 1],
                'graph_field_names' => ['width'], 'graph_field_metadata' => $data['graphFieldMetadata'], 'color_templates' => ['None' => 0],
            ]);
            $submitted = $data['template'] + ['_token' => 'unused'];
            $submitted['items'] = [['id' => '8', 'sequence' => '0', 'forceSkip' => '0', 'colorTemplate' => '0', 'skip' => '1']];
            $submitted['graphSettings_width'] = ['value' => '640', 'override' => '1'];
            $form->submit($submitted, true);
            self::assertTrue($form->getData()['items'][0]['skip']);
            self::assertFalse($form->getData()['items'][0]['total']);
        } finally {
            $kernel->shutdown();
        }
    }

    public static function editRequests(): iterable
    {
        yield 'success' => ['success', 303];
        yield 'invalid csrf' => ['csrf', 422];
        yield 'changed id' => ['changed-id', 409];
        yield 'extra field' => ['extra', 422];
        yield 'invalid name' => ['invalid', 422];
        yield 'actor revoked during mutation' => ['denied', 403];
        yield 'unknown worker result' => ['failure', 502];
        yield 'stale revision' => ['conflict', 409];
    }

    #[DataProvider('editRequests')]
    public function testSymfonyEditRouteValidatesFormAndReportsWorkerOutcome(string $mode, int $expectedStatus): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $session = $this->createMock(AuthenticatedSession::class);
            $session->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(AuthenticatedSession::class, $session);
            $permissions = $this->createMock(AggregateTemplatePermissions::class);
            $permissions->method('canManage')->willReturn(true);
            $container->set(AggregateTemplatePermissions::class, $permissions);
            $catalog = $this->createMock(AggregateTemplateCatalog::class);
            $catalog->method('editData')->willReturn($this->newTemplateData());
            $container->set(AggregateTemplateCatalog::class, $catalog);
            $editor = $this->createMock(AggregateTemplateEditor::class);
            $savedData = null;
            if ($mode === 'success') {
                $editor->expects(self::once())->method('save')->willReturnCallback(static function (int $actor, int $id, array $data, string $revision) use (&$savedData): int {
                    $savedData = [$actor, $id, $data, $revision];
                    return 9;
                });
            } elseif ($mode === 'denied') {
                $editor->expects(self::once())->method('save')->willThrowException(new AggregateTemplateAccessDenied());
            } elseif ($mode === 'failure') {
                $editor->expects(self::once())->method('save')->willThrowException(new \RuntimeException('private database marker'));
            } elseif ($mode === 'conflict') {
                $editor->expects(self::once())->method('save')->willThrowException(new AggregateTemplateConflict('Aggregate template changed.'));
            } else {
                $editor->expects(self::never())->method('save');
            }
            $container->set(AggregateTemplateEditor::class, $editor);
            $token = $container->get(CsrfTokenManagerInterface::class)->getToken('aggregate_template_edit')->getValue();
            $body = [
                'id' => '0', 'revision' => '', 'name' => $mode === 'invalid' ? '' : 'Aggregate one', 'graph_template_id' => '3',
                'gprint_prefix' => '', 'gprint_format' => '0', 'graph_type' => '0', 'total' => '1', 'total_type' => '1',
                'total_prefix' => '', 'order_type' => '1', 'items' => [],
                'graphSettings_width' => ['value' => '640', 'override' => '1'], '_token' => $token,
            ];
            if ($mode === 'changed-id') {
                $body['id'] = '7';
            }
            if ($mode === 'csrf') {
                unset($body['_token']);
            }
            if ($mode === 'extra') {
                $body['surprise'] = 'unexpected';
            }
            $request = Request::create('/aggregate-templates/0/edit', 'POST', ['aggregate_template' => $body]);
            if ($mode !== 'csrf') {
                $request->headers->set('Origin', 'http://localhost');
            }
            $response = $kernel->handle($request);
            self::assertSame($expectedStatus, $response->getStatusCode(), substr(strip_tags($response->getContent()), 0, 700));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringNotContainsString('private database marker', $response->getContent());
            if ($mode === 'success') {
                self::assertSame('/aggregate-templates/9/edit?saved=1', $response->headers->get('Location'));
                self::assertSame([42, 0, ''], [$savedData[0], $savedData[1], $savedData[3]]);
                self::assertSame('Aggregate one', $savedData[2]['name']);
                self::assertSame(3, (int) $savedData[2]['graph_template_id']);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testListRouteRequiresAuthenticatedConsoleActorAndAggregateRealm(): void
    {
        foreach ([['unauthenticated', null, false, 401], ['missing subsystem realm', new Actor(42, 'operator'), false, 403], ['authorized', new Actor(42, 'operator'), true, 200]] as [$label, $actor, $allowed, $expected]) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $session = $this->createMock(AuthenticatedSession::class);
                $session->method('consoleActor')->willReturn($actor);
                $container->set(AuthenticatedSession::class, $session);
                $permissions = $this->createMock(AggregateTemplatePermissions::class);
                $permissions->method('canManage')->willReturn($allowed);
                $container->set(AggregateTemplatePermissions::class, $permissions);
                $catalog = $this->createMock(AggregateTemplateCatalog::class);
                if ($expected === 200) {
                    $catalog->expects(self::once())->method('list')->willReturn(['rows' => [], 'total' => 0]);
                } else {
                    $catalog->expects(self::never())->method('list');
                }
                $container->set(AggregateTemplateCatalog::class, $catalog);
                $response = $kernel->handle(Request::create('/aggregate-templates'));
                self::assertSame($expected, $response->getStatusCode(), $label);
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            } finally {
                $kernel->shutdown();
            }
        }
    }

    public function testListRejectsMalformedFiltersBeforeDatabaseAccess(): void
    {
        foreach (['page[]=1', 'page=garbage', 'page=0', 'rows[]=30', 'filter[]=x', 'sort[]=name', 'has_graphs[]=on'] as $query) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $session = $this->createMock(AuthenticatedSession::class);
                $session->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $container->set(AuthenticatedSession::class, $session);
                $permissions = $this->createMock(AggregateTemplatePermissions::class);
                $permissions->method('canManage')->willReturn(true);
                $container->set(AggregateTemplatePermissions::class, $permissions);
                $catalog = $this->createMock(AggregateTemplateCatalog::class);
                $catalog->expects(self::never())->method('list');
                $container->set(AggregateTemplateCatalog::class, $catalog);
                $response = $kernel->handle(Request::create('/aggregate-templates?' . $query));
                self::assertSame(400, $response->getStatusCode(), $query);
            } finally {
                $kernel->shutdown();
            }
        }
    }

    private function newTemplateData(): array
    {
        return [
            'template' => [
                'id' => 0, 'name' => '', 'graph_template_id' => 0, 'gprint_prefix' => '', 'gprint_format' => false,
                'graph_type' => 0, 'total' => 1, 'total_type' => 1, 'total_prefix' => '', 'order_type' => 1,
                'revision' => '', 'graphSettings' => ['width' => ['value' => '', 'override' => false]], 'items' => [],
            ],
            'source' => ['id' => 3, 'name' => 'Source graph'], 'graphTemplates' => ['Source graph' => 3],
            'graphSettings' => ['width' => ['value' => '', 'override' => false]],
            'graphFieldMetadata' => ['width' => ['kind' => 'text', 'choices' => [], 'maxLength' => 255]], 'items' => [],
            'colorTemplates' => [], 'graphTypes' => ['Keep Graph Types' => 0], 'totals' => ['No Totals' => 1],
            'totalTypes' => ['Similar' => 1], 'orderTypes' => ['No Reordering' => 1],
        ];
    }
}

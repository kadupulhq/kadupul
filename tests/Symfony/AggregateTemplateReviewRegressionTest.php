<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;
use Kadupul\AggregateTemplate\Infrastructure\Symfony\Controller\LegacyAggregateTemplatesController;
use Kadupul\AggregateTemplate\Infrastructure\Symfony\Form\AggregateTemplateItemType;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\CurrentActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AggregateTemplateReviewRegressionTest extends TestCase
{
    #[DataProvider('unsafePages')]
    public function testUnsafePagesAreRejectedBeforeComputingAnOffset(int $page): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AggregateTemplateCriteria(page: $page, pageSize: 100);
    }

    public static function unsafePages(): array
    {
        return [[50001], [1000000], [PHP_INT_MAX]];
    }

    public function testMaximumSupportedOffsetRemainsAnInteger(): void
    {
        self::assertSame(4999900, (new AggregateTemplateCriteria(page: 50000, pageSize: 100))->offset());
    }

    #[DataProvider('legacyRows')]
    public function testLegacyRowsAndGraphSortRedirectToSupportedFilters(string $rows, ?string $expectedRows): void
    {
        $actor = $this->createMock(CurrentActor::class);
        $actor->method('__invoke')->willReturn(new Actor(9, 'operator'));
        $permissions = $this->createMock(AggregateTemplatePermissions::class);
        $permissions->method('canManage')->willReturn(true);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $expected = ['filter' => 'literal', 'page' => '2', 'sort' => 'graphs', 'direction' => 'desc'];
        if ($expectedRows !== null) {
            $expected['rows'] = $expectedRows;
        }
        $urls->expects(self::once())->method('generate')->with('aggregate_template_list', self::callback(static fn(array $query): bool => $query == $expected))->willReturn('/aggregate-templates');
        $response = (new LegacyAggregateTemplatesController())(
            Request::create('/aggregate-templates/legacy', 'GET', ['filter' => 'literal', 'page' => '2', 'rows' => $rows, 'sort_column' => 'graphs.graphs', 'sort_direction' => 'DESC']),
            $actor,
            $permissions,
            $urls,
            $this->createMock(TranslatorInterface::class),
        );
        self::assertSame(302, $response->getStatusCode());
    }

    public static function legacyRows(): array
    {
        return [['-1', null], ['10', null], ['25', null], ['30', '30'], ['50', '50'], ['100', '100']];
    }

    #[DataProvider('legacyPaths')]
    public function testLegacyGraphsLinkRemainsWithinTheInstallationOrigin(mixed $base, string $expected): void
    {
        self::assertSame($expected, \Kadupul\AggregateTemplate\Domain\AggregateTemplateLegacyPath::graphs($base));
    }

    public static function legacyPaths(): array
    {
        return [
            ['/', '/aggregate_graphs.php'], ['/kadupul/', '/kadupul/aggregate_graphs.php'],
            ['javascript:alert(1)', '/aggregate_graphs.php'], ['//evil.invalid', '/aggregate_graphs.php'],
            ['/\\evil.invalid', '/aggregate_graphs.php'], ["/\nevil.invalid", '/aggregate_graphs.php'],
            ['/kadupul?redirect=x', '/aggregate_graphs.php'], ['/kadupul#fragment', '/aggregate_graphs.php'],
            [[], '/aggregate_graphs.php'], [null, '/aggregate_graphs.php'],
        ];
    }

    #[DataProvider('sourceItems')]
    public function testAuthoritativeSourceItemPolicy(int $type, string $value, string $text, bool $skip): void
    {
        self::assertSame($skip, \Kadupul\AggregateTemplate\Domain\AggregateTemplateItemPolicy::forceSkip($type, $value, $text));
    }

    public static function sourceItems(): array
    {
        return [[3, '', '', true], [30, '', '', true], [40, '', '', true], [1, '', '', true],
            [1, '', 'Visible comment', false], [2, '10', '', true], [2, '|sum:bits|', '', false],
            [4, '', '', false], [8, '', '', false]];
    }

    public function testForcedSkipIsDisabledAndCannotBeClearedByForgedHiddenData(): void
    {
        $form = Forms::createFormFactory()->create(AggregateTemplateItemType::class, [
            'id' => 8, 'sequence' => 0, 'forceSkip' => true, 'colorTemplate' => 0, 'skip' => true, 'total' => false,
        ], ['color_templates' => ['None' => 0]]);
        self::assertTrue($form->get('skip')->isDisabled());
        self::assertTrue($form->createView()['skip']->vars['disabled']);
        $form->submit(['id' => '8', 'sequence' => '0', 'forceSkip' => '0', 'colorTemplate' => '0']);
        self::assertTrue($form->getData()['skip']);
    }
}

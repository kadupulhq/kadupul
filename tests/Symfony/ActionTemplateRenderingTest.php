<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Infrastructure\Symfony\Form\AutomationTemplateDeletionType;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefActionType;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefRevisionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateItemDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileActionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileRraDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\GprintPresetDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\PaletteColorDeletionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bulk confirmation and item deletion pages must keep the markup they had
 * before they shared templates/_action_confirm.html.twig and
 * templates/_item_delete.html.twig. Fixtures were captured from the
 * per-family templates; class attributes and inter-tag whitespace are ignored.
 */
final class ActionTemplateRenderingTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__ . '/Fixtures/action-rendering';

    #[DataProvider('cases')]
    public function testPageMatchesThePreExtractionRendering(string $name, string $template, \Closure $context): void
    {
        $actual = ListTemplateRenderingTest::listRegion(ListTemplateRenderingTest::render($template, $context()));
        self::assertStringEqualsFile(self::GOLDEN_DIR . '/' . $name . '.html', $actual);
    }

    /** @return array<string, array{string, string, \Closure(): array<string, mixed>}> */
    public static function cases(): array
    {
        $form = static fn(string $type, array $data, array $options = []): \Closure => static fn(\Psr\Container\ContainerInterface $container): object => $container->get('form.factory')->create($type, $data, ['csrf_protection' => false] + $options)->createView();
        $bulk = ['selection' => '3,4', 'revisions' => 'a,b'];
        $item = ['revision' => 'r1'];

        return [
            'automation delete' => ['automation-delete', 'automation/automation_template_delete.html.twig', static fn(): array => [
                'form' => $form(AutomationTemplateDeletionType::class, $bulk),
                'templates' => [['deviceTemplate' => 'Cisco', 'sysDescr' => 'IOS <b>'], ['deviceTemplate' => null, 'sysDescr' => '']],
            ]],
            'cdef delete' => ['cdef-delete', 'graph_definition/cdef_action.html.twig', static fn(): array => [
                'operation' => 'delete', 'used' => false, 'cdefs' => [['name' => 'Total'], ['name' => 'x"y']],
                'form' => $form(CdefActionType::class, $bulk),
            ]],
            'cdef delete in use' => ['cdef-delete-used', 'graph_definition/cdef_action.html.twig', static fn(): array => [
                'operation' => 'delete', 'used' => true, 'cdefs' => [['name' => 'Total']],
                'form' => $form(CdefActionType::class, $bulk),
            ]],
            'cdef duplicate' => ['cdef-duplicate', 'graph_definition/cdef_action.html.twig', static fn(): array => [
                'operation' => 'duplicate', 'used' => false, 'cdefs' => [['name' => 'Total']],
                'form' => $form(CdefActionType::class, $bulk + ['title_format' => '<cdef> (1)'], ['duplicate' => true]),
            ]],
            'data source profile delete' => ['dsp-delete', 'graphing/data_source_profile_action.html.twig', static fn(): array => [
                'operation' => 'delete', 'used' => true, 'filters' => ['rows' => 30],
                'profiles' => [
                    ['name' => 'Default', 'isDefault' => true, 'deletable' => false, 'dataSources' => 0, 'templates' => 0],
                    ['name' => 'Busy', 'isDefault' => false, 'deletable' => false, 'dataSources' => 2, 'templates' => 1],
                    ['name' => 'Free', 'isDefault' => false, 'deletable' => true, 'dataSources' => 0, 'templates' => 0],
                ],
                'form' => $form(DataSourceProfileActionType::class, $bulk),
            ]],
            'data source profile duplicate' => ['dsp-duplicate', 'graphing/data_source_profile_action.html.twig', static fn(): array => [
                'operation' => 'duplicate', 'used' => false, 'filters' => ['rows' => 30],
                'profiles' => [['name' => 'Free', 'isDefault' => false, 'deletable' => true, 'dataSources' => 0, 'templates' => 0]],
                'form' => $form(DataSourceProfileActionType::class, $bulk + ['title_format' => '<name>'], ['duplicate' => true]),
            ]],
            'gprint delete' => ['gprint-delete', 'graphing/gprint_preset_delete.html.twig', static fn(): array => [
                'used' => false, 'filters' => ['rows' => 30], 'presets' => [['name' => 'Normal']],
                'form' => $form(GprintPresetDeletionType::class, $bulk),
            ]],
            'gprint delete in use' => ['gprint-delete-used', 'graphing/gprint_preset_delete.html.twig', static fn(): array => [
                'used' => true, 'filters' => ['rows' => 30], 'presets' => [['name' => 'Normal']],
                'form' => $form(GprintPresetDeletionType::class, $bulk),
            ]],
            'palette delete' => ['palette-delete', 'graphing/palette_color_delete.html.twig', static fn(): array => [
                'used' => false, 'presets' => [['hex' => 'ff0000', 'visibleName' => true, 'displayName' => 'Red'], ['hex' => '00ff00', 'visibleName' => false, 'displayName' => '']],
                'form' => $form(PaletteColorDeletionType::class, $bulk),
            ]],
            'palette delete in use' => ['palette-delete-used', 'graphing/palette_color_delete.html.twig', static fn(): array => [
                'used' => true, 'presets' => [['hex' => 'ff0000', 'visibleName' => false, 'displayName' => '']],
                'form' => $form(PaletteColorDeletionType::class, $bulk),
            ]],
            'cdef item delete' => ['cdef-item-delete', 'graph_definition/cdef_item_delete.html.twig', static fn(): array => [
                'cdef' => ['id' => 5, 'name' => 'Total'], 'types' => [1 => 'Function', 4 => 'Special Data Source'],
                'item' => ['type' => 4, 'label' => 'Current Graph Maximum Value'],
                'form' => $form(CdefRevisionType::class, $item),
            ]],
            'cdef function item delete' => ['cdef-item-delete-function', 'graph_definition/cdef_item_delete.html.twig', static fn(): array => [
                'cdef' => ['id' => 5, 'name' => 'Total'], 'types' => [1 => 'Function'],
                'item' => ['type' => 1, 'label' => 'SUM'],
                'form' => $form(CdefRevisionType::class, $item),
            ]],
            'color template item delete' => ['color-template-item-delete', 'graphing/color_template_item_delete.html.twig', static fn(): array => [
                'template' => ['id' => 7, 'name' => 'Warm'], 'item' => ['hex' => 'ff0000'],
                'form' => $form(ColorTemplateItemDeletionType::class, $item),
            ]],
            'data source profile rra delete' => ['dsp-rra-delete', 'graphing/data_source_profile_rra_delete.html.twig', static fn(): array => [
                'profile' => ['id' => 2, 'name' => 'Default', 'readOnly' => false], 'archive' => ['name' => 'Daily'],
                'form' => $form(DataSourceProfileRraDeletionType::class, $item),
            ]],
            'data source profile rra delete read only' => ['dsp-rra-delete-read-only', 'graphing/data_source_profile_rra_delete.html.twig', static fn(): array => [
                'profile' => ['id' => 2, 'name' => 'Default', 'readOnly' => true], 'archive' => ['name' => 'Daily'],
                'form' => $form(DataSourceProfileRraDeletionType::class, $item),
            ]],
        ];
    }
}

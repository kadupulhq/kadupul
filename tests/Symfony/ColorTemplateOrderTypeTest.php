<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateOrderType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class ColorTemplateOrderTypeTest extends TestCase
{
    public function testTwoRenderedOrderFormsHaveNonemptyUniqueFormAndFieldIds(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new ColorTemplateOrderType())
            ->getFormFactory();
        $names = ['color_template_order_12_34_up', 'color_template_order_12_35_down'];
        $formIds = [];
        $ids = [];

        foreach ($names as $name) {
            $form = $factory->createNamed($name, ColorTemplateOrderType::class, [
                'order' => '[34,35]',
                'revision' => hash('sha256', '[34,35]'),
            ], [
                'csrf_protection' => false,
            ]);
            $view = $form->createView();
            $formIds[] = $view->vars['id'];
            $ids[] = $view->vars['id'];
            $ids[] = $view['order']->vars['id'];
            $ids[] = $view['revision']->vars['id'];
            $ids[] = $view['save']->vars['id'];
        }

        self::assertSame($names, $formIds);
        self::assertNotContains('', $ids);
        self::assertCount(count($ids), array_unique($ids));
    }
}

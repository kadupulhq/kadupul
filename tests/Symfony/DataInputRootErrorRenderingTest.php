<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Infrastructure\Symfony\Form\DataInputActionType;
use Kadupul\DataInput\Infrastructure\Symfony\Form\DataInputFieldType;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;

final class DataInputRootErrorRenderingTest extends TestCase
{
    /** @dataProvider confirmationTemplates */
    public function testRootValidationFailureAppearsOnce(string $template, string $type): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $form = $container->get(FormFactoryInterface::class)->create($type, null, ['csrf_protection' => false]);
            $form->submit([]);
            $marker = 'Reviewed root validation failure';
            $form->addError(new FormError($marker));
            $html = $container->get('twig')->render($template, ['state' => ['method' => ['id' => 3, 'name' => 'Fixture']], 'operation' => 'delete', 'names' => ['Fixture'], 'selected_field' => null, 'form' => $form->createView()]);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($html));
            self::assertSame(1, substr_count($document->textContent, $marker));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function confirmationTemplates(): array
    {
        return [['data_input/action.html.twig', DataInputActionType::class], ['data_input/bulk.html.twig', DataInputActionType::class], ['data_input/field.html.twig', DataInputFieldType::class]];
    }
}

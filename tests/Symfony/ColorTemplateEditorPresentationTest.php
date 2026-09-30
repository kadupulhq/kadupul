<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Command\SaveColorTemplate;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Domain\ColorTemplate;
use Kadupul\ColorTemplates\Domain\ColorTemplateItem;
use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ColorTemplateEditorPresentationTest extends TestCase
{
    public function testEditorRendersStableDistinctReorderFormsForTwoItems(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');

            $access = $this->createMock(ColorTemplateAccess::class);
            $access->method('authorize')->willReturn(new Actor(42, 'operator'));

            $store = $this->createMock(ColorTemplateStore::class);
            $store->method('defaultRows')->willReturn(25);
            $store->method('defaultHasGraphs')->willReturn(false);
            $store->method('find')->with(12)->willReturn(new ColorTemplate(12, 'Presentation palette', 0, 0, 2));
            $store->method('items')->with(12)->willReturn([
                new ColorTemplateItem(31, 12, 5, 1, 'ff0000'),
                new ColorTemplateItem(32, 12, 6, 2, '00ff00'),
            ]);

            $container->set(ColorTemplateAccess::class, $access);
            $container->set(ColorTemplateStore::class, $store);
            $container->set(SaveColorTemplate::class, new SaveColorTemplate($access, $store));

            $response = $kernel->handle(Request::create('/graphing/color-templates/12/edit'));

            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($response->getContent()));
            $xpath = new \DOMXPath($document);
            $forms = $xpath->query('//form[contains(@action, "/graphing/color-templates/12/items/order")]');
            self::assertNotFalse($forms);
            self::assertCount(2, $forms);

            $expectedRevision = hash('sha256', json_encode([31, 32], JSON_THROW_ON_ERROR));
            $formIds = [];
            $fieldIds = [];
            $revisions = [];
            $orders = [];
            foreach ($forms as $form) {
                $formId = $form->getAttribute('id');
                self::assertNotSame('', $formId);
                $formIds[] = $formId;

                foreach ($xpath->query('.//*[@id]', $form) as $field) {
                    self::assertNotSame('', $field->getAttribute('id'));
                    $fieldIds[] = $field->getAttribute('id');
                }

                $prefix = $formId . '[';
                $values = [];
                foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
                    $name = $input->getAttribute('name');
                    if (str_starts_with($name, $prefix)) {
                        $values[substr($name, strlen($formId) + 1, -1)] = $input->getAttribute('value');
                    }
                }
                self::assertSame($expectedRevision, $values['revision'] ?? null);
                $revisions[] = $values['revision'];
                $orders[] = json_decode($values['order'] ?? '', true, 8, JSON_THROW_ON_ERROR);
            }

            self::assertCount(count($formIds), array_unique($formIds));
            self::assertCount(count($fieldIds), array_unique($fieldIds));
            self::assertSame([$expectedRevision, $expectedRevision], $revisions);
            self::assertSame([[32, 31], [32, 31]], $orders);
        } finally {
            $kernel->shutdown();
        }
    }
}

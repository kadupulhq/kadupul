<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\Navigation\Domain\ExternalLink;
use Kadupul\Navigation\Infrastructure\Symfony\Form\LinkType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class LinkChoicePresentationTest extends TestCase
{
    public function testLegacyNewSentinelNameRemainsLiteralThroughRenderingAndSubmission(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale('en');
            $link = new ExternalLink(1, 1, 'Example', 'https://example.org', 'CONSOLE', '__NEW__', true, 0);
            $fields = $link->fields([]) + ['revision' => str_repeat('a', 64)];
            $form = $container->get(FormFactoryInterface::class)->create(LinkType::class, $fields, ['files' => [], 'sections' => ['__NEW__'], 'csrf_protection' => false]);
            $html = $container->get('twig')->render('navigation/link_edit.html.twig', ['link' => $link, 'form' => $form->createView()]);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($html));
            $xpath = new \DOMXPath($document);
            $option = $xpath->query('//select[@name="link[consolesection]"]/option[@value="__NEW__"]');
            self::assertSame(1, $option->length);
            self::assertSame('__NEW__', $option->item(0)->textContent);
            self::assertTrue($option->item(0)->hasAttribute('selected'));
            self::assertSame(3, $xpath->query('//select[@name="link[consolesection]"]/option')->length);
            $fields['refresh'] = '0';
            $fields['enabled'] = '1';
            $form->submit($fields);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame('__NEW__', ExternalLink::validate($form->getData(), [])['extendedstyle']);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFrenchEditorPreservesStoredNamesAndTranslatesSyntheticLabels(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale('fr');
            $form = $container->get(FormFactoryInterface::class)->create(LinkType::class, null, ['files' => ['Title'], 'sections' => ['Title', 'New Name Below'], 'csrf_protection' => false]);
            $html = $container->get('twig')->render('navigation/link_edit.html.twig', ['link' => null, 'form' => $form->createView()]);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">' . $html));
            $xpath = new \DOMXPath($document);
            foreach ([['consolesection', 'Title', 'Title'], ['filename', 'Title', 'Title'], ['consolesection', 'New Name Below', 'New Name Below'], ['consolesection', ExternalLink::NEW_SECTION_SELECTION, 'Nouveau nom ci-dessous'], ['filename', '0', 'URL ci-dessous']] as [$field, $value, $label]) {
                $option = $xpath->query('//select[@name="link[' . $field . ']"]/option[@value="' . $value . '"]');
                self::assertSame(1, $option->length);
                self::assertSame($label, $option->item(0)->textContent);
            }
            $form->submit(['title' => 'Example', 'style' => 'CONSOLE', 'filename' => 'Title', 'fileurl' => '', 'consolesection' => 'Title', 'consolenewsection' => '', 'enabled' => '1', 'refresh' => '0', 'revision' => str_repeat('a', 64)]);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame('Title', $form->getData()['filename']);
            self::assertSame('Title', $form->getData()['consolesection']);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEnglishListShowsFriendlyStyleNames(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale('en');
            $links = [];
            foreach (ExternalLink::STYLES as $index => $style) {
                $links[] = new ExternalLink($index + 1, $index, 'Example', 'Title', $style, '', true, 0);
            }
            $html = $container->get('twig')->render('navigation/links.html.twig', ['links' => $links, 'filters' => ['filter' => '', 'rows' => 10, 'sort_column' => 'sortorder', 'sort_direction' => 'ASC', 'page' => 1, 'limit' => 10], 'total' => 4, 'viewPath' => '/link.php']);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($html));
            $styles = (new \DOMXPath($document))->query('//tbody/tr/td[5]');
            self::assertSame(['Top Tab', 'Console (External Links)', 'Bottom Console', 'Top Console'], array_map(static fn(\DOMNode $node): string => trim($node->textContent), iterator_to_array($styles)));
        } finally {
            $kernel->shutdown();
        }
    }
}

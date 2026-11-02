<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InstallerPresentationTest extends TestCase
{
    private function render(array $overrides = [], string $locale = 'en'): \DOMXPath
    {
        $root = dirname(__DIR__, 2);
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root . '/config/translations/installer.' . $locale . '.yaml', $locale, 'installer');
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));
        $plan = array_replace([
            'mode' => 'install', 'target' => '1.2.38', 'schema' => 'new_install', 'collector' => 1,
            'job' => ['state' => 'not_started', 'phase' => 'Starting', 'progress' => 0, 'error' => null, 'attempt' => 0],
            'checks' => [['name' => 'PHP runtime', 'ok' => true, 'observed' => '8.4', 'remedy' => '']],
            'reviewed' => false, 'ready' => true, 'revision' => str_repeat('a', 64),
            'profiles' => [['id' => 1, 'name' => '<Default>']], 'profile' => 1,
            'discovery' => false, 'override' => false, 'range' => '127.0.0.1/32',
            'languages' => ['en' => 'English'], 'language' => 'en', 'themes' => ['modern'], 'theme' => 'modern',
            'snmp' => [['name' => 'password', 'label' => 'Password', 'choices' => [], 'value' => '', 'limit' => 255, 'secret' => true]],
            'tables' => [], 'cron' => '300', 'paths' => ['path_php_binary' => '/usr/bin/php'],
            'templates' => [['key' => 'local', 'name' => '<Local>', 'selected' => true], ['key' => 'other', 'name' => 'Other', 'selected' => false]],
        ], $overrides);
        $html = $twig->render('platform/installer.html.twig', ['plan' => $plan, 'error' => null,
            'view' => ['action' => '/monitor/install/install.php', 'root' => '/monitor', 'locale' => $locale, 'csrf' => 'fixture-token']]);
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
        return new \DOMXPath($document);
    }

    public function testOptionalGroupsRetainSuccessfulFormControlsAndCurrentStage(): void
    {
        $xpath = $this->render();
        self::assertSame('Configure', $xpath->evaluate('string(//li[@aria-current="step"])'));
        foreach (['collector-settings', 'discovery-settings', 'advanced-settings', 'template-settings'] as $id) {
            self::assertSame(1, $xpath->query('//details[@id="' . $id . '"]/summary')->length);
            self::assertSame(0, $xpath->query('//details[@id="' . $id . '"][@open]')->length);
        }
        self::assertSame(1, $xpath->query('//details[@id="discovery-settings"]//input[@name="options[SnmpOptions][password]"][@value=""]')->length);
        self::assertSame(1, $xpath->query('//details[@id="template-settings"]//input[@name="options[Templates][local]"][@checked]')->length);
        self::assertSame('form_complete', $xpath->evaluate('string(//form[input[@name="action"][@value="configure"]]/input[last()]/@name)'));
        self::assertSame(1, $xpath->query('//form[not(@data-installer-form)][input[@name="action"][@value="compare_runtime"]]//input[@name="runtime_confirmed"][@required]')->length);
        self::assertSame('fixture-token', $xpath->evaluate('string(//form[input[@name="action"][@value="compare_runtime"]]/input[@name="__csrf_magic"]/@value)'));
        self::assertSame('/monitor/install/install.php?view=diagnostics', $xpath->evaluate('string(//a[@download]/@href)'));
    }

    public function testReviewedPlanShowsSelectedValuesAndBackupsWithoutTreatingThemAsVerified(): void
    {
        $xpath = $this->render(['reviewed' => true]);
        self::assertSame('Review', $xpath->evaluate('string(//li[@aria-current="step"])'));
        self::assertSame('<Default> (#1)', $xpath->evaluate('string(//section[h2[@id="review"]]//dt[.="Collection profile"]/following-sibling::dd[1])'));
        self::assertSame('<Local>', $xpath->evaluate('string(//section[h2[@id="review"]]//details//li)'));
        self::assertSame(3, $xpath->query('//section[h2[@id="review"]]/ul[@class="checklist"]/li')->length);
        self::assertSame(1, $xpath->query('//input[@name="confirmed"][@required]')->length);
        self::assertSame(0, $xpath->query('//script[not(@src)]')->length);
    }

    public function testBlockedChecksAreExpandedAndAdvisoriesRemainSeparate(): void
    {
        $advisory = ['name' => 'Database privileges', 'status' => 'not_assessed', 'observed' => 'Not probed', 'remedy' => 'Review deployment privileges'];
        $xpath = $this->render(['advisories' => [$advisory]]);
        self::assertSame('Configure', $xpath->evaluate('string(//li[@aria-current="step"])'));
        self::assertSame(1, $xpath->query('//section[h2[@id="requirements"]]/aside')->length);
        $xpath = $this->render(['ready' => false, 'checks' => [['name' => 'PHP runtime', 'ok' => false, 'observed' => 'missing', 'remedy' => 'Enable PHP']], 'advisories' => [$advisory]]);
        self::assertSame('Check requirements', $xpath->evaluate('string(//li[@aria-current="step"])'));
        self::assertSame(1, $xpath->query('//section[h2[@id="requirements"]]/details[@open]')->length);
    }

    public function testRecoveryAndActiveWorkerKeepDistinctExplicitActions(): void
    {
        foreach (['failed', 'partial'] as $state) {
            $xpath = $this->render(['mode' => 'finish', 'job' => ['state' => $state, 'phase' => 'Stopped', 'progress' => 90, 'error' => 'Retain logs', 'attempt' => 2], 'reviewed' => true]);
            self::assertSame('Recover', $xpath->evaluate('string(//li[@aria-current="step"])'));
            self::assertSame(4, $xpath->query('//section[h2[@id="recovery"]]//li')->length);
            self::assertSame(1, $xpath->query('//input[@name="retry_confirmed"][@required]')->length);
            self::assertSame(0, $xpath->query('//h2[@id="verification"]')->length);
        }
        $xpath = $this->render(['job' => ['state' => 'running', 'phase' => 'Working', 'progress' => 40, 'error' => null, 'attempt' => 3]]);
        self::assertSame('Run', $xpath->evaluate('string(//li[@aria-current="step"])'));
        self::assertSame(1, $xpath->query('//details[not(@open)]//input[@name="recovery_confirmed"][@required]')->length);
        self::assertSame('3', $xpath->evaluate('string(//input[@name="attempt"]/@value)'));
        self::assertSame(0, $xpath->query('//input[@name="action"][@value="configure"]')->length);
    }

    public function testHealthEvidenceAndMissingEvidenceAreExplicitAndTranslated(): void
    {
        $health = [['name' => 'Schema version', 'status' => 'verified', 'observed' => '1.2.38', 'remedy' => ''],
            ['name' => 'Collector', 'status' => 'action_required', 'observed' => '1', 'remedy' => 'Check requirements'],
            ['name' => 'RRD', 'status' => 'not_assessed', 'observed' => 'Backup restoration was not exercised.', 'remedy' => '']];
        $xpath = $this->render(['mode' => 'complete', 'job' => ['state' => 'succeeded', 'phase' => 'Finished execution', 'progress' => 100, 'error' => null, 'health' => $health]], 'fr');
        self::assertSame('Vérifier', $xpath->evaluate('string(//li[@aria-current="step"])'));
        self::assertSame(['Vérifié', 'Action requise', 'Non évalué'], array_map(static fn($node): string => $node->textContent, iterator_to_array($xpath->query('//section[h2[@id="health"]]//tbody/tr/td[1]'))));
        self::assertSame('La restauration des sauvegardes n’a pas été testée.', $xpath->evaluate('string(//section[h2[@id="health"]]//tbody/tr[3]/td[2])'));
        $xpath = $this->render(['mode' => 'complete', 'job' => ['state' => 'succeeded', 'phase' => 'Finished', 'progress' => 100, 'error' => null]]);
        self::assertStringContainsString('Operational checks still require operator verification.', $xpath->evaluate('string(//section[h2[@id="health"]])'));
    }
}

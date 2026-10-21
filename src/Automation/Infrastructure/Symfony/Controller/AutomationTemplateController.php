<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplatePreferences;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Automation\Domain\AvailabilityMethods;
use Kadupul\Automation\Infrastructure\Symfony\Form\AutomationTemplateOrderType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AutomationTemplateController
{
    #[Route('/automation/templates', name: 'automation_templates', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, AutomationTemplatePreferences $preferences, LegacyConfiguration $configuration, FormFactoryInterface $forms, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $flags = [];
            foreach (['clear', 'deleted', 'saved'] as $flag) {
                $flags[$flag] = $query[$flag] ?? null;
                unset($query[$flag]);
                if ($flags[$flag] !== null && $flags[$flag] !== '1') {
                    throw new \InvalidArgumentException('Invalid automation template filters.');
                }
            }
            if ($query === [] && $flags['clear'] === null) {
                $query = $preferences->load() ?? [];
            }
            $defaultRows = $store->defaultRows();
            $filters = AutomationTemplateFilters::fromQuery($query, $defaultRows);
            if (($configuration->values()['collector_id'] ?? null) === 1
                && ($flags['clear'] !== null || array_intersect(array_keys($request->query->all()), AutomationTemplateFilters::KEYS) !== [])) {
                $preferences->save($filters->query());
            }
            $page = $store->list($filters);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid automation template filters.', [], 'automation'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load automation templates. Reload before retrying.', [], 'automation'), 502, $headers);
        }
        $moves = [];
        foreach ($page->templates as $template) {
            $moves[$template->id] = $forms->createNamed('automation_template_order_' . $template->id, AutomationTemplateOrderType::class, ['revision' => $page->order])->createView();
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/automation_templates.html.twig', [
            'page' => $page,
            'filters' => $filters->query(),
            'defaultRows' => $defaultRows,
            'availability' => AvailabilityMethods::LABELS,
            'moves' => $moves,
            'deleted' => $flags['deleted'] === '1',
            'saved' => $flags['saved'] === '1',
        ]), 200, $headers);
    }
}

<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony\Controller;

use Kadupul\Collection\Application\Query\AutomationAccessDenied;
use Kadupul\Collection\Application\Query\ListAutomationGraphRules;
use Kadupul\Collection\Infrastructure\Symfony\AutomationGraphRuleListParameters;
use Kadupul\Collection\Infrastructure\Symfony\Form\AutomationGraphRuleFilterType;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AutomationGraphRuleListController
{
    #[Route('/automation/graph-rules', name: 'automation_graph_rule_list', methods: ['GET', 'HEAD'])]
    public function __invoke(
        Request $request,
        ListAutomationGraphRules $list,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        LegacyConfiguration $configuration
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $formData = AutomationGraphRuleListParameters::formData($request->query->all());
            $form = $forms->create(AutomationGraphRuleFilterType::class, [
                'q' => $formData['q'] ?? '',
                'data_query' => $formData['data_query'] ?? '',
                'status' => $formData['status'] ?? 'all',
                'size' => $formData['size'] ?? '25',
                'sort' => $formData['sort'] ?? 'name',
                'direction' => $formData['direction'] ?? 'asc',
            ], ['method' => 'GET', 'action' => $urls->generate('automation_graph_rule_list')]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && !$form->isValid()) {
                return new Response($translator->trans('Invalid automation graph rule filters.', [], 'collection'), 400, $headers);
            }
            $criteria = AutomationGraphRuleListParameters::parse($request->query->all(), $form->getData());
            $result = $list($criteria);
        } catch (AutomationAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collection'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid automation graph rule filters.', [], 'collection'), 400, $headers);
        }

        $content = $twig->render('collection/automation_graph_rules.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'form' => $form->createView(),
            'filters' => ['automation_graph_rule_filter' => $form->getData()],
            'legacyEditBase' => rtrim((string) ($configuration->values()['url_path'] ?? '/'), '/') . '/automation_graph_rules.php?action=edit&id=',
            'legacyListUrl' => rtrim((string) ($configuration->values()['url_path'] ?? '/'), '/') . '/automation_graph_rules.php',
        ]);
        return new Response($request->isMethod('HEAD') ? '' : $content, 200, $headers);
    }
}

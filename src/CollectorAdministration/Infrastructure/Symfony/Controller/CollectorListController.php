<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Application\Query\ListCollectors;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\CollectorListParameters;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\Form\CollectorFilterType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CollectorListController
{
    #[Route('/collectors', name: 'collector_list', methods: ['GET', 'HEAD'])]
    public function __invoke(
        Request $request,
        ListCollectors $list,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $formData = CollectorListParameters::formData($request->query->all());
            $form = $forms->create(CollectorFilterType::class, [
                'q' => $formData['q'] ?? '',
                'size' => $formData['size'] ?? '25',
                'sort' => $formData['sort'] ?? 'name',
                'direction' => $formData['direction'] ?? 'asc',
            ], ['method' => 'GET', 'action' => $urls->generate('collector_list')]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && !$form->isValid()) {
                return new Response($translator->trans('Invalid collector list filters.', [], 'collectors'), 400, $headers);
            }
            $criteria = CollectorListParameters::parse($request->query->all(), $form->getData());
            $result = $list($criteria);
        } catch (CollectorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid collector list filters.', [], 'collectors'), 400, $headers);
        }

        $content = $twig->render('collectors/index.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'form' => $form->createView(),
            'filters' => [
                'collector_filter' => $form->getData(),
            ],
        ]);
        return new Response($request->isMethod('HEAD') ? '' : $content, 200, $headers);
    }
}

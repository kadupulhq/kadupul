<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony\Controller;

use Kadupul\Collection\Application\Query\AutomationAccessDenied;
use Kadupul\Collection\Application\Query\ListNetworks;
use Kadupul\Collection\Infrastructure\Symfony\Form\NetworkFilterType;
use Kadupul\Collection\Infrastructure\Symfony\NetworkListParameters;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class NetworkListController
{
    #[Route('/automation/networks', name: 'automation_networks', methods: ['GET', 'HEAD'])]
    public function __invoke(
        Request $request,
        ListNetworks $list,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        LegacyConfiguration $configuration
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $formData = NetworkListParameters::formData($request->query->all());
            $form = $forms->create(NetworkFilterType::class, [
                'q' => $formData['q'] ?? '',
                'size' => $formData['size'] ?? '25',
                'sort' => $formData['sort'] ?? 'name',
                'direction' => $formData['direction'] ?? 'asc',
            ], ['method' => 'GET', 'action' => $urls->generate('automation_networks')]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && !$form->isValid()) {
                return new Response($translator->trans('Invalid network list filters.', [], 'collection'), 400, $headers);
            }
            $criteria = NetworkListParameters::parse($request->query->all(), $form->getData());
            $result = $list($criteria);
        } catch (AutomationAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collection'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid network list filters.', [], 'collection'), 400, $headers);
        }

        $content = $twig->render('collection/networks.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'form' => $form->createView(),
            'filters' => ['network_filter' => $form->getData()],
            'legacyEditBase' => rtrim((string) ($configuration->values()['url_path'] ?? '/'), '/') . '/automation_networks.php?action=edit&id=',
        ]);
        return new Response($request->isMethod('HEAD') ? '' : $content, 200, $headers);
    }
}

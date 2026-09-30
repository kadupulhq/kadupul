<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Doctrine\DBAL\Exception;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Application\Query\ListCollectors;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\CollectorListParameters;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\Form\CollectorFilterType;
use Symfony\Component\Form\FormFactoryInterface;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
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
        TranslatorInterface $translator,
        ConsoleAccess $access
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $actor === null ? 401 : 403, $headers);
        }
        try {
            $formData = CollectorListParameters::formData($request->query->all());
            $form = $forms->create(CollectorFilterType::class, [
                'q' => $formData['q'] ?? '',
                'size' => $formData['size'] ?? (string) $list->defaultPageSize(),
                'sort' => $formData['sort'] ?? 'name',
                'direction' => $formData['direction'] ?? 'asc',
                'refresh' => $formData['refresh'] ?? '20',
            ], ['method' => 'GET', 'action' => $urls->generate('collector_list')]);
            if ($request->query->has('collector_filter')) {
                // An omitted GET field retains its validated default. Explicit
                // malformed fields are rejected by the parser and form.
                $form->submit($formData, false);
            }
            if ($form->isSubmitted() && !$form->isValid()) {
                return new Response($translator->trans('Invalid collector list filters.', [], 'collectors'), 400, $headers);
            }
            $criteria = CollectorListParameters::parse($request->query->all(), $form->getData());
            $result = $list($criteria);
        } catch (CollectorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid collector list filters.', [], 'collectors'), 400, $headers);
        } catch (Exception) {
            return new Response($translator->trans('Unable to load Data Collectors. Please reload before retrying.', [], 'collectors'), 502, $headers);
        }

        $content = $twig->render('collectors/index.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'form' => $form->createView(),
            'filters' => [
                'collector_filter' => $form->getData(),
            ],
        ]);
        if ($criteria->refresh > 0) {
            $headers['Refresh'] = (string) $criteria->refresh;
        }
        return new Response($request->isMethod('HEAD') ? '' : $content, 200, $headers);
    }
}

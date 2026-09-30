<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony\Controller;

use Kadupul\Collection\Application\Query\AutomationAccessDenied;
use Kadupul\Collection\Application\Query\ListDiscoveredDevices;
use Kadupul\Collection\Infrastructure\Symfony\DiscoveredDeviceListParameters;
use Kadupul\Collection\Infrastructure\Symfony\Form\DiscoveredDeviceFilterType;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormFactoryInterface;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DiscoveredDeviceListController
{
    #[Route('/automation/devices', name: 'automation_device_list', methods: ['GET', 'HEAD'])]
    public function __invoke(
        Request $request,
        ListDiscoveredDevices $list,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        LegacyConfiguration $configuration,
        ConsoleAccess $access
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageAutomation($actor)) {
            return new Response($translator->trans('Access denied.', [], 'collection'), $actor === null ? 401 : 403, $headers);
        }
        try {
            $formData = DiscoveredDeviceListParameters::formData($request->query->all());
            $criteria = DiscoveredDeviceListParameters::parse($request->query->all(), $formData);
            $result = $list($criteria);
            $form = $forms->create(DiscoveredDeviceFilterType::class, [
                'q' => $formData['q'] ?? '',
                'network' => $formData['network'] ?? '-1',
                'status' => $formData['status'] ?? 'all',
                'snmp' => $formData['snmp'] ?? 'all',
                'os' => $formData['os'] ?? '',
                'size' => $formData['size'] ?? '25',
                'sort' => $formData['sort'] ?? 'hostname',
                'direction' => $formData['direction'] ?? 'asc',
            ], [
                'method' => 'GET',
                'action' => $urls->generate('automation_device_list'),
                'networks' => $result->networks,
                'operating_systems' => $result->operatingSystems,
            ]);
            if ($request->query->has($form->getName())) {
                $form->submit($formData, false);
            }
            if ($form->isSubmitted() && !$form->isValid()) {
                return new Response($translator->trans('Invalid automation device filters.', [], 'collection'), 400, $headers);
            }
            if ($form->isSubmitted()) {
                $criteria = DiscoveredDeviceListParameters::parse($request->query->all(), $form->getData());
                $result = $list($criteria);
            }
        } catch (AutomationAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collection'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid automation device filters.', [], 'collection'), 400, $headers);
        }

        $content = $twig->render('collection/discovered_devices.html.twig', [
            'result' => $result,
            'criteria' => $criteria,
            'form' => $form->createView(),
            'filters' => ['discovery_filter' => $form->getData()],
            'legacyListUrl' => rtrim((string) ($configuration->values()['url_path'] ?? '/'), '/') . '/automation_devices.php',
        ]);
        return new Response($request->isMethod('HEAD') ? '' : $content, 200, $headers);
    }
}

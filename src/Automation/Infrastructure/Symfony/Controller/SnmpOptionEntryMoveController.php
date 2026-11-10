<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\SnmpOptionStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionEntryConfirmType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SnmpOptionEntryMoveController
{
    #[Route('/automation/snmp-options/{id}/entries/{entry}/move/{direction}', name: 'automation_snmp_entry_move', requirements: ['id' => '[1-9][0-9]{0,9}', 'entry' => '[1-9][0-9]{0,9}', 'direction' => 'up|down'], methods: ['POST'])]
    public function __invoke(int $id, int $entry, string $direction, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $form = $forms->createNamed('automation_snmp_entry_order_' . $entry, SnmpOptionEntryConfirmType::class);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return new Response($translator->trans('Invalid SNMP entry order.', [], 'automation'), 422, $headers);
            }
            $store->moveEntry($actor->id, $id, $entry, $direction === 'up' ? -1 : 1, SnmpOptionSetEditController::field(SnmpOptionSetEditController::data($form), 'revision'));
            return new RedirectResponse($urls->generate('automation_snmp_option_edit', ['id' => $id, 'entry' => 'moved']), 303, $headers);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'automation'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Move outcome is uncertain. Reload before retrying.', [], 'automation'), 502, $headers);
        }
    }
}

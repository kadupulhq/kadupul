<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Infrastructure\Symfony\Form\AutomationTemplateOrderType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AutomationTemplateMoveController
{
    #[Route('/automation/templates/{id}/move/{direction}', name: 'automation_template_move', requirements: ['id' => '[1-9][0-9]{0,7}', 'direction' => 'up|down'], methods: ['POST'])]
    public function __invoke(int $id, string $direction, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $form = $forms->createNamed('automation_template_order_' . $id, AutomationTemplateOrderType::class);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return new Response($translator->trans('Invalid automation template order.', [], 'automation'), 422, $headers);
            }
            $store->move($actor->id, $id, $direction === 'up' ? -1 : 1, (string) $form->getData()['revision']);
            // Return to the list the move was made from; its filters are restored from preferences.
            return new RedirectResponse($urls->generate('automation_templates'), 303, $headers);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'automation'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Move outcome is uncertain. Reload before retrying.', [], 'automation'), 502, $headers);
        }
    }
}

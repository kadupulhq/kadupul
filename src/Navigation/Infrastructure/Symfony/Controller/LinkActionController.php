<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Application\Query\ListLinks;
use Kadupul\Navigation\Application\Command\ChangeLinks;
use Kadupul\Navigation\Domain\ExternalLink;
use Kadupul\Navigation\Infrastructure\Symfony\LinkFormFailure;
use Kadupul\Navigation\Infrastructure\Symfony\LinkListParameters;
use Kadupul\Navigation\Infrastructure\Symfony\Form\LinkActionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class LinkActionController
{
    #[Route('/links/action/{operation}', name: 'navigation_link_action', requirements: ['operation' => 'delete|enable|disable|up|down'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(
        string $operation,
        Request $request,
        ConsoleAccess $console,
        LinkAccess $access,
        ListLinks $list,
        ChangeLinks $change,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        LinkFormFailure $failure
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'navigation'), 401, $headers);
        }
        try {
            $access->authorize();
        } catch (LinkAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'navigation'), $error->unauthenticated ? 401 : 403, $headers);
        }
        try {
            $ids = LinkListParameters::ids($request->query->all()['ids'] ?? null);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'navigation'), 400, $headers);
        }
        if (in_array($operation, ['up', 'down'], true) && count($ids) !== 1) {
            return new Response($translator->trans('Invalid link selection.', [], 'navigation'), 400, $headers);
        }
        try {
            $snapshot = $list();
        } catch (LinkAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'navigation'), $error->unauthenticated ? 401 : 403, $headers);
        }
        $selected = array_values(array_filter($snapshot['links'], static fn(ExternalLink $link): bool => in_array($link->id, $ids, true)));
        if (count($selected) !== count($ids)) {
            return new Response($translator->trans('Link not found.', [], 'navigation'), 404, $headers);
        }
        $form = $forms->create(LinkActionType::class, ['revision' => $snapshot['revision']], ['action' => $urls->generate('navigation_link_action', ['operation' => $operation, 'ids' => $ids])]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->getExtraData() !== []) {
            $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'navigation')));
        }
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $change($ids, $operation, (string) $form->getData()['revision']);
                return new RedirectResponse($urls->generate('navigation_links'), 303, $headers);
            } catch (\RuntimeException|\InvalidArgumentException $error) {
                $status = $failure($error, $form);
                if ($status instanceof Response) {
                    return $status;
                }
            }
        }
        return new Response($twig->render('navigation/link_action.html.twig', ['links' => $selected, 'operation' => $operation, 'form' => $form->createView()]), $status, $headers);
    }
}

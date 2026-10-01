<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Application\Query\ListLinks;
use Kadupul\Navigation\Application\Command\SaveLink;
use Kadupul\Navigation\Domain\LinkConflict;
use Kadupul\Navigation\Infrastructure\Symfony\Form\LinkType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class LinkEditController
{
    #[Route('/links/new', name: 'navigation_link_create', methods: ['GET', 'HEAD', 'POST'])]
    #[Route('/links/{id}/edit', name: 'navigation_link_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(
        Request $request,
        ConsoleAccess $console,
        LinkAccess $access,
        LinkStore $store,
        ListLinks $list,
        SaveLink $save,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        ?int $id = null
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
        $snapshot = $list();
        $link = null;
        $sections = [];
        foreach ($snapshot['links'] as $item) {
            if ($item->id === $id) {
                $link = $item;
            } if ($item->style === 'CONSOLE' && $item->extendedstyle !== '') {
                $sections[] = $item->extendedstyle;
            }
        }
        if ($id !== null && $link === null) {
            return new Response($translator->trans('Link not found.', [], 'navigation'), 404, $headers);
        }
        $files = $store->files();
        $data = $link?->fields($files) ?? ['title' => '', 'style' => 'TAB', 'filename' => '0', 'fileurl' => 'https://kadupul.org', 'consolesection' => 'External Links', 'consolenewsection' => '', 'enabled' => true, 'refresh' => 0];
        $form = $forms->create(LinkType::class, $data + ['revision' => $snapshot['revision']], ['files' => $files, 'sections' => array_unique($sections), 'action' => $urls->generate($id === null ? 'navigation_link_create' : 'navigation_link_edit', $id === null ? [] : ['id' => $id])]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->getExtraData() !== []) {
            $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'navigation')));
        }
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->isValid()) {
            $fields = $form->getData();
            $revision = (string) $fields['revision'];
            unset($fields['revision']);
            try {
                $save($id, $fields, $revision);
                return new RedirectResponse($urls->generate('navigation_links'), 303, $headers);
            } catch (LinkAccessDenied $error) {
                return new Response($translator->trans('Access denied.', [], 'navigation'), $error->unauthenticated ? 401 : 403, $headers);
            } catch (LinkConflict $error) {
                $status = 409;
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'navigation')));
            } catch (\InvalidArgumentException $error) {
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'navigation')));
            } catch (\RuntimeException) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Save could not be confirmed. Reload before retrying.', [], 'navigation')));
            }
        }
        return new Response($twig->render('navigation/link_edit.html.twig', ['link' => $link, 'form' => $form->createView()]), $status, $headers);
    }
}

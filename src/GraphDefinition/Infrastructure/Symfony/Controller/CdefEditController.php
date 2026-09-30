<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\CdefCatalog;
use Kadupul\GraphDefinition\Application\Port\CdefEditor;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\CdefAuthorization;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefEditType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CdefEditController
{
    #[Route('/graph-definitions/cdefs/new', name: 'graph_cdef_create', methods: ['GET', 'HEAD', 'POST'])]
    #[Route('/graph-definitions/cdefs/{id<\d+>}/edit', name: 'graph_cdef_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(?int $id, Request $request, CdefAuthorization $authorization, CdefCatalog $catalog, CdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $record = $id === null ? ['id' => 0, 'name' => '', 'items' => []] : $catalog->find($id);
            if ($record === null) {
                return new Response($translator->trans('CDEF not found.', [], 'graph_definition'), 404, $headers);
            }
            $form = $forms->create(CdefEditType::class, ['id' => $record['id'], 'name' => $record['name']], [
                'action' => $urls->generate($id === null ? 'graph_cdef_create' : 'graph_cdef_edit', $id === null ? [] : ['id' => $id]),
            ]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'graph_definition')));
                }
                if ($form->isValid()) {
                    $data = $form->getData();
                    if ((int) $data['id'] !== (int) $record['id'] || trim((string) $data['name']) === ''
                        || mb_strlen((string) $data['name']) > 255 || preg_match('/[\x00\r\n]/', (string) $data['name'])) {
                        $form->addError(new FormError($translator->trans('Enter a valid CDEF name.', [], 'graph_definition')));
                    } else {
                        $savedId = $editor->save($actor, (int) $data['id'], (string) $data['name']);
                        return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $savedId]), 303, $headers);
                    }
                }
            }
            return new Response($twig->render('graph_definition/cdef_edit.html.twig', [
                'form' => $form->createView(), 'cdef' => $record, 'preview' => $id === null ? '' : $catalog->preview($id),
            ]), $status, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF save failed. Check the CDEF list before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }
}

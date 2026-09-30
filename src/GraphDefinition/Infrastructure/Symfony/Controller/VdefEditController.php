<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Query\VdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\VdefAuthorization;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\VdefEditType;
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

final class VdefEditController
{
    #[Route('/graph-definitions/vdefs/new', name: 'graph_vdef_create', methods: ['GET', 'HEAD', 'POST'])]
    #[Route('/graph-definitions/vdefs/{id<\d+>}/edit', name: 'graph_vdef_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(?int $id, Request $request, VdefAuthorization $authorization, VdefCatalog $catalog, VdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $record = $id === null ? ['id' => 0, 'name' => '', 'revision' => '', 'items' => []] : $catalog->find($id);
            if ($record === null) {
                return new Response($translator->trans('VDEF not found.', [], 'graph_definition'), 404, $headers);
            }
            $form = $forms->create(VdefEditType::class, ['id' => $record['id'], 'name' => $record['name'], 'revision' => $record['revision']], ['action' => $urls->generate($id === null ? 'graph_vdef_create' : 'graph_vdef_edit', $id === null ? [] : ['id' => $id])]);
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
                        $form->addError(new FormError($translator->trans('Enter a VDEF name.', [], 'graph_definition')));
                    } else {
                        try {
                            $savedId = $editor->save($actor, (int) $data['id'], (string) $data['name'], (string) $data['revision']);
                            return new RedirectResponse($urls->generate('graph_vdef_edit', ['id' => $savedId]), 303, $headers);
                        } catch (\InvalidArgumentException $error) {
                            $form->addError(new FormError($translator->trans($error->getMessage(), [], 'graph_definition')));
                            $status = 409;
                        }
                    }
                }
            }
            return new Response($twig->render('graph_definition/vdef_edit.html.twig', [
                'form' => $form->createView(), 'vdef' => $record,
                'preview' => $id === null ? '' : $catalog->preview($id),
            ]), $status, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('VDEF save failed. Check the VDEF list before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefStore;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\Cdef;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefRevisionType;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefType;
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
    public function create(Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $actor = $access->authorize();
        } catch (CdefAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, null, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/graph-definitions/cdefs/{id}/edit', name: 'graph_cdef_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $actor = $access->authorize();
            $cdef = $store->find($id);
        } catch (CdefAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, ['Cache-Control' => 'private, no-store']);
        }
        if ($cdef === null) {
            return new Response($translator->trans('CDEF not found.', [], 'cdef'), 404, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, $cdef, $request, $store, $forms, $twig, $urls, $translator);
    }

    private function edit(int $actorId, ?Cdef $cdef, Request $request, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $saved = $request->query->all()['saved'] ?? null;
        $form = $forms->create(CdefType::class, ['name' => $cdef?->name ?? '', 'revision' => $cdef?->revision ?? ''], [
            'action' => $cdef === null ? $urls->generate('graph_cdef_create') : $urls->generate('graph_cdef_edit', ['id' => $cdef->id]),
            'csrf_token_id' => $cdef === null ? 'graph_cdef_create' : 'graph_cdef_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'cdef')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $id = $store->save($actorId, $cdef?->id, (string) $data['name'], $cdef === null ? null : (string) $data['revision']);
                    return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $id, 'saved' => 1]), 303, $headers);
                } catch (CdefAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'cdef')));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'cdef')));
                }
            }
        }
        $changes = [];
        foreach ($cdef?->items ?? [] as $item) {
            $changes[$item->id] = $forms->createNamed('cdef_item_change_' . $item->id, CdefRevisionType::class, ['revision' => $cdef->revision])->createView();
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graph_definition/cdef_edit.html.twig', [
            'cdef' => $cdef,
            'form' => $form->createView(),
            'changes' => $changes,
            'saved' => $saved === '1',
        ]), $status, $headers);
    }

    private function denied(CdefAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}

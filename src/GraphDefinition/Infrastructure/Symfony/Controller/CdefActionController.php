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
use Kadupul\GraphDefinition\Domain\CdefSummary;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefActionType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CdefActionController
{
    #[Route('/graph-definitions/cdefs/actions/{operation}', name: 'graph_cdef_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, CdefAuthorization $authorization, CdefCatalog $catalog, CdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $actor = $authorization->actor();
            $ids = self::ids($request->query->all()['ids'] ?? null);
            $rows = [];
            foreach ($ids as $id) {
                $record = $catalog->find($id);
                if ($record === null) {
                    return new Response($translator->trans('CDEF not found.', [], 'graph_definition'), 404, $headers);
                }
                $rows[] = new CdefSummary($id, $record['name'], $record['graphs'], $record['templates'], $record['referencing_cdefs']);
            }
            $form = $forms->create(CdefActionType::class, [
                'selection' => json_encode($ids, JSON_THROW_ON_ERROR), 'title_format' => '<cdef_title> (1)',
            ], ['action' => $urls->generate('graph_cdef_action', ['operation' => $operation, 'ids' => $ids])]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'graph_definition')));
                }
                if ($form->isValid()) {
                    try {
                        $data = $form->getData();
                        $selected = json_decode((string) $data['selection'], true, 8, JSON_THROW_ON_ERROR);
                        if (self::ids($selected) !== $ids) {
                            throw new \InvalidArgumentException('The selected CDEFs changed. Reload the confirmation.');
                        }
                        $editor->act($actor, $operation, $ids, (string) $data['title_format']);
                        return new RedirectResponse($urls->generate('graph_cdefs'), 303, $headers);
                    } catch (\InvalidArgumentException $error) {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'graph_definition')));
                        $status = 409;
                    }
                }
            }
            return new Response($twig->render('graph_definition/cdef_action.html.twig', [
                'form' => $form->createView(), 'rows' => $rows, 'operation' => $operation,
            ]), $status, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\JsonException) {
            return new Response($translator->trans('Invalid CDEF selection.', [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF action failed. Check the CDEF list before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 500 || array_keys($raw) !== range(0, count($raw) - 1)) {
            throw new \InvalidArgumentException('Select one or more CDEFs.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!(is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]{0,7}$/D', $id) === 1))) {
                throw new \InvalidArgumentException('Invalid CDEF selection.');
            }
            $id = (int) $id;
            if (in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Invalid CDEF selection.');
            }
            $ids[] = $id;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

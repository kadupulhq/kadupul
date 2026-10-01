<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Query\VdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\VdefAuthorization;
use Kadupul\GraphDefinition\Domain\VdefSummary;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\VdefActionType;
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

final class VdefActionController
{
    #[Route('/graph-definitions/vdefs/actions/{operation}', name: 'graph_vdef_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, VdefAuthorization $authorization, VdefCatalog $catalog, VdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $ids = self::ids($request->query->all()['ids'] ?? null);
            $rows = [];
            $revisions = [];
            foreach ($ids as $id) {
                $record = $catalog->find($id);
                if ($record === null) {
                    return new Response($translator->trans('VDEF not found.', [], 'graph_definition'), 404, $headers);
                }
                $rows[] = new VdefSummary($id, $record['name'], 0, 0, 0);
                $revisions[(string) $id] = $record['revision'];
            }
            $form = $forms->create(VdefActionType::class, [
                'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
                'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR),
                'title_format' => '<vdef_title> (1)',
            ], ['action' => $urls->generate('graph_vdef_action', ['operation' => $operation, 'ids' => $ids])]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'graph_definition')));
                }
                if ($form->isValid()) {
                    try {
                        $data = $form->getData();
                        $submittedIds = json_decode((string) $data['selection'], true, 8, JSON_THROW_ON_ERROR);
                        $submittedRevisions = json_decode((string) $data['revisions'], true, 8, JSON_THROW_ON_ERROR);
                        if (self::ids($submittedIds) !== $ids || $submittedRevisions !== $revisions) {
                            throw new \InvalidArgumentException('The selected VDEFs changed. Reload the confirmation.');
                        }
                        $editor->act($actor, $operation, $ids, (string) $data['title_format'], $revisions);
                        return new RedirectResponse($urls->generate('graph_vdefs'), 303, $headers);
                    } catch (\InvalidArgumentException $error) {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'graph_definition')));
                        $status = 409;
                    }
                }
            }
            return new Response($twig->render('graph_definition/vdef_action.html.twig', [
                'form' => $form->createView(), 'rows' => $rows, 'operation' => $operation,
            ]), $status, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\JsonException) {
            return new Response($translator->trans('Invalid VDEF selection.', [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('VDEF action failed. Check the VDEF list before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 500 || array_keys($raw) !== range(0, count($raw) - 1)) {
            throw new \InvalidArgumentException('Select one or more VDEFs.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!(is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]{0,7}$/D', $id) === 1))) {
                throw new \InvalidArgumentException('Invalid VDEF selection.');
            }
            $id = (int) $id;
            if ($id < 1 || in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Invalid VDEF selection.');
            }
            $ids[] = $id;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

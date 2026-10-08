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
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefActionType;
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

final class CdefActionController
{
    #[Route('/graph-definitions/cdefs/actions/{operation}', name: 'graph_cdef_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $ids = self::ids($request->query->all()['ids'] ?? null);
            $selected = $store->findMany($ids);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid CDEF selection.', [], 'cdef'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, $headers);
        }
        if (count($selected) !== count($ids)) {
            return new Response($translator->trans('One or more selected CDEFs no longer exist.', [], 'cdef'), 404, $headers);
        }
        $duplicate = $operation === 'duplicate';
        $revisions = array_map(static fn(array $entry): string => $entry['revision'], $selected);
        $used = $duplicate ? [] : array_values(array_filter(array_column($selected, 'summary'), static fn($summary): bool => !$summary->isDeletable()));
        $form = $forms->create(CdefActionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'title_format' => '<cdef_title> (1)',
        ], [
            'duplicate' => $duplicate,
            'action' => $urls->generate('graph_cdef_action', ['operation' => $operation, 'ids' => $ids]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'cdef')));
            }
            if ($used !== []) {
                $form->addError(new FormError($translator->trans('CDEFs in use by a graph, template, aggregate or another CDEF cannot be deleted.', [], 'cdef')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $submitted = json_decode((string) $data['selection'], false, 8, JSON_THROW_ON_ERROR);
                    $submittedRevisions = json_decode((string) $data['revisions'], true, 8, JSON_THROW_ON_ERROR);
                    if ($submitted !== $ids || !is_array($submittedRevisions)) {
                        throw new \InvalidArgumentException('Invalid CDEF selection.');
                    }
                    $expected = [];
                    foreach ($submittedRevisions as $id => $revision) {
                        $expected[(int) $id] = $revision;
                    }
                    if ($duplicate) {
                        $store->duplicate($actor->id, $ids, $expected, (string) $data['title_format']);
                    } else {
                        $store->delete($actor->id, $ids, $expected);
                    }
                    return new RedirectResponse($urls->generate('graph_cdefs', [$duplicate ? 'duplicated' : 'deleted' => 1]), 303, $headers);
                } catch (CdefAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException | \JsonException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                    $message = $error instanceof \JsonException ? 'Invalid CDEF selection.' : $error->getMessage();
                    $form->addError(new FormError($translator->trans($message, [], 'cdef')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans(
                        $duplicate ? 'Duplicate outcome is uncertain. Check the CDEF list before retrying.' : 'Delete outcome is uncertain. Check the CDEF list before retrying.',
                        [],
                        'cdef',
                    )));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graph_definition/cdef_action.html.twig', [
            'operation' => $operation,
            'cdefs' => array_column($selected, 'summary'),
            'used' => $used,
            'form' => $form->createView(),
        ]), $status, $headers);
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > CdefStore::MAX_SELECTION || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Invalid CDEF selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,7}\z/D', $id) !== 1) {
                throw new \InvalidArgumentException('Invalid CDEF selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

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
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefItemType;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefRevisionType;
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

final class CdefItemController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/graph-definitions/cdefs/{cdefId}/items/{itemId}', name: 'graph_cdef_item_edit', requirements: ['cdefId' => '[1-9][0-9]{0,7}', 'itemId' => '0|[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(int $cdefId, int $itemId, Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $cdef = $store->find($cdefId);
            $item = $cdef?->item($itemId);
            if ($cdef === null || ($itemId > 0 && $item === null)) {
                return new Response($translator->trans('CDEF item not found.', [], 'cdef'), 404, self::HEADERS);
            }
            $type = self::type($request->query->all()['type'] ?? null, $item?->type ?? CdefFunctions::FUNCTION);
            $choices = match ($type) {
                CdefFunctions::FUNCTION => $store->functions(),
                CdefFunctions::OPERATOR => CdefFunctions::OPERATORS,
                CdefFunctions::SPECIAL_DATA_SOURCE => CdefFunctions::DATA_SOURCES,
                CdefFunctions::CDEF => $store->references($cdefId),
                default => [],
            };
            $value = $item !== null && $item->type === $type ? $item->value : null;
            $form = $forms->create(CdefItemType::class, ['value' => $value, 'revision' => $cdef->revision], [
                'item_type' => $type,
                'value_choices' => $choices,
                'action' => $urls->generate('graph_cdef_item_edit', ['cdefId' => $cdefId, 'itemId' => $itemId, 'type' => $type]),
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
                        $store->saveItem($actor->id, $cdefId, $itemId === 0 ? null : $itemId, $type, (string) $data['value'], (string) $data['revision']);
                        return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, self::HEADERS);
                    } catch (\InvalidArgumentException $error) {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'cdef')));
                        $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                    } catch (CdefAccessDenied $error) {
                        throw $error;
                    } catch (\Throwable) {
                        $status = 502;
                        $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'cdef')));
                    }
                }
            }
            return new Response($request->isMethod('HEAD') ? '' : $twig->render('graph_definition/cdef_item_edit.html.twig', [
                'cdef' => $cdef,
                'item' => $item,
                'type' => $type,
                'types' => CdefFunctions::TYPES,
                'form' => $form->createView(),
            ]), $status, self::HEADERS);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Choose a valid CDEF item type.', [], 'cdef'), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, self::HEADERS);
        }
    }

    #[Route('/graph-definitions/cdefs/{cdefId}/items/{itemId}/delete', name: 'graph_cdef_item_delete', requirements: ['cdefId' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $cdefId, int $itemId, Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $cdef = $store->find($cdefId);
            $item = $cdef?->item($itemId);
            if ($cdef === null || $item === null) {
                return new Response($translator->trans('CDEF item not found.', [], 'cdef'), 404, self::HEADERS);
            }
            $form = $forms->create(CdefRevisionType::class, ['revision' => $cdef->revision], [
                'action' => $urls->generate('graph_cdef_item_delete', ['cdefId' => $cdefId, 'itemId' => $itemId]),
            ]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $store->deleteItem($actor->id, $cdefId, $itemId, (string) $form->getData()['revision']);
                    return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, self::HEADERS);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'cdef')));
                    $status = 409;
                } catch (CdefAccessDenied $error) {
                    throw $error;
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Reload before retrying.', [], 'cdef')));
                }
            }
            return new Response($request->isMethod('HEAD') ? '' : $twig->render('graph_definition/cdef_item_delete.html.twig', [
                'cdef' => $cdef,
                'item' => $item,
                'types' => CdefFunctions::TYPES,
                'form' => $form->createView(),
            ]), $status, self::HEADERS);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, self::HEADERS);
        }
    }

    #[Route('/graph-definitions/cdefs/{cdefId}/items/{itemId}/move/{direction}', name: 'graph_cdef_item_move', requirements: ['cdefId' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,7}', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(int $cdefId, int $itemId, string $direction, Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $form = $forms->createNamed('cdef_item_change_' . $itemId, CdefRevisionType::class);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return new Response($translator->trans('Invalid CDEF item order.', [], 'cdef'), 422, self::HEADERS);
            }
            $store->moveItem($actor->id, $cdefId, $itemId, $direction === 'up' ? -1 : 1, (string) $form->getData()['revision']);
            return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, self::HEADERS);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'cdef'), 409, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Move outcome is uncertain. Reload before retrying.', [], 'cdef'), 502, self::HEADERS);
        }
    }

    private static function type(mixed $raw, int $default): int
    {
        if ($raw === null) {
            return $default;
        }
        if (!is_string($raw) || preg_match('/\A[1-9]\z/D', $raw) !== 1 || !isset(CdefFunctions::TYPES[(int) $raw])) {
            throw new \InvalidArgumentException('Choose a valid CDEF item type.');
        }
        return (int) $raw;
    }
}

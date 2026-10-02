<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Command\DeletePaletteColors;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Query\FindPaletteColors;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\PaletteColorDeletionType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaletteColorDeleteController
{
    #[Route('/graphing/colors/actions/delete', name: 'palette_color_delete', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, FindPaletteColors $find, DeletePaletteColors $delete, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $ids = $this->ids($query['ids'] ?? null);
            unset($query['ids']);
            $filters = $query === [] ? [] : PaletteColorFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
            $presets = $find($ids);
        } catch (PaletteColorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color selection.', [], 'palette'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare Color deletion.', [], 'palette'), 502, $headers);
        }
        if (count($presets) !== count($ids)) {
            return new Response($translator->trans('One or more selected Colors no longer exist.', [], 'palette'), 404, $headers);
        }
        $used = array_values(array_filter($presets, static fn(PaletteColor $preset): bool => !$preset->isDeletable()));
        $form = $forms->create(PaletteColorDeletionType::class, ['selection' => json_encode($ids, JSON_THROW_ON_ERROR), 'revisions' => json_encode(array_column(array_map(static fn(PaletteColor $color): array => ['id' => $color->id, 'revision' => $color->revision], $presets), 'revision', 'id'), JSON_THROW_ON_ERROR)], [
            'action' => $urls->generate('palette_color_delete', ['ids' => $ids] + $filters),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'palette')));
            }
            $selected = json_decode((string) $form->get('selection')->getData(), true);
            if (!is_array($selected) || $selected !== $ids) {
                $form->addError(new FormError($translator->trans('The selected Colors changed. Reload before continuing.', [], 'palette')));
            }
            if ($used !== []) {
                $form->addError(new FormError($translator->trans('Colors in use by a graph or graph template cannot be deleted.', [], 'palette')));
            }
            if ($form->isValid()) {
                try {
                    $revisions = json_decode((string) $form->get('revisions')->getData(), true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($revisions) || count($revisions) !== count($ids)) {
                        throw new \InvalidArgumentException('Invalid color selection.');
                    }
                    $delete($ids, $revisions);
                    return new RedirectResponse($urls->generate('palette_color_list', ['deleted' => 1] + $filters), 303, $headers);
                } catch (PaletteColorAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException | \JsonException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'palette')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Check the Color list before retrying.', [], 'palette')));
                }
            }
        }
        return new Response($twig->render('graphing/palette_color_delete.html.twig', [
            'presets' => $presets,
            'used' => $used,
            'form' => $form->createView(),
            'filters' => $filters,
        ]), $status, $headers);
    }

    private function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > PaletteColorStore::MAX_DELETE_SELECTION) {
            throw new \InvalidArgumentException('Invalid color selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)) {
                throw new \InvalidArgumentException('Invalid color selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

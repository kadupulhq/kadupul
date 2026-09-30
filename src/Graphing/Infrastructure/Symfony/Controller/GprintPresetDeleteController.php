<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Command\DeleteGprintPresets;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Query\FindGprintPresets;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\GprintPresetDeletionType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class GprintPresetDeleteController
{
    #[Route('/graphing/gprint-presets/actions/delete', name: 'gprint_preset_delete', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, GprintPresetAccess $access, GprintPresetStore $store, FindGprintPresets $find, DeleteGprintPresets $delete, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
            $query = $request->query->all();
            $ids = $this->ids($query['ids'] ?? null);
            unset($query['ids']);
            $filters = $query === [] ? [] : GprintPresetFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
            $presets = $find($ids);
        } catch (GprintPresetAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'gprint'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid GPRINT preset selection.', [], 'gprint'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare GPRINT Preset deletion.', [], 'gprint'), 502, $headers);
        }
        if (count($presets) !== count($ids)) {
            return new Response($translator->trans('One or more selected GPRINT Presets no longer exist.', [], 'gprint'), 404, $headers);
        }
        $used = array_values(array_filter($presets, static fn(GprintPreset $preset): bool => !$preset->isDeletable()));
        $form = $forms->create(GprintPresetDeletionType::class, ['selection' => json_encode($ids, JSON_THROW_ON_ERROR)], [
            'action' => $urls->generate('gprint_preset_delete', ['ids' => $ids] + $filters),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'gprint')));
            }
            $selected = json_decode((string) $form->get('selection')->getData(), true);
            if (!is_array($selected) || $selected !== $ids) {
                $form->addError(new FormError($translator->trans('The selected GPRINT Presets changed. Reload before continuing.', [], 'gprint')));
            }
            if ($used !== []) {
                $form->addError(new FormError($translator->trans('GPRINT Presets in use by a graph or graph template cannot be deleted.', [], 'gprint')));
            }
            if ($form->isValid()) {
                try {
                    $delete($ids);
                    return new RedirectResponse($urls->generate('gprint_preset_list', ['deleted' => 1] + $filters), 303, $headers);
                } catch (GprintPresetAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'gprint'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'gprint')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Check the GPRINT Preset list before retrying.', [], 'gprint')));
                }
            }
        }
        return new Response($twig->render('graphing/gprint_preset_delete.html.twig', [
            'presets' => $presets,
            'used' => $used,
            'form' => $form->createView(),
            'listUrl' => $urls->generate('gprint_preset_list', $filters),
        ]), $status, $headers);
    }

    private function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 100) {
            throw new \InvalidArgumentException('Invalid GPRINT preset selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)) {
                throw new \InvalidArgumentException('Invalid GPRINT preset selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

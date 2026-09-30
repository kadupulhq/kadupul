<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Command\SaveGprintPreset;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Query\FindGprintPreset;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\GprintPresetType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class GprintPresetEditController
{
    #[Route('/graphing/gprint-presets/new', name: 'gprint_preset_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, GprintPresetAccess $access, GprintPresetStore $store, SaveGprintPreset $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        try {
            $access->authorize();
        } catch (GprintPresetAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to authorize GPRINT Preset access.', [], 'gprint'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit(null, null, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/gprint-presets/{id}/edit', name: 'gprint_preset_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, FindGprintPreset $find, GprintPresetStore $store, SaveGprintPreset $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        try {
            $preset = $find($id);
        } catch (GprintPresetAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load GPRINT Preset. Reload before retrying.', [], 'gprint'), 502, ['Cache-Control' => 'private, no-store']);
        }
        if ($preset === null) {
            return new Response($translator->trans('GPRINT Preset not found.', [], 'gprint'), 404, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($id, $preset, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    private function edit(?int $id, ?GprintPreset $preset, Request $request, GprintPresetStore $store, SaveGprintPreset $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $filters = $this->filterContext($request, $store);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid GPRINT preset filters.', [], 'gprint'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare GPRINT Preset form.', [], 'gprint'), 502, $headers);
        }
        $route = $id === null ? 'gprint_preset_create' : 'gprint_preset_edit';
        $form = $forms->create(GprintPresetType::class, [
            'name' => $preset?->name ?? '',
            'gprint_text' => $preset?->gprintText ?? '',
            'revision' => $preset?->revision ?? '',
        ], [
            'action' => $urls->generate($route, ($id === null ? [] : ['id' => $id]) + $filters),
            'csrf_token_id' => $id === null ? 'gprint_preset_create' : 'gprint_preset_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'gprint')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                $this->validate($form, $data, $translator);
                if ($form->isValid()) {
                    try {
                        $saved = $save($id, (string) $data['name'], (string) $data['gprint_text'], is_string($data['revision']) ? $data['revision'] : null);
                        return new RedirectResponse($urls->generate('gprint_preset_edit', ['id' => $saved] + $filters + ['saved' => 1]), 303, $headers);
                    } catch (GprintPresetAccessDenied $error) {
                        return $this->denied($error, $translator);
                    } catch (\InvalidArgumentException $error) {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'gprint')));
                        if (str_contains($error->getMessage(), 'changed since')) {
                            $status = 409;
                        }
                    } catch (\Throwable) {
                        $status = 502;
                        $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'gprint')));
                    }
                }
            }
        }
        return new Response($twig->render('graphing/gprint_preset_edit.html.twig', [
            'preset' => $preset,
            'form' => $form->createView(),
            'saved' => $request->query->get('saved') === '1',
            'filters' => $filters,
        ]), $status, $headers);
    }

    private function filterContext(Request $request, GprintPresetStore $store): array
    {
        $query = $request->query->all();
        unset($query['saved']);
        if ($query === []) {
            return [];
        }
        return GprintPresetFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
    }

    private function validate(\Symfony\Component\Form\FormInterface $form, array $data, TranslatorInterface $translator): void
    {
        foreach (['name' => 'GPRINT Preset Name', 'gprint_text' => 'GPRINT Text'] as $field => $label) {
            $value = $data[$field] ?? null;
            if (!is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > 50 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                $form->get($field)->addError(new FormError($translator->trans($label . ' must contain 1 to 50 valid characters.', [], 'gprint')));
            }
        }
    }

    private function denied(GprintPresetAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'gprint'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}

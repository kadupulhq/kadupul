<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Application\Port\ColorTemplateSynchronization;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplate;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateActionType;
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

final class ColorTemplateActionController
{
    private const array ACTIONS = ['delete', 'duplicate', 'sync'];

    #[Route('/graphing/color-templates/actions', name: 'color_template_action', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, ColorTemplateSynchronization $synchronization, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $query = $request->query->all();
            $action = $query['action'] ?? null;
            if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
                return new Response($translator->trans('Select a valid Color Template action.', [], 'color_template'), 400, $headers);
            }
            $ids = $this->ids($query['ids'] ?? null);
            unset($query['action'], $query['ids']);
            $filters = $query === [] ? [] : ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
            $templates = $store->findMany($ids);
            $revisions = $store->selectionRevisions($ids);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template selection.', [], 'color_template'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare the Color Template action.', [], 'color_template'), 502, $headers);
        }
        if (count($templates) !== count($ids) || count($revisions) !== count($ids)) {
            return new Response($translator->trans('One or more selected Color Templates no longer exist.', [], 'color_template'), 404, $headers);
        }
        $used = array_values(array_filter($templates, static fn(ColorTemplate $template): bool => !$template->isDeletable()));
        $form = $forms->create(ColorTemplateActionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR),
            'title_format' => '<template_title> (1)',
        ], [
            'action' => $urls->generate('color_template_action', ['action' => $action, 'ids' => $ids] + $filters),
            'title_format' => $action === 'duplicate',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        $synchronized = [];
        $failed = false;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'color_template')));
            }
            $selected = json_decode((string) $form->get('selection')->getData(), false, 4);
            if (!is_array($selected) || $selected !== $ids) {
                $form->addError(new FormError($translator->trans('The selected Color Templates changed. Reload before continuing.', [], 'color_template')));
            }
            if ($action === 'delete' && $used !== []) {
                $form->addError(new FormError($translator->trans('Color Templates used by an aggregate graph or aggregate template cannot be deleted.', [], 'color_template')));
            }
            if ($form->isValid()) {
                try {
                    $submitted = json_decode((string) $form->get('revisions')->getData(), true, 4, JSON_THROW_ON_ERROR);
                    if (!is_array($submitted) || count($submitted) !== count($ids)) {
                        throw new \InvalidArgumentException('Invalid color template selection.');
                    }
                    if ($action === 'delete') {
                        $store->delete($actor->id, $ids, $submitted);
                        return new RedirectResponse($urls->generate('color_template_list', ['deleted' => 1] + $filters), 303, $headers);
                    }
                    if ($action === 'duplicate') {
                        $store->duplicate($actor->id, $ids, (string) $form->get('title_format')->getData(), $submitted);
                        return new RedirectResponse($urls->generate('color_template_list', ['duplicated' => 1] + $filters), 303, $headers);
                    }
                    // Each template commits on its own and the run stops at the
                    // first failure, as the legacy action did.
                    foreach ($ids as $id) {
                        $failed = true;
                        if (!is_string($submitted[$id] ?? null)) {
                            throw new \InvalidArgumentException('Invalid color template selection.');
                        }
                        $synchronized[] = $synchronization->synchronize($actor->id, $id, $submitted[$id]);
                        $failed = false;
                    }
                    $status = 200;
                } catch (ColorTemplateAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException | \JsonException $error) {
                    $status = $error->getMessage() === 'The selected Color Templates changed. Reload before continuing.' ? 409 : 422;
                    $form->addError(new FormError($translator->trans($error instanceof \JsonException ? 'Invalid color template selection.' : $error->getMessage(), [], 'color_template')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans($action === 'sync'
                        ? 'Color Template synchronization failed. Some aggregates may already have been updated; retry after reviewing the settings.'
                        : 'Action outcome is uncertain. Check the Color Template list before retrying.', [], 'color_template')));
                }
            }
        }
        return new Response($twig->render('graphing/color_template_action.html.twig', [
            'action' => $action,
            'templates' => $templates,
            'used' => $used,
            'form' => $form->createView(),
            'filters' => $filters,
            'synchronized' => $synchronized,
            'completed' => $action === 'sync' && $status === 200 && $request->isMethod('POST'),
            'failed' => $failed,
        ]), $status, $headers);
    }

    /** @return list<int> */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > ColorTemplateStore::MAX_SELECTION) {
            throw new \InvalidArgumentException('Invalid color template selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $id)) {
                throw new \InvalidArgumentException('Invalid color template selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function denied(ColorTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}

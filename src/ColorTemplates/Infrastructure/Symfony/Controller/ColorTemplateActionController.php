<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Controller;

use Kadupul\ColorTemplates\Application\Command\DeleteColorTemplates;
use Kadupul\ColorTemplates\Application\Command\DuplicateColorTemplates;
use Kadupul\ColorTemplates\Application\Command\SyncColorTemplate;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateActionType;
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

    #[Route('/graphing/color-templates/actions', name: 'color_template_actions', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, DeleteColorTemplates $delete, DuplicateColorTemplates $duplicate, SyncColorTemplate $sync, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $action = $query['action'] ?? null;
            $rawIds = $query['ids'] ?? null;
            unset($query['action'], $query['ids']);
            if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException('Select a valid color template action.');
            }
            $ids = $this->ids($rawIds);
            $filters = $query === [] ? [] : ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
            $templates = [];
            foreach ($ids as $id) {
                $template = $store->find($id);
                if ($template === null) {
                    throw new \InvalidArgumentException('One or more color templates no longer exist.');
                }
                $templates[] = $template;
            }
            $form = $forms->create(ColorTemplateActionType::class, ['selection' => json_encode($ids, JSON_THROW_ON_ERROR), 'revisions' => json_encode($store->actionRevisions($templates), JSON_THROW_ON_ERROR), 'title_format' => '<template_title> (1)'], [
                'action' => $urls->generate('color_template_actions', ['action' => $action, 'ids' => $ids] + $filters),
            ]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'color_templates')));
                }
                $selected = json_decode((string) $form->get('selection')->getData(), true);
                if (!is_array($selected) || $selected !== $ids) {
                    $form->addError(new FormError($translator->trans('The selected color templates changed. Reload before continuing.', [], 'color_templates')));
                }
                $revisions = json_decode((string) $form->get('revisions')->getData(), true);
                if (!is_array($revisions)) {
                    $form->addError(new FormError($translator->trans('The selected color templates changed. Reload before continuing.', [], 'color_templates')));
                }
                if ($action === 'delete' && array_filter($templates, static fn($template): bool => !$template->deletable()) !== []) {
                    $form->addError(new FormError($translator->trans('Color templates referenced by aggregate graphs or templates cannot be deleted.', [], 'color_templates')));
                }
                if ($form->isValid()) {
                    $data = $form->getData();
                    try {
                        if ($action === 'delete') {
                            $delete($ids, $revisions);
                            return new RedirectResponse($urls->generate('color_template_list', ['deleted' => 1] + $filters), 303, $headers);
                        }
                        if ($action === 'duplicate') {
                            $duplicate($ids, (string) ($data['title_format'] ?? ''), $revisions);
                            return new RedirectResponse($urls->generate('color_template_list', ['duplicated' => 1] + $filters), 303, $headers);
                        }
                        $summaries = [];
                        foreach ($ids as $id) {
                            $summaries[] = $sync($id);
                        }
                        return new RedirectResponse($urls->generate('color_template_list', ['synced' => 1] + $filters), 303, $headers);
                    } catch (ColorTemplateAccessDenied $error) {
                        return $this->denied($error, $translator);
                    } catch (\InvalidArgumentException $error) {
                        if ($error->getMessage() === 'The selected color templates changed. Reload before continuing.') {
                            $status = 409;
                        }
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'color_templates')));
                    } catch (\Throwable) {
                        $status = 502;
                        $form->addError(new FormError($translator->trans('Action outcome is uncertain. Reload the color template list before retrying.', [], 'color_templates')));
                    }
                }
            }
            return new Response($twig->render('color_templates/action.html.twig', [
                'actionName' => $action, 'templates' => $templates, 'form' => $form->createView(), 'filters' => $filters,
                'status' => $status,
            ]), $status, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'color_templates'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare color template action.', [], 'color_templates'), 502, $headers);
        }
    }

    private function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > 100) {
            throw new \InvalidArgumentException('Select between 1 and 100 color templates.');
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
        return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Port\GraphTemplateInputCatalog;
use Kadupul\Graphing\Application\Port\GraphTemplateInputEditor;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputColumn;
use Kadupul\Graphing\Domain\GraphTemplateInputItem;
use Kadupul\Graphing\Infrastructure\Symfony\Form\GraphTemplateInputDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\GraphTemplateInputType;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The graph item input editor that graph_templates_inputs.php rendered and
 * saved. Each route authenticates and authorizes realm 10 before it reads a
 * graph template, in the form the entry-point classifier certifies.
 */
final class GraphTemplateInputController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'graph_template_input';

    #[Route('/graphing/graph-templates/{templateId}/inputs/new', name: 'graph_template_input_create', requirements: ['templateId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function create(int $templateId, Request $request, ConsoleAccess $console, GraphTemplateInputAccess $access, GraphTemplateInputCatalog $catalog, GraphTemplateInputEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_template_input'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (GraphTemplateInputAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return $this->unavailable($translator);
        }
        $loaded = $this->load($templateId, null, $catalog, $translator);
        return $loaded instanceof Response ? $loaded : $this->edit($actor, $templateId, $loaded, $request, $editor, $forms, $twig, $urls, $translator, $configuration);
    }

    #[Route('/graphing/graph-templates/{templateId}/inputs/{inputId}/edit', name: 'graph_template_input_edit', requirements: ['templateId' => '[1-9][0-9]{0,7}', 'inputId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $templateId, int $inputId, Request $request, ConsoleAccess $console, GraphTemplateInputAccess $access, GraphTemplateInputCatalog $catalog, GraphTemplateInputEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_template_input'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (GraphTemplateInputAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return $this->unavailable($translator);
        }
        $loaded = $this->load($templateId, $inputId, $catalog, $translator);
        return $loaded instanceof Response ? $loaded : $this->edit($actor, $templateId, $loaded, $request, $editor, $forms, $twig, $urls, $translator, $configuration);
    }

    #[Route('/graphing/graph-templates/{templateId}/inputs/{inputId}/delete', name: 'graph_template_input_delete', requirements: ['templateId' => '[1-9][0-9]{0,7}', 'inputId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $templateId, int $inputId, Request $request, ConsoleAccess $console, GraphTemplateInputAccess $access, GraphTemplateInputCatalog $catalog, GraphTemplateInputEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_template_input'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (GraphTemplateInputAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return $this->unavailable($translator);
        }
        $loaded = $this->load($templateId, $inputId, $catalog, $translator);
        if ($loaded instanceof Response) {
            return $loaded;
        }
        [$templateName, $input] = $loaded;
        \assert($input instanceof GraphTemplateInput);
        $form = $forms->create(GraphTemplateInputDeletionType::class, ['revision' => $input->revision], [
            'action' => $urls->generate('graph_template_input_delete', ['templateId' => $templateId, 'inputId' => $inputId]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $editor->remove($actor->id, $templateId, $inputId, self::text($form->get('revision')->getData()));
                return new RedirectResponse(self::templateUrl($templateId, $configuration), 303, self::HEADERS);
            } catch (GraphTemplateInputAccessDenied $error) {
                return $this->denied($error, $translator);
            } catch (\InvalidArgumentException $error) {
                $status = 409;
                $form->addError(new FormError($translator->trans($error->getMessage(), [], self::DOMAIN)));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Reload before retrying.', [], self::DOMAIN)));
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/graph_template_input_delete.html.twig', [
            'template_id' => $templateId,
            'template_name' => $templateName,
            'input' => $input,
            'template_url' => self::templateUrl($templateId, $configuration),
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    /** @param array{string, ?GraphTemplateInput, list<GraphTemplateInputItem>} $loaded */
    private function edit(Actor $actor, int $templateId, array $loaded, Request $request, GraphTemplateInputEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        [$templateName, $input, $items] = $loaded;
        $choices = [];
        foreach ($items as $item) {
            $choices[(string) $item->id] = $item->id;
        }
        $form = $forms->create(GraphTemplateInputType::class, [
            'name' => $input->name ?? '',
            'description' => $input->description ?? '',
            // A stored column outside the allowlist shows the first choice, as the legacy drop-down did.
            'column_name' => GraphTemplateInputColumn::tryFrom($input->column ?? '')->value ?? GraphTemplateInputColumn::GraphType->value,
            'items' => array_values(array_map(static fn(GraphTemplateInputItem $item): int => $item->id, array_filter($items, static fn(GraphTemplateInputItem $item): bool => $item->selected))),
            'revision' => $input->revision ?? '',
        ], [
            'action' => $input === null
                ? $urls->generate('graph_template_input_create', ['templateId' => $templateId])
                : $urls->generate('graph_template_input_edit', ['templateId' => $templateId, 'inputId' => $input->id]),
            'item_choices' => $choices,
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->getExtraData() !== []) {
            // The legacy field names (i_<id>, save_component_input, graph_template_id) are not accepted.
            $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], self::DOMAIN)));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $selected = [];
            // The choice field admits only this template's item IDs; the editor rechecks them.
            foreach ((array) $form->get('items')->getData() as $itemId) {
                if (is_int($itemId)) {
                    $selected[] = $itemId;
                }
            }
            try {
                $editor->save(
                    $actor->id,
                    $templateId,
                    $input?->id,
                    self::text($form->get('name')->getData()),
                    self::text($form->get('description')->getData()),
                    self::text($form->get('column_name')->getData()),
                    $selected,
                    $input === null ? null : self::text($form->get('revision')->getData()),
                );
                return new RedirectResponse(self::templateUrl($templateId, $configuration), 303, self::HEADERS);
            } catch (GraphTemplateInputAccessDenied $error) {
                return $this->denied($error, $translator);
            } catch (\InvalidArgumentException $error) {
                $message = $error->getMessage();
                $status = in_array($message, [GraphTemplateInputEditor::STALE, GraphTemplateInputEditor::NOT_FOUND, GraphTemplateInputEditor::TEMPLATE_NOT_FOUND, GraphTemplateInputEditor::RELATIONSHIP], true) ? 409 : 422;
                $form->addError(new FormError($translator->trans($message, [], self::DOMAIN)));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], self::DOMAIN)));
            }
        }
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/graph_template_input_edit.html.twig', [
            'template_id' => $templateId,
            'template_name' => $templateName,
            'input' => $input,
            'items' => $byId,
            'template_url' => self::templateUrl($templateId, $configuration),
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    /** @return Response|array{string, ?GraphTemplateInput, list<GraphTemplateInputItem>} */
    private function load(int $templateId, ?int $inputId, GraphTemplateInputCatalog $catalog, TranslatorInterface $translator): Response|array
    {
        if ($templateId > GraphTemplateInput::MAX_ID || ($inputId ?? 1) > GraphTemplateInput::MAX_ID) {
            return new Response($translator->trans('Graph Item Input not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        try {
            $templateName = $catalog->templateName($templateId);
            $input = $templateName === null || $inputId === null ? null : $catalog->find($templateId, $inputId);
            $items = $templateName === null || ($inputId !== null && $input === null) ? [] : $catalog->items($templateId, $inputId);
        } catch (\Throwable) {
            return $this->unavailable($translator);
        }
        if ($templateName === null) {
            return new Response($translator->trans('Graph Template not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        if ($inputId !== null && $input === null) {
            return new Response($translator->trans('Graph Item Input not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        return [$templateName, $input, $items];
    }

    /**
     * The legacy graph template editor below url_path, with the same
     * acceptance as InstallationContext: one absolute path and nothing else.
     */
    private static function templateUrl(int $templateId, LegacyConfiguration $configuration): string
    {
        $path = $configuration->values()['url_path'] ?? '/';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20?#]/', $path) === 1) {
            $path = '/';
        }
        return rtrim($path, '/') . '/graph_templates.php?action=template_edit&id=' . $templateId;
    }

    /** A submitted form field; an empty description or name arrives as null. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function denied(GraphTemplateInputAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
    }

    /** A failed read, including an offline collector's unreachable main database. */
    private function unavailable(TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Graph Item Inputs are unavailable. Reload before retrying.', [], self::DOMAIN), 503, self::HEADERS + ['Retry-After' => '30']);
    }
}

<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Controller;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateEditor;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateCatalog;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\AggregateTemplate\Application\Query\AggregateTemplateAccessDenied;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\AggregateTemplateConflict;
use Kadupul\AggregateTemplate\Infrastructure\Symfony\Form\AggregateTemplateDeleteType;
use Kadupul\IdentityAccess\Contract\CurrentActor;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AggregateTemplateDeleteController
{
    #[Route('/aggregate-templates/actions/delete', name: 'aggregate_template_delete', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, CurrentActor $currentActor, AggregateTemplatePermissions $access, AggregateTemplateCatalog $catalog, AggregateTemplateEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $currentActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 401, $headers);
        }
        if (!$access->canManage($actor)) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 403, $headers);
        }
        $ids = $request->isMethod('POST') ? [] : $request->query->all('ids');
        if ($request->isMethod('POST')) {
            $posted = $request->request->all('aggregate_template_delete');
            $encoded = $posted['revisions'] ?? '';
            try {
                $revisions = is_string($encoded) ? json_decode($encoded, true, 8, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException) {
                $revisions = null;
            }
            if (!is_array($revisions) || $revisions === [] || count($revisions) > 1000) {
                return new Response($translator->trans('Invalid aggregate template selection.', [], 'aggregate_template'), 400, $headers);
            }
            foreach ($revisions as $id => $revision) {
                if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id < 1 || !is_string($revision)) {
                    return new Response($translator->trans('Invalid aggregate template selection.', [], 'aggregate_template'), 400, $headers);
                }
            }
            $ids = array_keys($revisions);
        } else {
            $revisions = [];
            if ($ids === [] || count($ids) > 1000) {
                return new Response($translator->trans('Select at least one aggregate template.', [], 'aggregate_template'), 400, $headers);
            }
            foreach ($ids as $id) {
                if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id < 1) {
                    return new Response($translator->trans('Invalid aggregate template selection.', [], 'aggregate_template'), 400, $headers);
                }
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $record = $catalog->editData($id);
                if ($record === null) {
                    return new Response($translator->trans('Aggregate template not found.', [], 'aggregate_template'), 404, $headers);
                }
                $revisions[$id] = $record['template']['revision'];
            }
        }
        $rows = [];
        foreach ($revisions as $id => $revision) {
            $record = $catalog->editData((int) $id);
            if ($record === null) {
                return new Response($translator->trans('Aggregate template not found.', [], 'aggregate_template'), 404, $headers);
            }
            $rows[] = ['id' => (int) $id, 'name' => $record['template']['name'], 'revision' => $revision];
        }
        $form = $forms->create(AggregateTemplateDeleteType::class, [
            'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR),
        ], ['action' => $urls->generate('aggregate_template_delete')]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'aggregate_template')));
            }
            if ($form->isValid()) {
                try {
                    $postedRevisions = json_decode((string) $form->get('revisions')->getData(), true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($postedRevisions) || array_keys($postedRevisions) !== array_keys($revisions)) {
                        throw new \InvalidArgumentException('Delete confirmation selection changed. Reload and try again.');
                    }
                    $editor->delete($actor->id, $postedRevisions);
                    return new RedirectResponse($urls->generate('aggregate_template_list', ['deleted' => 1]), 303, $headers);
                } catch (AggregateTemplateConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'aggregate_template')));
                } catch (\JsonException|\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'aggregate_template')));
                } catch (AggregateTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'aggregate_template'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\RuntimeException) {
                    return new Response($translator->trans('Delete outcome could not be confirmed. Reload the list before retrying.', [], 'aggregate_template'), 502, $headers);
                }
            }
        }
        return new Response($twig->render('aggregate_template/delete.html.twig', ['rows' => $rows, 'form' => $form->createView()]), $status, $headers);
    }
}

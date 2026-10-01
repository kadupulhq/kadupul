<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Controller;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateEditor;
use Kadupul\AggregateTemplate\Application\Query\AggregateTemplateAccessDenied;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateCatalog;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\AggregateTemplateConflict;
use Kadupul\AggregateTemplate\Infrastructure\Symfony\Form\AggregateTemplateType;
use Kadupul\IdentityAccess\Contract\CurrentActor;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AggregateTemplateEditController
{
    #[Route('/aggregate-templates/{id}/edit', name: 'aggregate_template_edit', requirements: ['id' => '0|[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, CurrentActor $currentActor, AggregateTemplatePermissions $access, AggregateTemplateCatalog $catalog, AggregateTemplateEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $currentActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 401, $headers);
        }
        if (!$access->canManage($actor)) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 403, $headers);
        }
        $sourceId = filter_var($request->query->get('source', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
        $data = $catalog->editData($id > 0 ? $id : null, $sourceId);
        if ($data === null) {
            return new Response($translator->trans('Aggregate template not found.', [], 'aggregate_template'), 404, $headers);
        }
        $template = $data['template'];
        $sourceSelectorAsset = rtrim($configuration->values()['url_path'] ?? '/', '/') . '/public/js/aggregate-template-source.js';
        $form = $forms->create(AggregateTemplateType::class, $template, [
            'action' => $urls->generate('aggregate_template_edit', ['id' => $template['id'], 'source' => $template['id'] === 0 ? $template['graph_template_id'] : null]),
            'graph_templates' => ['Select a source graph template' => 0] + $data['graphTemplates'],
            'graph_types' => $data['graphTypes'], 'totals' => $data['totals'],
            'total_types' => $data['totalTypes'], 'order_types' => $data['orderTypes'],
            'graph_field_names' => array_keys($data['graphSettings']), 'graph_field_metadata' => $data['graphFieldMetadata'],
            'color_templates' => $data['colorTemplates'],
            'source_locked' => $template['id'] > 0,
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'aggregate_template')));
            }
            if ($form->isValid()) {
                $values = $form->getData();
                if (!is_string($values['name'] ?? null) || trim($values['name']) === '' || mb_strlen($values['name']) > 64) {
                    $form->addError(new FormError($translator->trans('Enter an aggregate template name of at most 64 characters.', [], 'aggregate_template')));
                    return new Response($twig->render('aggregate_template/edit.html.twig', [
                        'template' => $template, 'data' => $data, 'form' => $form->createView(), 'saved' => false, 'sourceSelectorAsset' => $sourceSelectorAsset,
                    ]), 422, $headers);
                }
                if ((int) $values['id'] !== $id) {
                    return new Response($translator->trans('Aggregate template selection changed. Reload before saving.', [], 'aggregate_template'), 409, $headers);
                }
                $saveId = $id;
                $revision = (string) $values['revision'];
                unset($values['id'], $values['revision']);
                try {
                    $savedId = $editor->save($actor->id, $saveId, $values, $revision);
                    return new RedirectResponse($urls->generate('aggregate_template_edit', ['id' => $savedId, 'saved' => 1]), 303, $headers);
                } catch (AggregateTemplateConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'aggregate_template')));
                } catch (AggregateTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'aggregate_template'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'aggregate_template')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome could not be confirmed. Reload before retrying.', [], 'aggregate_template')));
                }
            }
        }
        return new Response($twig->render('aggregate_template/edit.html.twig', [
            'template' => $template, 'data' => $data, 'form' => $form->createView(),
            'saved' => $request->query->get('saved') === '1',
            'sourceSelectorAsset' => $sourceSelectorAsset,
        ]), $status, $headers);
    }
}

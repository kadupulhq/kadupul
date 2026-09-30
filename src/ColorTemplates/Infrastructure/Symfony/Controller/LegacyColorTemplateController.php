<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Controller;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyColorTemplateController
{
    #[Route('/graphing/color-templates/legacy', name: 'color_template_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function templates(Request $request, ColorTemplateAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to process color template request.', [], 'color_templates'), 502, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open Color Templates and submit a new form.', [], 'color_templates'), 409, $headers);
        }
        $query = $request->query->all();
        try {
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            if ($action === 'template_edit') {
                $id = $query['color_template_id'] ?? '0';
                if (!is_string($id) || !preg_match('/\A(?:0|[1-9][0-9]{0,7})\z/D', $id)) {
                    throw new \InvalidArgumentException();
                }
                return new RedirectResponse($urls->generate($id === '0' ? 'color_template_create' : 'color_template_edit', $id === '0' ? [] : ['id' => $id]), 302, $headers);
            }
            if ($action !== '' && $action !== 'actions') {
                return new Response($translator->trans('Open Color Templates and use its current forms.', [], 'color_templates'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action === 'actions') {
                return new Response($translator->trans('Select templates from the Color Templates list to start an action.', [], 'color_templates'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            $filters = [];
            foreach (['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'] as $field) {
                if (isset($query[$field])) {
                    if (!is_string($query[$field])) {
                        throw new \InvalidArgumentException();
                    }
                    $filters[$field] = $query[$field];
                }
            }
            return new RedirectResponse($urls->generate('color_template_list', $filters), 302, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template request.', [], 'color_templates'), 400, $headers);
        }
    }

    #[Route('/graphing/color-template-items/legacy', name: 'color_template_items_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function items(Request $request, ColorTemplateAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to process color template request.', [], 'color_templates'), 502, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open Color Templates and submit a new form.', [], 'color_templates'), 409, $headers);
        }
        $query = $request->query->all();
        $templateId = $query['color_template_id'] ?? $query['id'] ?? null;
        if (!is_string($templateId) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $templateId)) {
            return new Response($translator->trans('Invalid color template request.', [], 'color_templates'), 400, $headers);
        }
        $action = $query['action'] ?? '';
        if (!is_string($action)) {
            return new Response($translator->trans('Invalid color template request.', [], 'color_templates'), 400, $headers);
        }
        if ($action === 'item_edit') {
            $itemId = $query['color_template_item_id'] ?? '0';
            if (!is_string($itemId) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $itemId)) {
                return new Response($translator->trans('Invalid color template request.', [], 'color_templates'), 400, $headers);
            }
            $route = $itemId === '0' ? 'color_template_item_create' : 'color_template_item_edit';
            return new RedirectResponse($urls->generate($route, ['id' => $templateId] + ($itemId === '0' ? [] : ['itemId' => $itemId])), 302, $headers);
        }
        if ($action === 'item_remove_confirm') {
            $itemId = $query['color_id'] ?? null;
            if (!is_string($itemId) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $itemId)) {
                return new Response($translator->trans('Invalid color template request.', [], 'color_templates'), 400, $headers);
            }
            return new RedirectResponse($urls->generate('color_template_item_delete', ['id' => $templateId, 'itemId' => $itemId]), 302, $headers);
        }
        if (in_array($action, ['item_movedown', 'item_moveup', 'ajax_dnd', 'item_remove'], true)) {
            return new Response($translator->trans('This legacy form has expired. Open Color Templates and submit a new form.', [], 'color_templates'), 409, $headers);
        }
        return new RedirectResponse($urls->generate('color_template_edit', ['id' => $templateId]), 302, $headers);
    }
}

<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceTemplateDefinitions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyDeviceTemplateDefinitionsController
{
    #[Route('/inventory/device-templates/legacy', name: 'inventory_device_template_definitions_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, DeviceTemplateDefinitions $store, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 401, ['Cache-Control' => 'private, no-store']);
        }
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $store->authorize($actor->id);
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy form has expired. Open Device Templates and submit a new form.', [], 'inventory'), 409, $headers);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            if ($action === 'edit') {
                $id = $query['id'] ?? '0';
                if ((!is_string($id) && !is_int($id)) || !preg_match('/^[0-9]{1,8}$/D', (string) $id)) {
                    throw new \InvalidArgumentException();
                }
                return new RedirectResponse($urls->generate((int) $id > 0 ? 'inventory_device_template_definition_edit' : 'inventory_device_template_definition_create', (int) $id > 0 ? ['id' => (int) $id] : []), 302, $headers);
            }
            if (in_array($action, ['item_remove_gt_confirm', 'item_remove_dq_confirm'], true)) {
                $parent = $query['host_template_id'] ?? null;
                $child = $query['id'] ?? null;
                foreach ([$parent, $child] as $value) {
                    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string) $value)) {
                        throw new \InvalidArgumentException();
                    }
                }
                return new RedirectResponse($urls->generate('inventory_device_template_definition_association', ['id' => (int) $parent, 'kind' => $action === 'item_remove_gt_confirm' ? 'graph' : 'query', 'operation' => 'remove', 'child' => (int) $child]), 302, $headers);
            }
            if ($action !== '') {
                return new Response($translator->trans('Open Device Templates and use its current forms.', [], 'inventory'), 405, $headers);
            }
            $defaults = $store->defaults($actor->id, isset($query['clear']));
            foreach (['clear', 'sort_column', 'rows', 'has_hosts'] as $key) {
                if (isset($query[$key]) && !is_string($query[$key]) && !is_int($query[$key])) {
                    throw new \InvalidArgumentException();
                }
            }
            $filters = DeviceTemplateFilters::parse(['q' => $query['filter'] ?? ($defaults['q'] ?? ''), 'has_hosts' => $query['has_hosts'] ?? ($defaults['has_hosts'] ?? 'false'), 'class' => $query['class'] ?? '-1', 'graph' => ($query['graph_template'] ?? '-1') === '-1' ? 0 : $query['graph_template'], 'page' => $query['page'] ?? 1, 'size' => ($query['rows'] ?? '-1') === '-1' ? ($defaults['size'] ?? 25) : $query['rows'], 'sort' => ['ht.name' => 'name', 'ht.id' => 'id', 'ht.class' => 'class', 'hosts' => 'hosts'][$query['sort_column'] ?? 'ht.name'] ?? 'name', 'direction' => strtolower(is_string($query['sort_direction'] ?? 'asc') ? ($query['sort_direction'] ?? 'asc') : '')], $defaults);
            return new RedirectResponse($urls->generate('inventory_device_templates', $filters), 302, $headers);
        } catch (InventoryAccessDenied) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 403, $headers);
        } catch (\InvalidArgumentException|\TypeError) {
            return new Response($translator->trans('Invalid device template filters.', [], 'inventory'), 400, $headers);
        }
    }
}

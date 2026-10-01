<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\DataInputDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyDataInputController
{
    #[Route('/data-inputs/legacy', name: 'data_input_legacy', methods: ['GET','HEAD','POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, DataInputAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, $headers);
        }
        try {
            $access->authorize();
        } catch (DataInputDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), $error->anonymous ? 401 : 403, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open Data Input Methods and submit a new form.', [], 'data_input'), 409, $headers);
        }
        try {
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            $id = $query['id'] ?? '0';
            if (!is_string($id) || ($id !== '' && !preg_match('/\A[0-9]{1,8}\z/D', $id))) {
                throw new \InvalidArgumentException();
            }
            if ($action === 'edit') {
                return new RedirectResponse($urls->generate((int) $id > 0 ? 'data_input_edit' : 'data_input_create', (int) $id > 0 ? ['id' => (int) $id] : []), 302, $headers);
            }
            if (in_array($action, ['field_edit','field_remove_confirm'], true)) {
                $parent = $query['data_input_id'] ?? null;
                if (!is_string($parent) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $parent)) {
                    throw new \InvalidArgumentException();
                }
                $route = $action === 'field_edit' ? 'data_input_field' : 'data_input_action';
                $params = ['id' => (int) $parent,'field' => (int) $id];
                if ($action === 'field_remove_confirm') {
                    $params['operation'] = 'field_delete';
                } else {
                    $type = $query['type'] ?? 'in';
                    if (!is_string($type) || !in_array($type, ['in','out'], true)) {
                        throw new \InvalidArgumentException();
                    } $params['direction'] = $type;
                }
                return new RedirectResponse($urls->generate($route, $params), 302, $headers);
            }
            if ($action !== '') {
                return new Response($translator->trans('Use the current Data Input Methods forms.', [], 'data_input'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            $params = [];
            foreach (['filter' => 'filter','rows' => 'rows','page' => 'page','sort_column' => 'sort','sort_direction' => 'direction'] as $old => $new) {
                if (isset($query[$old])) {
                    if (!is_string($query[$old])) {
                        throw new \InvalidArgumentException();
                    } $params[$new] = $query[$old];
                }
            }
            return new RedirectResponse($urls->generate('data_inputs', $params), 302, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid data input request.', [], 'data_input'), 400, $headers);
        }
    }
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefStore;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** cdef.php lands here. Old links navigate; nothing here changes data. */
final class LegacyCdefController
{
    private const string ID = '/\A(?:0|[1-9][0-9]{0,7})\z/D';

    #[Route('/graph-definitions/cdefs/legacy', name: 'graph_cdef_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy CDEF form has expired. Open CDEFs and submit a new form.', [], 'cdef'), 409, $headers);
            }
            $query = $request->query->all();
            unset($query['header']);
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid CDEF filters.');
            }
            if ($action === 'edit') {
                $id = self::id($query['id'] ?? '0');
                return self::redirect($urls->generate($id === 0 ? 'graph_cdef_create' : 'graph_cdef_edit', $id === 0 ? [] : ['id' => $id]));
            }
            if ($action === 'item_edit') {
                $parameters = ['cdefId' => self::id($query['cdef_id'] ?? '0'), 'itemId' => self::id($query['id'] ?? '0')];
                if ($parameters['cdefId'] === 0) {
                    throw new \InvalidArgumentException('Invalid CDEF filters.');
                }
                if (isset($query['type_select'])) {
                    $parameters['type'] = $query['type_select'];
                }
                return self::redirect($urls->generate('graph_cdef_item_edit', $parameters));
            }
            if ($action === 'item_remove_confirm') {
                // The legacy dialog passed the CDEF as id and the item as cdef_id.
                $cdefId = self::id($query['id'] ?? '0');
                $itemId = self::id($query['cdef_id'] ?? '0');
                if ($cdefId === 0 || $itemId === 0) {
                    throw new \InvalidArgumentException('Invalid CDEF filters.');
                }
                return self::redirect($urls->generate('graph_cdef_item_delete', ['cdefId' => $cdefId, 'itemId' => $itemId]));
            }
            if (in_array($action, ['save', 'actions', 'item_remove', 'item_moveup', 'item_movedown', 'ajax_dnd'], true)) {
                return new Response($translator->trans('Open CDEFs and use its current forms.', [], 'cdef'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid CDEF filters.');
            }
            if (array_key_exists('clear', $query)) {
                if ($query['clear'] !== '1') {
                    throw new \InvalidArgumentException('Invalid CDEF filters.');
                }
                return self::redirect($urls->generate('graph_cdefs', ['clear' => '1']));
            }
            $context = array_intersect_key($query, array_flip(CdefFilters::KEYS));
            if ($context !== []) {
                $context = CdefFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasGraphs())->query();
            }
            return self::redirect($urls->generate('graph_cdefs', $context));
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid CDEF filters.', [], 'cdef'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, $headers);
        }
    }

    private static function id(mixed $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        if (!is_string($raw) || preg_match(self::ID, $raw) !== 1) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }
        return (int) $raw;
    }

    private static function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
    }
}

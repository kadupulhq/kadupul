<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Safe GET navigation from color_templates.php and color_templates_items.php; posted legacy forms expire. */
final class LegacyColorTemplatesController
{
    #[Route('/graphing/color-templates/legacy', name: 'color_template_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function templates(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return $this->expired($translator);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid color template request.');
            }
            if ($action === 'template_edit') {
                $id = $this->id($query['color_template_id'] ?? '0', true);
                return new RedirectResponse($id === 0 ? $urls->generate('color_template_create') : $urls->generate('color_template_edit', ['id' => $id]), 302, $headers);
            }
            if (in_array($action, ['actions', 'save'], true)) {
                return new Response($translator->trans('Open Color Templates and use its current forms.', [], 'color_template'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid color template request.');
            }
            if (array_key_exists('clear', $query)) {
                if ($query['clear'] !== '1') {
                    throw new \InvalidArgumentException('Invalid color template request.');
                }
                return new RedirectResponse($urls->generate('color_template_list', ['reset' => '1']), 302, $headers);
            }
            // A bare menu visit keeps the saved filters, as the legacy session did.
            $context = array_intersect_key($query, array_flip(ColorTemplateFilters::KEYS));
            if ($context === []) {
                return new RedirectResponse($urls->generate('color_template_list'), 302, $headers);
            }
            $filters = ColorTemplateFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasGraphs());
            return new RedirectResponse($urls->generate('color_template_list', $filters->query()), 302, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template request.', [], 'color_template'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Templates. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
    }

    #[Route('/graphing/color-templates/items/legacy', name: 'color_template_items_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function items(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return $this->expired($translator);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid color template request.');
            }
            if ($action === 'item_edit') {
                $id = $this->id($query['color_template_id'] ?? null, false);
                $itemId = $this->id($query['color_template_item_id'] ?? '0', true, 10);
                return new RedirectResponse($itemId === 0
                    ? $urls->generate('color_template_item_create', ['id' => $id])
                    : $urls->generate('color_template_item_edit', ['id' => $id, 'itemId' => $itemId]), 302, $headers);
            }
            if ($action === 'item') {
                return new RedirectResponse($urls->generate('color_template_edit', ['id' => $this->id($query['color_template_id'] ?? null, false)]), 302, $headers);
            }
            if ($action === 'item_remove_confirm') {
                // The legacy dialog named the template "id" and the item "color_id".
                $id = $this->id($query['id'] ?? null, false);
                $itemId = $this->id($query['color_id'] ?? null, false, 10);
                return new RedirectResponse($urls->generate('color_template_item_delete', ['id' => $id, 'itemId' => $itemId]), 302, $headers);
            }
            if (in_array($action, ['save', 'item_remove', 'item_moveup', 'item_movedown', 'ajax_dnd'], true)) {
                return new Response($translator->trans('Open Color Templates and use its current forms.', [], 'color_template'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            throw new \InvalidArgumentException('Invalid color template request.');
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template request.', [], 'color_template'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Templates. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
    }

    /** Template IDs fit mediumint; item IDs are int, as the route requirements say. */
    private function id(mixed $raw, bool $allowZero, int $digits = 8): int
    {
        $pattern = '/\A' . ($allowZero ? '(?:0|' : '(?:') . '[1-9][0-9]{0,' . ($digits - 1) . '})\z/D';
        if (!is_string($raw) || !preg_match($pattern, $raw)) {
            throw new \InvalidArgumentException('Invalid color template request.');
        }
        return (int) $raw;
    }

    private function expired(TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('This legacy Color Template form has expired. Open Color Templates and submit a new form.', [], 'color_template'), 409, ['Cache-Control' => 'private, no-store']);
    }
}

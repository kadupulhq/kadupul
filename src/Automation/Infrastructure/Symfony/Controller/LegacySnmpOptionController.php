<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Kadupul\Platform\Contract\LegacyRequestId;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** automation_snmp.php lands here. Old links navigate; nothing here reads or changes data. */
final readonly class LegacySnmpOptionController
{
    private const string EXPIRED = 'This SNMP Option Set form has expired. Open the SNMP Option Set again and use its current form.';

    #[Route('/automation/snmp-options/legacy', name: 'automation_snmp_options_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $access->authorize();
            // Every legacy post was a save, a reorder or a bulk action (drp_action with
            // chk_* or selected_items). Those forms carry no revision, so none is replayed.
            if ($request->isMethod('POST')) {
                return SnmpOptionSetEditController::message(self::EXPIRED, 409, $translator);
            }
            $query = $request->query->all();
            unset($query['header']);
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid SNMP Option Set request.');
            }
            return match ($action) {
                'edit' => self::redirect(($id = self::id($query['id'] ?? '')) === 0
                    ? $urls->generate('automation_snmp_option_create')
                    : $urls->generate('automation_snmp_option_edit', ['id' => $id])),
                'item_edit' => self::redirect(self::entry($urls, self::id($query['id'] ?? ''), self::id($query['item_id'] ?? ''))),
                'save', 'ajax_dnd', 'item_movedown', 'item_moveup', 'item_remove_confirm', 'item_remove' => SnmpOptionSetEditController::message(self::EXPIRED, 409, $translator),
                // A bulk action without a posted selection returned to the list.
                'actions' => self::redirect($urls->generate('admin_snmp_options')),
                '' => self::redirect($urls->generate('admin_snmp_options', self::filters($query))),
                default => throw new \InvalidArgumentException('Invalid SNMP Option Set request.'),
            };
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid SNMP Option Set request.', [], 'automation'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, $headers);
        }
    }

    private static function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
    }

    /** An absent or empty ID is 0, as the legacy page read it. */
    private static function id(mixed $raw): int
    {
        return LegacyRequestId::parse($raw, 'Invalid SNMP Option Set request.', 10);
    }

    private static function entry(UrlGeneratorInterface $urls, int $set, int $entry): string
    {
        if ($set === 0) {
            return $urls->generate('admin_snmp_options');
        }
        return $entry === 0
            ? $urls->generate('automation_snmp_entry_create', ['id' => $set])
            : $urls->generate('automation_snmp_entry_edit', ['id' => $set, 'entry' => $entry]);
    }

    /**
     * Bookmarked list filters carry over; clear=1 and anything else the old list ignored are dropped.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private static function filters(array $query): array
    {
        if (($query['clear'] ?? '1') !== '1') {
            throw new \InvalidArgumentException('Invalid SNMP Option Set request.');
        }
        $context = array_key_exists('clear', $query) ? [] : array_intersect_key($query, array_flip(['filter', 'rows', 'page', 'sort_column', 'sort_direction']));
        return $context === [] ? [] : array_intersect_key(ListFilters::fromQuery(ListScreen::SnmpOptions, $context)->query(), $context);
    }
}

<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** automation_templates.php lands here. Old links navigate; nothing here changes data. */
final class LegacyAutomationTemplateController
{
    private const string ID = '/\A(?:0|[1-9][0-9]{0,7})\z/D';

    #[Route('/automation/templates/legacy', name: 'automation_templates_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy automation template form has expired. Open Device Rules and submit a new form.', [], 'automation'), 409, $headers);
            }
            $query = $request->query->all();
            unset($query['header']);
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid automation template filters.');
            }
            if ($action === 'edit') {
                $id = self::id($query['id'] ?? '0');
                return self::redirect($urls->generate($id === 0 ? 'automation_template_create' : 'automation_template_edit', $id === 0 ? [] : ['id' => $id]));
            }
            if (in_array($action, ['save', 'actions', 'remove', 'moveup', 'movedown', 'ajax_dnd'], true)) {
                return new Response($translator->trans('Open Device Rules and use its current forms.', [], 'automation'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid automation template filters.');
            }
            if (array_key_exists('clear', $query)) {
                if ($query['clear'] !== '1') {
                    throw new \InvalidArgumentException('Invalid automation template filters.');
                }
                return self::redirect($urls->generate('automation_templates', ['clear' => '1']));
            }
            // The legacy save redirect carried a stray id; only the list filters matter here.
            $context = array_intersect_key($query, array_flip(AutomationTemplateFilters::KEYS));
            if ($context !== []) {
                $context = AutomationTemplateFilters::fromQuery($context, $store->defaultRows())->query();
            }
            return self::redirect($urls->generate('automation_templates', $context));
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid automation template filters.', [], 'automation'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load automation templates. Reload before retrying.', [], 'automation'), 502, $headers);
        }
    }

    private static function id(mixed $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        if (!is_string($raw) || preg_match(self::ID, $raw) !== 1) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }
        return (int) $raw;
    }

    private static function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
    }
}

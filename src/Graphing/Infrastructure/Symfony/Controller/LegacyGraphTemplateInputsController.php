<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Safe GET navigation from graph_templates_inputs.php URLs, which
 * graph_templates.php and the management catalogs still emit. A posted
 * legacy form expires; GET never changes anything.
 */
final class LegacyGraphTemplateInputsController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'graph_template_input';

    #[Route('/graphing/graph-templates/inputs/legacy', name: 'graph_template_input_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, GraphTemplateInputAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_template_input'), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy Graph Item Input form has expired. Open the Graph Template and submit a new form.', [], self::DOMAIN), 409, self::HEADERS);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? 'input_edit';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            $templateId = self::id($query['graph_template_id'] ?? null, false);
            return match ($action) {
                // The page's default action was input_edit; id 0 or none opened a new input.
                '', 'input_edit' => new RedirectResponse(($inputId = self::id($query['id'] ?? '0', true)) === 0
                    ? $urls->generate('graph_template_input_create', ['templateId' => $templateId])
                    : $urls->generate('graph_template_input_edit', ['templateId' => $templateId, 'inputId' => $inputId]), 302, self::HEADERS),
                // Removal now asks for confirmation and posts with a CSRF token.
                'input_remove' => new RedirectResponse($urls->generate('graph_template_input_delete', ['templateId' => $templateId, 'inputId' => self::id($query['id'] ?? null, false)]), 302, self::HEADERS),
                'save' => new Response($translator->trans('Open the Graph Template and use its current forms.', [], self::DOMAIN), 405, self::HEADERS + ['Allow' => 'GET, HEAD']),
                default => throw new \InvalidArgumentException(),
            };
        } catch (GraphTemplateInputAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid Graph Item Input request.', [], self::DOMAIN), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Graph Item Inputs are unavailable. Reload before retrying.', [], self::DOMAIN), 503, self::HEADERS + ['Retry-After' => '30']);
        }
    }

    /** Template and input IDs are mediumint unsigned: decimal digits only, no sign, exponent or padding. */
    private static function id(mixed $raw, bool $allowZero): int
    {
        if (!is_string($raw) || preg_match($allowZero ? '/\A(?:0|[1-9][0-9]{0,7})\z/D' : '/\A[1-9][0-9]{0,7}\z/D', $raw) !== 1 || (int) $raw > GraphTemplateInput::MAX_ID) {
            throw new \InvalidArgumentException();
        }
        return (int) $raw;
    }
}

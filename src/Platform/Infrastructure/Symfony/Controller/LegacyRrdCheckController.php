<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\RrdCheckStore;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckFilters;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** rrdcheck.php lands here. Old links navigate; nothing here changes data. */
final class LegacyRrdCheckController
{
    #[Route('/utilities/rrd-check/legacy', name: 'platform_rrd_check_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, RrdCheckAccess $access, RrdCheckStore $store, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy RRD check form has expired. Open RRD Check and submit a new form.', [], 'rrd_check'), 409, $headers);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid RRD check filters.');
            }
            // The old purge button issued a GET; send it to the confirmation form.
            if ($action === 'purge') {
                return new RedirectResponse($urls->generate('platform_rrd_check_purge'), 302, $headers);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid RRD check filters.');
            }
            if (array_key_exists('clear', $query)) {
                if ($query['clear'] !== '1') {
                    throw new \InvalidArgumentException('Invalid RRD check filters.');
                }
                return new RedirectResponse($urls->generate('platform_rrd_checks', ['clear' => '1']), 302, $headers);
            }
            $context = array_intersect_key($query, array_flip(RrdCheckFilters::KEYS));
            if ($context !== []) {
                $context = RrdCheckFilters::fromQuery($context, $store->defaultRows())->query();
            }
            return new RedirectResponse($urls->generate('platform_rrd_checks', $context), 302, $headers);
        } catch (RrdCheckAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid RRD check filters.', [], 'rrd_check'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load RRD check problems. Reload before retrying.', [], 'rrd_check'), 502, $headers);
        }
    }
}

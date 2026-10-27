<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Navigation\Application\Port\ManagementCatalogAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Query\ViewManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class PersonalReportCatalogController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/management/my-reports', name: 'management_my_reports', defaults: ['screen' => 'my-reports', 'id' => '0'], methods: ['GET', 'HEAD'])]
    public function __invoke(CatalogScreen $screen, string $id, Request $request, AuthenticatedAccess $identity, ManagementCatalogAccess $access, ViewManagementCatalog $view, Environment $twig, TranslatorInterface $translator): Response
    {
        $actor = $identity->authenticatedActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'management'), 401, self::HEADERS);
        }
        try {
            $access->authorize($screen);
            $result = $view($screen, $request->query->all(), $id);
        } catch (AdministrationListDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'management'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (CatalogParentNotFound) {
            return new Response($translator->trans('Parent record not found.', [], 'management'), 404, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid list filters.', [], 'management'), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load this list. Reload before retrying.', [], 'management'), 502, self::HEADERS);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('management/catalog.html.twig', $result + [
            'screen' => $screen, 'parentId' => (int) $id,
        ]), 200, self::HEADERS);
    }
}

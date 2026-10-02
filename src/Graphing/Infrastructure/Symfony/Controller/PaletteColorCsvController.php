<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Port\PaletteColorPreferences;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteCsv;
use Kadupul\Graphing\Infrastructure\Symfony\Form\PaletteColorImportType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaletteColorCsvController
{
    #[Route('/graphing/colors/export', name: 'palette_color_export', methods: ['GET', 'HEAD'])]
    public function export(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, PaletteColorPreferences $preferences, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            if ($query === []) {
                $query = $preferences->load() ?? [];
            }
            $filters = PaletteColorFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs());
            $colors = $store->export($filters);
            return new Response($request->isMethod('HEAD') ? '' : PaletteCsv::export($colors), 200, $headers + ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="colors.csv"', 'X-Content-Type-Options' => 'nosniff']);
        } catch (PaletteColorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color filters.', [], 'palette'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to export colors.', [], 'palette'), 502, $headers);
        }
    }
    #[Route('/graphing/colors/import', name: 'palette_color_import', methods: ['GET', 'HEAD', 'POST'])]
    public function import(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            if ($request->query->all() !== []) {
                return new Response($translator->trans('Invalid color filters.', [], 'palette'), 400, $headers);
            }
            $revision = $store->snapshot();
        } catch (PaletteColorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare color import.', [], 'palette'), 502, $headers);
        }
        $form = $forms->create(PaletteColorImportType::class, ['revision' => $revision], ['action' => $urls->generate('palette_color_import')]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        $counts = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $file = $data['file'];
                if (!$file instanceof UploadedFile || !$file->isValid() || !is_uploaded_file($file->getPathname()) || $file->getSize() > PaletteCsv::MAX_BYTES) {
                    throw new \InvalidArgumentException('Select a valid CSV upload no larger than 1 MiB.');
                }
                $bytes = file_get_contents($file->getPathname(), false, null, 0, PaletteCsv::MAX_BYTES + 1);
                if (!is_string($bytes)) {
                    throw new \InvalidArgumentException('Unable to read CSV upload.');
                }
                $rows = PaletteCsv::parse($bytes);
                $counts = $store->import($actor->id, $rows, $data['allow_update'] === true, (string) $data['revision']);
                $status = 200;
                // A successful import consumes the submitted revision. New uploads need a fresh form.
                $form = $forms->create(PaletteColorImportType::class, ['revision' => $store->snapshot()], ['action' => $urls->generate('palette_color_import')]);
            } catch (PaletteColorAccessDenied $error) {
                return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
            } catch (\InvalidArgumentException $error) {
                $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'palette')));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Import failed. Reload before retrying.', [], 'palette')));
            }
        }
        return new Response($twig->render('graphing/palette_color_import.html.twig', ['form' => $form->createView(), 'counts' => $counts]), $status, $headers);
    }
}

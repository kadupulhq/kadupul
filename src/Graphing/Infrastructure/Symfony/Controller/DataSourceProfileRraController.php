<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfileStore;
use Kadupul\Graphing\Application\Query\DataSourceProfileAccessDenied;
use Kadupul\Graphing\Domain\DataSourceProfile;
use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Kadupul\Graphing\Infrastructure\Symfony\DataSourceProfileText;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileRraDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileRraType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DataSourceProfileRraController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/graphing/data-source-profiles/{id}/rras/new', name: 'data_source_profile_rra_create', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function create(int $id, Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $profile = $store->find($id);
        } catch (DataSourceProfileAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, self::HEADERS);
        }
        if ($profile === null) {
            return new Response($translator->trans('Data Source Profile not found.', [], 'data_source_profile'), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $profile, null, $request, $store, $text, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/data-source-profiles/{id}/rras/{rraId}/edit', name: 'data_source_profile_rra_edit', requirements: ['id' => '[1-9][0-9]{0,7}', 'rraId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, int $rraId, Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $profile = $store->find($id);
        } catch (DataSourceProfileAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, self::HEADERS);
        }
        $archive = self::archive($profile, $rraId);
        if ($profile === null || $archive === null) {
            return new Response($translator->trans('RRA not found.', [], 'data_source_profile'), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $profile, $archive, $request, $store, $text, $forms, $twig, $urls, $translator);
    }

    private function edit(int $actorId, DataSourceProfile $profile, ?DataSourceProfileRra $archive, Request $request, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $level = $archive === null ? null : ($archive->steps === 1 ? 1 : $archive->steps * $profile->step);
        $levels = self::levels($profile, $archive);
        $form = $forms->create(DataSourceProfileRraType::class, [
            'name' => $archive?->name ?? $translator->trans('New Profile RRA', [], 'data_source_profile'),
            'steps' => $level ?? $levels[0] ?? null,
            'rows' => (string) ($archive?->rows ?? 600),
            'timespan' => $archive?->timespan ?? DataSourceProfileRra::TIMESPANS[0],
            'revision' => $archive?->revision ?? '',
        ], [
            'action' => $archive === null
                ? $urls->generate('data_source_profile_rra_create', ['id' => $profile->id])
                : $urls->generate('data_source_profile_rra_edit', ['id' => $profile->id, 'rraId' => $archive->id]),
            'read_only' => $profile->isReadOnly(),
            'level_choices' => $text->choices($levels, $level, static fn(int $seconds): string => $seconds === 1
                ? $translator->trans('Each Insert is New Row', [], 'data_source_profile') : $text->level($seconds)),
            'timespan_choices' => $text->choices(DataSourceProfileRra::TIMESPANS, $archive?->timespan, $text->timespan(...)),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_source_profile')));
            }
            $rows = $form->get('rows')->getData();
            if ($rows !== null && (!is_string($rows) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $rows) !== 1 || (int) $rows > DataSourceProfileRra::MAX_ROWS)) {
                $form->get('rows')->addError(new FormError($translator->trans('Enter a number of rows from 1 to 4294967295.', [], 'data_source_profile')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $store->saveArchive(
                        $actorId,
                        $profile->id,
                        $archive?->id,
                        (string) $data['name'],
                        is_int($data['steps']) ? $data['steps'] : null,
                        $rows === null ? null : (int) $rows,
                        is_int($data['timespan']) ? $data['timespan'] : ($archive?->timespan ?? DataSourceProfileRra::TIMESPANS[0]),
                        $archive?->revision === null ? null : (string) $data['revision'],
                    );
                    return new RedirectResponse($urls->generate('data_source_profile_edit', ['id' => $profile->id, 'saved' => 1]), 303, self::HEADERS);
                } catch (DataSourceProfileAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_source_profile')));
                    $status = str_contains($error->getMessage(), 'changed since') || str_contains($error->getMessage(), 'not found') || $error->getMessage() === DataSourceProfileStore::READ_ONLY ? 409 : 422;
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'data_source_profile')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/data_source_profile_rra_edit.html.twig', [
            'profile' => $profile,
            'archive' => $archive,
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    #[Route('/graphing/data-source-profiles/{id}/rras/{rraId}/delete', name: 'data_source_profile_rra_delete', requirements: ['id' => '[1-9][0-9]{0,7}', 'rraId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $id, int $rraId, Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $profile = $store->find($id);
        } catch (DataSourceProfileAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, self::HEADERS);
        }
        $archive = self::archive($profile, $rraId);
        if ($profile === null || $archive === null) {
            return new Response($translator->trans('RRA not found.', [], 'data_source_profile'), 404, self::HEADERS);
        }
        $form = $forms->create(DataSourceProfileRraDeletionType::class, ['revision' => $archive->revision], [
            'action' => $urls->generate('data_source_profile_rra_delete', ['id' => $id, 'rraId' => $rraId]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $store->deleteArchive($actor->id, $id, $rraId, (string) $form->get('revision')->getData());
                return new RedirectResponse($urls->generate('data_source_profile_edit', ['id' => $id, 'saved' => 1]), 303, self::HEADERS);
            } catch (DataSourceProfileAccessDenied $error) {
                return $this->denied($error, $translator);
            } catch (\InvalidArgumentException $error) {
                $status = 409;
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_source_profile')));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Reload before retrying.', [], 'data_source_profile')));
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/data_source_profile_rra_delete.html.twig', [
            'profile' => $profile,
            'archive' => $archive,
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    private static function archive(?DataSourceProfile $profile, int $rraId): ?DataSourceProfileRra
    {
        return array_find($profile?->archives ?? [], static fn(DataSourceProfileRra $archive): bool => $archive->id === $rraId);
    }

    /**
     * The legacy item editor's choices: the first archive takes one row per
     * sample, later ones only coarser levels than any archive so far, and an
     * existing archive keeps its kind.
     *
     * @return list<int>
     */
    private static function levels(DataSourceProfile $profile, ?DataSourceProfileRra $archive): array
    {
        $first = array_find($profile->archives, static fn(DataSourceProfileRra $candidate): bool => $candidate->steps === 1);
        if ($archive?->steps === 1 || ($archive === null && $first === null)) {
            return [1];
        }
        $floor = $archive === null ? max(array_map(static fn(DataSourceProfileRra $candidate): int => $candidate->steps, $profile->archives)) * $profile->step : $profile->step;
        return array_values(array_filter(DataSourceProfileRra::AGGREGATION_LEVELS, static fn(int $level): bool => $level > $floor));
    }

    private function denied(DataSourceProfileAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, self::HEADERS);
    }
}

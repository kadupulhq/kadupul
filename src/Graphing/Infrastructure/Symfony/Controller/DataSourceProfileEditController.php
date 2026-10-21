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
use Kadupul\Graphing\Infrastructure\Symfony\DataSourceProfileText;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileType;
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

final class DataSourceProfileEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/graphing/data-source-profiles/new', name: 'data_source_profile_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $defaults = $store->defaults();
        } catch (DataSourceProfileAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, $defaults, $request, $store, $text, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/data-source-profiles/{id}/edit', name: 'data_source_profile_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
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
        return $this->edit($actor->id, $profile, ['step' => $profile->step, 'heartbeat' => $profile->heartbeat], $request, $store, $text, $forms, $twig, $urls, $translator);
    }

    /** @param array{step: int, heartbeat: int} $defaults */
    private function edit(int $actorId, ?DataSourceProfile $profile, array $defaults, Request $request, DataSourceProfileStore $store, DataSourceProfileText $text, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $query = $request->query->all();
        $form = $forms->create(DataSourceProfileType::class, [
            'name' => $profile?->name ?? $translator->trans('New Profile', [], 'data_source_profile'),
            'step' => $defaults['step'],
            'heartbeat' => $defaults['heartbeat'],
            'x_files_factor' => $profile === null ? '0.5' : self::factor($profile->xFilesFactor),
            'consolidation_function_id' => $profile?->consolidationFunctions ?? [],
            'default' => $profile?->isDefault ?? false,
            'revision' => $profile?->revision ?? '',
        ], [
            'action' => $profile === null ? $urls->generate('data_source_profile_create') : $urls->generate('data_source_profile_edit', ['id' => $profile->id]),
            'csrf_token_id' => $profile === null ? 'data_source_profile_create' : 'data_source_profile_edit',
            'read_only' => $profile?->isReadOnly() ?? false,
            'is_default' => $profile?->isDefault ?? false,
            'step_choices' => $text->choices(DataSourceProfile::SAMPLING_INTERVALS, $profile?->step, $text->interval(...)),
            'heartbeat_choices' => $text->choices(DataSourceProfile::HEARTBEATS, $profile?->heartbeat, $text->heartbeat(...)),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_source_profile')));
            }
            // The choice field folds repeated values into one; the legacy save refused them.
            $functions = $request->request->all()[$form->getName()]['consolidation_function_id'] ?? [];
            if (is_array($functions) && count($functions) !== count(array_unique($functions, SORT_REGULAR))) {
                $form->get('consolidation_function_id')->addError(new FormError($translator->trans('Choose valid consolidation functions.', [], 'data_source_profile')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $saved = $store->save(
                        $actorId,
                        $profile?->id,
                        (string) $data['name'],
                        is_int($data['step']) ? $data['step'] : null,
                        is_int($data['heartbeat']) ? $data['heartbeat'] : null,
                        is_string($data['x_files_factor']) ? $data['x_files_factor'] : null,
                        array_values(array_filter((array) $data['consolidation_function_id'], 'is_int')),
                        $data['default'] === true,
                        $profile === null ? null : (string) $data['revision'],
                    );
                    return new RedirectResponse($urls->generate('data_source_profile_edit', ['id' => $saved['id'], 'saved' => 1] + ($saved['retune'] ? ['retune' => 1] : [])), 303, self::HEADERS);
                } catch (DataSourceProfileAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_source_profile')));
                    $status = str_contains($error->getMessage(), 'changed since') || $error->getMessage() === DataSourceProfileStore::READ_ONLY ? 409 : 422;
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'data_source_profile')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/data_source_profile_edit.html.twig', [
            'profile' => $profile,
            'form' => $form->createView(),
            'saved' => ($query['saved'] ?? null) === '1',
            'retune' => ($query['retune'] ?? null) === '1',
            'rows' => array_sum(array_map(static fn($archive): int => $archive->rows, $profile?->archives ?? [])),
        ]), $status, self::HEADERS);
    }

    /** The stored double as the legacy text box showed it: 0.5, not 0.50000. */
    private static function factor(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private function denied(DataSourceProfileAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, self::HEADERS);
    }
}

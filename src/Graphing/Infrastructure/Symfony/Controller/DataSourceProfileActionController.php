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
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\DataSourceProfileActionType;
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

final class DataSourceProfileActionController
{
    #[Route('/graphing/data-source-profiles/actions/{operation}', name: 'data_source_profile_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $query = $request->query->all();
            $ids = self::ids($query['ids'] ?? null);
            unset($query['ids']);
            $filters = $query === [] ? [] : DataSourceProfileFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasData())->query();
            $selected = $store->findMany($ids);
        } catch (DataSourceProfileAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid profile selection.', [], 'data_source_profile'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, $headers);
        }
        if (count($selected) !== count($ids)) {
            return new Response($translator->trans('One or more selected Data Source Profiles no longer exist.', [], 'data_source_profile'), 404, $headers);
        }
        $duplicate = $operation === 'duplicate';
        $used = $duplicate ? [] : array_values(array_filter($selected, static fn(DataSourceProfile $profile): bool => !$profile->isDeletable()));
        $form = $forms->create(DataSourceProfileActionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode(array_map(static fn(DataSourceProfile $profile): string => $profile->revision, $selected), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'title_format' => '<profile_title> (1)',
        ], [
            'duplicate' => $duplicate,
            'action' => $urls->generate('data_source_profile_action', ['operation' => $operation, 'ids' => $ids] + $filters),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_source_profile')));
            }
            if ($used !== []) {
                $form->addError(new FormError($translator->trans('The default profile and profiles in use by Data Templates or Data Sources can not be deleted.', [], 'data_source_profile')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $submitted = json_decode((string) $data['selection'], false, 8, JSON_THROW_ON_ERROR);
                    $submittedRevisions = json_decode((string) $data['revisions'], true, 8, JSON_THROW_ON_ERROR);
                    if ($submitted !== $ids || !is_array($submittedRevisions)) {
                        throw new \InvalidArgumentException('Invalid profile selection.');
                    }
                    $expected = [];
                    foreach ($submittedRevisions as $id => $revision) {
                        $expected[(int) $id] = $revision;
                    }
                    if ($duplicate) {
                        $store->duplicate($actor->id, $ids, $expected, (string) $data['title_format']);
                    } else {
                        $store->delete($actor->id, $ids, $expected);
                    }
                    return new RedirectResponse($urls->generate('data_source_profile_list', [$duplicate ? 'duplicated' : 'deleted' => 1] + $filters), 303, $headers);
                } catch (DataSourceProfileAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException | \JsonException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                    $message = $error instanceof \JsonException ? 'Invalid profile selection.' : $error->getMessage();
                    $form->addError(new FormError($translator->trans($message, [], 'data_source_profile')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans(
                        $duplicate ? 'Duplicate outcome is uncertain. Check the Data Source Profile list before retrying.' : 'Delete outcome is uncertain. Check the Data Source Profile list before retrying.',
                        [],
                        'data_source_profile',
                    )));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/data_source_profile_action.html.twig', [
            'operation' => $operation,
            'profiles' => array_values($selected),
            'used' => $used,
            'form' => $form->createView(),
            'filters' => $filters,
        ]), $status, $headers);
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > DataSourceProfileStore::MAX_SELECTION || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Invalid profile selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,7}\z/D', $id) !== 1) {
                throw new \InvalidArgumentException('Invalid profile selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

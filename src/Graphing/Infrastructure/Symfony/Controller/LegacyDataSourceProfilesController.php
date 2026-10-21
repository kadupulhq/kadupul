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
use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Kadupul\Graphing\Domain\DataSourceProfileSizing;
use Kadupul\Graphing\Infrastructure\Symfony\DataSourceProfileText;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * data_source_profiles.php lands here. Old links navigate, the size and span
 * lookups still answer, and nothing here changes data.
 */
final class LegacyDataSourceProfilesController
{
    private const string ID = '/\A(?:0|[1-9][0-9]{0,7})\z/D';
    private const string INTEGER = '/\A-?[0-9]{1,10}\z/D';

    #[Route('/graphing/data-source-profiles/legacy', name: 'data_source_profile_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfileText $text, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, $headers);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy Data Source Profile form has expired. Open Data Source Profiles and submit a new form.', [], 'data_source_profile'), 409, $headers);
            }
            $query = $request->query->all();
            unset($query['header']);
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid profile request.');
            }
            if ($action === 'ajax_span' || $action === 'ajax_size') {
                $body = $action === 'ajax_span' ? $this->span($query, $store, $text, $translator) : $this->size($query, $store, $text);
                return new Response($request->isMethod('HEAD') ? '' : $body, 200, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            if ($action === 'edit') {
                $id = self::id($query['id'] ?? '0');
                return self::redirect($urls->generate($id === 0 ? 'data_source_profile_create' : 'data_source_profile_edit', $id === 0 ? [] : ['id' => $id]));
            }
            if ($action === 'item_edit' || $action === 'item_remove_confirm') {
                $profileId = self::id($query['profile_id'] ?? '0');
                $rraId = self::id($query['id'] ?? '0');
                if ($profileId === 0 || ($action === 'item_remove_confirm' && $rraId === 0)) {
                    throw new \InvalidArgumentException('Invalid profile request.');
                }
                return self::redirect(match (true) {
                    $action === 'item_remove_confirm' => $urls->generate('data_source_profile_rra_delete', ['id' => $profileId, 'rraId' => $rraId]),
                    $rraId === 0 => $urls->generate('data_source_profile_rra_create', ['id' => $profileId]),
                    default => $urls->generate('data_source_profile_rra_edit', ['id' => $profileId, 'rraId' => $rraId]),
                });
            }
            if (in_array($action, ['save', 'actions', 'item_remove'], true)) {
                return new Response($translator->trans('Open Data Source Profiles and use its current forms.', [], 'data_source_profile'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid profile request.');
            }
            if (array_key_exists('clear', $query)) {
                if ($query['clear'] !== '1') {
                    throw new \InvalidArgumentException('Invalid profile request.');
                }
                return self::redirect($urls->generate('data_source_profile_list', ['clear' => '1']));
            }
            // A bare menu visit keeps the saved filters, as the legacy session did.
            $context = array_intersect_key($query, array_flip(DataSourceProfileFilters::KEYS));
            if ($context !== []) {
                $context = DataSourceProfileFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasData())->query();
            }
            return self::redirect($urls->generate('data_source_profile_list', $context));
        } catch (DataSourceProfileAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid profile request.', [], 'data_source_profile'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, $headers);
        }
    }

    /**
     * Retention of rows at the chosen level: span is the level in seconds,
     * and 1 means the profile's own polling interval.
     *
     * @param array<string, mixed> $query
     */
    private function span(array $query, DataSourceProfileStore $store, DataSourceProfileText $text, TranslatorInterface $translator): string
    {
        $profileId = self::id($query['profile_id'] ?? '0');
        $level = self::integer($query['span'] ?? '0', 0, 2147483647);
        $rows = ($query['rows'] ?? '') === '' ? 0 : self::integer($query['rows'], PHP_INT_MIN, DataSourceProfileRra::MAX_ROWS);
        if ($rows <= 0) {
            return $translator->trans('N/A', [], 'data_source_profile');
        }
        // A missing profile has no step, so its span is empty, as the legacy lookup printed.
        $seconds = DataSourceProfileSizing::retention($level === 1 ? ($store->find($profileId)?->step ?? 0) : $level, 1, $rows);
        return $seconds === null ? $translator->trans('N/A', [], 'data_source_profile') : $text->span($seconds);
    }

    /**
     * The whole profile, or one archive of the given rows. Without a count of
     * checked functions the stored ones count.
     *
     * @param array<string, mixed> $query
     */
    private function size(array $query, DataSourceProfileStore $store, DataSourceProfileText $text): string
    {
        $profile = $store->find(self::id($query['id'] ?? '0'));
        $functions = count($profile?->consolidationFunctions ?? []);
        if (($query['type'] ?? '') === 'profile') {
            $checked = ($query['cfs'] ?? '') === '' ? 0 : self::integer($query['cfs'], 0, count(DataSourceProfile::CONSOLIDATION_FUNCTIONS));
            return $text->profileSize(array_sum(array_map(static fn(DataSourceProfileRra $archive): int => $archive->rows, $profile?->archives ?? [])), $checked === 0 ? $functions : $checked);
        }
        $rows = ($query['rows'] ?? '') === '' ? 0 : self::integer($query['rows'], PHP_INT_MIN, DataSourceProfileRra::MAX_ROWS);
        return $text->archiveSize($rows, $functions);
    }

    private static function integer(mixed $raw, int $minimum, int $maximum): int
    {
        if (!is_string($raw) || preg_match(self::INTEGER, $raw) !== 1 || (int) $raw < $minimum || (int) $raw > $maximum) {
            throw new \InvalidArgumentException('Invalid profile request.');
        }
        return (int) $raw;
    }

    private static function id(mixed $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        if (!is_string($raw) || preg_match(self::ID, $raw) !== 1) {
            throw new \InvalidArgumentException('Invalid profile request.');
        }
        return (int) $raw;
    }

    private static function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, 302, ['Cache-Control' => 'private, no-store']);
    }
}

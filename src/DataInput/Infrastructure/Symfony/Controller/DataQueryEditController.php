<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Symfony\Controller;

use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\DataQueries;
use Kadupul\DataInput\Application\Port\DataQueryAccess;
use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\DataInput\Domain\DataQueryRejected;
use Kadupul\DataInput\Domain\DataQuerySettings;
use Kadupul\DataInput\Infrastructure\Symfony\Form\DataQueryType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** The settings of a data_queries.php data query; its graph template associations, list and bulk actions remain legacy. */
final class DataQueryEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'data_query';
    /** The default xml_path data_queries.php offered for a new data query. */
    private const string DEFAULT_XML_PATH = '<path_cacti>/resource/';

    #[Route('/data-collection/queries/new', name: 'data_query_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, DataQueryAccess $access, DataQueries $queries, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $state = $queries->find(0);
        } catch (DataInputDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->anonymous ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the data query. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        return $this->edit(0, $state, ['name' => '', 'description' => '', 'xml_path' => self::DEFAULT_XML_PATH, 'data_input_id' => null], $request, $queries, $forms, $twig, $urls, $translator);
    }

    #[Route('/data-collection/queries/{id}/edit', name: 'data_query_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, DataQueryAccess $access, DataQueries $queries, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $state = $id <= DataQuerySettings::ID_MAX ? $queries->find($id) : null;
        } catch (DataInputDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->anonymous ? 401 : 403, self::HEADERS);
        } catch (DataInputNotFound) {
            return new Response($translator->trans('Data query not found.', [], self::DOMAIN), 404, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the data query. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        if ($state === null || $state['query'] === null) {
            return new Response($translator->trans('Data query not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        $query = $state['query'];
        return $this->edit($id, $state, ['name' => $query['name'], 'description' => $query['description'], 'xml_path' => $query['xml_path'], 'data_input_id' => $query['data_input_id']], $request, $queries, $forms, $twig, $urls, $translator);
    }

    /**
     * @param array{query: ?array{id: int, name: string, description: string, xml_path: string, data_input_id: int}, revision: string, xml: string, inputs: array<int, string>} $state
     * @param array<string, int|string|null> $settings
     */
    private function edit(int $id, array $state, array $settings, Request $request, DataQueries $queries, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        // A stored method that is not a query input type is not offered, as in
        // legacy; the form then asks for one instead of silently replacing it.
        $form = $forms->create(DataQueryType::class, $settings + ['revision' => $state['revision']], [
            'inputs' => $state['inputs'],
            'action' => $id === 0 ? $urls->generate('data_query_create') : $urls->generate('data_query_edit', ['id' => $id]),
            'csrf_token_id' => $id === 0 ? 'data_query_create' : 'data_query_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], self::DOMAIN)));
            }
            if ($form->isValid()) {
                $data = self::data($form);
                try {
                    $change = new DataQuerySettings(
                        is_string($data['name'] ?? null) ? $data['name'] : '',
                        is_string($data['description'] ?? null) ? $data['description'] : '',
                        is_string($data['xml_path'] ?? null) ? $data['xml_path'] : '',
                        is_int($data['data_input_id'] ?? null) ? $data['data_input_id'] : 0,
                    );
                    $saved = $queries->save($id, $change, is_string($data['revision'] ?? null) ? $data['revision'] : '');
                    // Legacy returned to the data query after saving.
                    return new RedirectResponse($urls->generate('data_query_edit', ['id' => $saved['id'], 'saved' => $saved['partial'] ? 'partial' : '1']), 303, self::HEADERS);
                } catch (DataInputDenied $error) {
                    return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->anonymous ? 401 : 403, self::HEADERS);
                } catch (DataQueryRejected $error) {
                    $target = $error->field !== null && $form->has($error->field) ? $form->get($error->field) : $form;
                    $target->addError(new FormError($translator->trans($error->getMessage(), $error->parameters, self::DOMAIN)));
                } catch (DataInputConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], self::DOMAIN)));
                } catch (DataInputNotFound) {
                    return new Response($translator->trans('Data query not found.', [], self::DOMAIN), 404, self::HEADERS);
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], self::DOMAIN)));
                }
            }
        }
        // Read without InputBag::get(), which throws on arrays.
        $saved = $request->query->all()['saved'] ?? null;
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('data_query/edit.html.twig', [
            'query' => $state['query'],
            'xml' => $state['xml'],
            'form' => $form->createView(),
            'saved' => in_array($saved, ['1', 'partial'], true) ? $saved : null,
        ]), $status, self::HEADERS);
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @return array<string, mixed>
     */
    private static function data(FormInterface $form): array
    {
        $data = $form->getData();
        if (!is_array($data)) {
            throw new \UnexpectedValueException('The submitted form has no data.');
        }
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = $value;
            }
        }
        return $fields;
    }
}

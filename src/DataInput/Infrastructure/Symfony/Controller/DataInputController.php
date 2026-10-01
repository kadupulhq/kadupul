<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\DataInput\Application\DataInputMethods;
use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Domain\DataInputState;
use Kadupul\DataInput\Infrastructure\Symfony\Form\DataInputMethodType;
use Kadupul\DataInput\Infrastructure\Symfony\Form\DataInputFieldType;
use Kadupul\DataInput\Infrastructure\Symfony\Form\DataInputActionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DataInputController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    #[Route('/data-inputs', name: 'data_inputs', methods: ['GET', 'HEAD'])]
    public function list(Request $request, ConsoleAccess $console, DataInputAccess $access, DataInputMethods $methods, Environment $twig, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            foreach ($query as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Invalid list filters.');
                }
            }
            $data = $methods->execute('list', 0, ['filter' => $query['filter'] ?? null, 'page' => $query['page'] ?? 1, 'rows' => $query['rows'] ?? null, 'sort' => $query['sort'] ?? null, 'direction' => $query['direction'] ?? null, 'clear' => ($query['clear'] ?? '') === '1']);
            return new Response($twig->render('data_input/list.html.twig', ['data' => $data, 'saved' => $this->queryString($request, 'saved'), 'operation' => $this->queryString($request, 'operation'), 'retry_ids' => $this->retryIds($request)]), 200, self::HEADERS);
        } catch (\Throwable $error) {
            return $this->failure($error, $translator);
        }
    }
    #[Route('/data-inputs/new', name: 'data_input_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        return $this->editor(0, $request, $methods, $forms, $twig, $urls, $translator);
    }
    #[Route('/data-inputs/{id}/edit', name: 'data_input_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(int $id, Request $request, ConsoleAccess $console, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        return $this->editor($id, $request, $methods, $forms, $twig, $urls, $translator);
    }
    private function editor(int $id, Request $request, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        try {
            $state = $methods->execute('find', $id);
            $form = $forms->create(DataInputMethodType::class, ($state['method'] ?: ['name' => '', 'input_string' => '', 'type_id' => 1]) + ['revision' => $state['revision']], ['existing_type' => (int) ($state['method']['type_id'] ?? 1)]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_input')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                try {
                    $result = $methods->execute('save', $id, ['revision' => $data['revision'] ?? '', 'data' => $data]);
                    return new RedirectResponse($urls->generate('data_input_edit', ['id' => $result['id'], 'saved' => $result['partial'] ? 'partial' : '1']), 303, self::HEADERS);
                } catch (DataInputConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                }
            }
            return new Response($twig->render('data_input/edit.html.twig', ['state' => $state, 'form' => $form->createView(), 'saved' => $this->queryString($request, 'saved'), 'operation' => $this->queryString($request, 'operation')]), $status, self::HEADERS);
        } catch (\Throwable $error) {
            return $this->failure($error, $translator);
        }
    }
    #[Route('/data-inputs/{id}/fields/{field}', name: 'data_input_field', requirements: ['id' => '[1-9][0-9]{0,7}', 'field' => '[0-9]{1,8}'], methods: ['GET', 'HEAD', 'POST'])]
    public function field(int $id, int $field, Request $request, ConsoleAccess $console, DataInputAccess $access, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $state = $methods->execute('find', $id);
            $data = null;
            foreach ($state['fields'] as $candidate) {
                if ((int) $candidate['id'] === $field) {
                    $data = $candidate;
                }
            }
            if ($field > 0 && $data === null) {
                return new Response($translator->trans('Field not found.', [], 'data_input'), 404, self::HEADERS);
            }
            $direction = $data['input_output'] ?? $this->queryString($request, 'direction', 'in');
            if (!in_array($direction, ['in', 'out'], true)) {
                throw new \InvalidArgumentException('Invalid field direction.');
            }
            $data ??= ['name' => '', 'data_name' => '', 'input_output' => $direction, 'type_code' => '', 'regexp_match' => '', 'allow_nulls' => '', 'update_rra' => $direction === 'out' ? 'on' : ''];
            foreach (['allow_nulls', 'update_rra'] as $key) {
                $data[$key] = $data[$key] === 'on';
            }
            $placeholders = $direction === 'in' && in_array((int) $state['method']['type_id'], [1, 5], true) ? DataInputState::placeholders($state['method']['input_string']) : null;
            if ($placeholders !== null) {
                $used = array_column(array_filter($state['fields'], static fn(array $item): bool => $item['input_output'] === 'in' && (int) $item['id'] !== $field), 'data_name');
                $placeholders = array_values(array_diff($placeholders, $used));
            }
            $form = $forms->create(DataInputFieldType::class, $data + ['revision' => $state['revision']], ['direction' => $direction, 'placeholders' => $placeholders]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_input')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                try {
                    $data['input_output'] = $direction;
                    $result = $methods->execute('field_save', $id, ['field' => $field, 'revision' => $data['revision'], 'data' => $data]);
                    return new RedirectResponse($urls->generate('data_input_edit', ['id' => $id, 'saved' => $result['partial'] ? 'partial' : '1']), 303, self::HEADERS);
                } catch (DataInputConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                }
            }
            return new Response($twig->render('data_input/field.html.twig', ['state' => $state, 'form' => $form->createView()]), $status, self::HEADERS);
        } catch (\Throwable $error) {
            return $this->failure($error, $translator);
        }
    }
    #[Route('/data-inputs/{id}/{operation}', name: 'data_input_action', requirements: ['id' => '[1-9][0-9]{0,7}', 'operation' => 'delete|duplicate|field_delete|propagate|whitelist'], methods: ['GET', 'HEAD', 'POST'])]
    public function action(int $id, string $operation, Request $request, ConsoleAccess $console, DataInputAccess $access, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $state = $methods->execute('find', $id);
            $field = 0;
            $selectedField = null;
            if ($operation === 'field_delete') {
                $raw = $request->query->all()['field'] ?? null;
                if (!is_string($raw) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $raw)) {
                    throw new \InvalidArgumentException('Invalid field.');
                } $field = (int) $raw;
                foreach ($state['fields'] as $candidate) {
                    if ((int) $candidate['id'] === $field) {
                        $selectedField = $candidate;
                        break;
                    }
                }
                if ($selectedField === null) {
                    throw new \InvalidArgumentException('Field does not belong to this input.');
                }
            }
            $form = $forms->create(DataInputActionType::class, ['revision' => $state['revision'], 'title' => '<input_title> (1)'], ['duplicate' => $operation === 'duplicate']);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_input')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                try {
                    $result = $methods->execute($operation, $id, $data + ['field' => $field]);
                    return new RedirectResponse($urls->generate($operation === 'delete' ? 'data_inputs' : 'data_input_edit', $operation === 'delete' ? [] : ['id' => $result['id'], 'saved' => $result['partial'] ? 'partial' : '1', 'operation' => $operation]), 303, self::HEADERS);
                } catch (DataInputConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                }
            }
            return new Response($twig->render('data_input/action.html.twig', ['state' => $state, 'operation' => $operation, 'selected_field' => $selectedField, 'form' => $form->createView()]), $status, self::HEADERS);
        } catch (\Throwable $error) {
            return $this->failure($error, $translator);
        }
    }

    #[Route('/data-inputs/actions/{operation}', name: 'data_input_bulk', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function bulk(string $operation, Request $request, ConsoleAccess $console, DataInputAccess $access, DataInputMethods $methods, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_input'), 401, self::HEADERS);
        }
        try {
            $access->authorize();
            $ids = $request->query->all()['ids'] ?? null;
            if (!is_array($ids) || $ids === [] || count($ids) > 100) {
                throw new \InvalidArgumentException('Invalid selection.');
            }
            $selected = [];
            foreach ($ids as $raw) {
                if (!is_string($raw) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $raw)) {
                    throw new \InvalidArgumentException('Invalid selection.');
                } $selected[(int) $raw] = true;
            }
            ksort($selected, SORT_NUMERIC);
            $snapshot = $methods->execute('selection', 0, ['ids' => array_keys($selected)]);
            $selection = $snapshot['selection'];
            $names = $snapshot['names'];
            $form = $forms->create(DataInputActionType::class, ['revision' => json_encode($selection, JSON_THROW_ON_ERROR), 'title' => '<input_title> (1)'], ['duplicate' => $operation === 'duplicate']);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'data_input')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                try {
                    $snapshot = json_decode($data['revision'], true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($snapshot) || array_keys($snapshot) !== array_keys($selection)) {
                        throw new \InvalidArgumentException('Invalid selection.');
                    }
                    $result = $methods->execute('bulk_' . $operation, 0, ['selection' => $snapshot, 'title' => $data['title'] ?? '<input_title> (1)']);
                    return new RedirectResponse($urls->generate('data_inputs', ['saved' => $result['partial'] ? 'partial' : '1', 'operation' => 'bulk_' . $operation, 'retry_ids' => $operation === 'duplicate' && $result['partial'] ? implode(',', $result['ids']) : '']), 303, self::HEADERS);
                } catch (DataInputConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'data_input')));
                } catch (\InvalidArgumentException|\JsonException $error) {
                    $form->addError(new FormError($translator->trans('Invalid selection.', [], 'data_input')));
                }
            }
            return new Response($twig->render('data_input/bulk.html.twig', ['names' => $names, 'operation' => $operation, 'form' => $form->createView()]), $status, self::HEADERS);
        } catch (\Throwable $error) {
            return $this->failure($error, $translator);
        }
    }
    private function retryIds(Request $request): array
    {
        $value = $request->query->all()['retry_ids'] ?? '';
        if (!is_string($value) || strlen($value) > 899 || ($value !== '' && !preg_match('/\A[1-9][0-9]{0,7}(?:,[1-9][0-9]{0,7}){0,99}\z/D', $value))) {
            throw new \InvalidArgumentException('Invalid selection.');
        }
        return $value === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $value))));
    }
    private function queryString(Request $request, string $name, string $default = ''): string
    {
        $value = $request->query->all()[$name] ?? $default;
        if (!is_string($value) || strlen($value) > 200) {
            throw new \InvalidArgumentException('Invalid data input request.');
        }
        return $value;
    }
    private function failure(\Throwable $error, TranslatorInterface $translator): Response
    {
        $status = match (true) {
            $error instanceof DataInputDenied => $error->anonymous ? 401 : 403,$error instanceof DataInputConflict => 409,$error instanceof \InvalidArgumentException => 400,default => 502
        };
        $message = $status === 502 ? 'Operation outcome is unknown. Reload before retrying.' : $error->getMessage();
        return new Response($translator->trans($message, [], 'data_input'), $status, self::HEADERS);
    }
}

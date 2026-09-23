<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Domain\DeviceSelection;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Shared validation and failure semantics for confirmed bulk-device operations. */
final readonly class DeviceSelectionForm
{
    public function __construct(private TranslatorInterface $translator) {}

    public function prepare(Request $request, callable $prepare): array|Response
    {
        try {
            $query = $request->query->all();
            $rawIds = $query['ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new \InvalidArgumentException('Invalid device selection.');
            }
            // Authorization remains before repository access and context parsing.
            $devices = $prepare($rawIds);
            return [$devices, DeviceSelection::validateIds($rawIds), DeviceListParameters::context($query)];
        } catch (InventoryAccessDenied|DevicesNotFound $error) {
            return $this->accessFailure($error);
        } catch (\InvalidArgumentException $error) {
            return $this->response($error->getMessage(), 400);
        }
    }

    public function selection(array $data, array $ids): DeviceSelection
    {
        $raw = json_decode((string) $data['selection'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid device selection.');
        }
        $selection = new DeviceSelection($raw);
        if (array_keys($selection->revisions) !== $ids) {
            throw new \InvalidArgumentException('Invalid device selection.');
        }
        return $selection;
    }

    public function rejectExtraFields(FormInterface $form): void
    {
        if ($form->getExtraData() !== []) {
            $form->addError(new FormError($this->translator->trans('Unexpected fields were submitted.', [], 'inventory')));
        }
    }

    public function failure(\Throwable $error, FormInterface $form, string $uncertain): int|Response
    {
        if ($error instanceof InventoryAccessDenied || $error instanceof DevicesNotFound) {
            return $this->accessFailure($error);
        }
        [$message, $status] = match (true) {
            $error instanceof DeviceEditConflict => [$error->getMessage(), 409],
            $error instanceof \JsonException, $error instanceof \InvalidArgumentException => ['Invalid device selection.', 422],
            default => [$uncertain, 502],
        };
        $form->addError(new FormError($this->translator->trans($message, [], 'inventory')));
        return $status;
    }

    private function accessFailure(InventoryAccessDenied|DevicesNotFound $error): Response
    {
        return $error instanceof InventoryAccessDenied
            ? $this->response('Access denied.', $error->unauthenticated ? 401 : 403)
            : $this->response('Selected devices were not found.', 404);
    }

    private function response(string $message, int $status): Response
    {
        return new Response($this->translator->trans($message, [], 'inventory'), $status, ['Cache-Control' => 'private, no-store']);
    }
}

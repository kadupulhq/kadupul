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
    /**
     * Binds the helper to the translator used for error messages.
     */
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * Loads the devices named in the request's "ids" query parameter for a confirmation page.
     * The callback runs before the ids are validated so that authorization comes first.
     *
     * @param callable $prepare Receives the raw ids and returns the devices to confirm.
     * @return array|Response The devices, the validated ids and the list filters to return to; or a 400, 401, 403 or 404 response.
     */
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

    /**
     * Decodes the submitted selection and checks that it covers exactly the ids that were confirmed.
     *
     * @param array $data Submitted form data containing a JSON "selection".
     * @param array $ids The ids shown on the confirmation page.
     * @throws \InvalidArgumentException When the selection is malformed or differs from $ids.
     * @throws \JsonException When the selection is not valid JSON.
     */
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

    /**
     * Adds a form error when the submission has fields the form does not define.
     */
    public function rejectExtraFields(FormInterface $form): void
    {
        if ($form->getExtraData() !== []) {
            $form->addError(new FormError($this->translator->trans('Unexpected fields were submitted.', [], 'inventory')));
        }
    }

    /**
     * Records a failed bulk operation on the form and picks the HTTP status for it.
     * Conflicts give 409, an invalid selection gives 422, and any other failure is reported as uncertain with 502. An access or not-found error returns a final response instead.
     *
     * @param string $uncertain Untranslated message shown when the outcome is unknown.
     * @return int|Response The status to render the form with, or a final response.
     */
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

    /**
     * Maps an access or not-found error to a private response: 401 or 403 for denied access, 404 for devices that were not found.
     */
    private function accessFailure(InventoryAccessDenied|DevicesNotFound $error): Response
    {
        return $error instanceof InventoryAccessDenied
            ? $this->response('Access denied.', $error->unauthenticated ? 401 : 403)
            : $this->response('Selected devices were not found.', 404);
    }

    /**
     * Builds a translated plain-text response that must not be cached.
     */
    private function response(string $message, int $status): Response
    {
        return new Response($this->translator->trans($message, [], 'inventory'), $status, ['Cache-Control' => 'private, no-store']);
    }
}

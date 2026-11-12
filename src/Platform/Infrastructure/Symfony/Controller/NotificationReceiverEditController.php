<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\NotificationReceiverAccess;
use Kadupul\Platform\Application\Port\NotificationReceiverStore;
use Kadupul\Platform\Application\Query\NotificationReceiverAccessDenied;
use Kadupul\Platform\Domain\NotificationReceiver;
use Kadupul\Platform\Domain\NotificationReceiverChange;
use Kadupul\Platform\Infrastructure\Symfony\Form\NotificationReceiverType;
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

/** The General tab of an SNMP notification receiver; Notifications and Logs remain in managers.php. */
final class NotificationReceiverEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/utilities/notification-receivers/new', name: 'notification_receiver_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, NotificationReceiverAccess $access, NotificationReceiverStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'notification_receiver'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $defaults = $store->defaults();
        } catch (NotificationReceiverAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'notification_receiver'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the notification receiver. Reload before retrying.', [], 'notification_receiver'), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, $defaults, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/utilities/notification-receivers/{id}/edit', name: 'notification_receiver_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, NotificationReceiverAccess $access, NotificationReceiverStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'notification_receiver'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $receiver = $store->find($id);
        } catch (NotificationReceiverAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'notification_receiver'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the notification receiver. Reload before retrying.', [], 'notification_receiver'), 502, self::HEADERS);
        }
        if ($receiver === null) {
            return new Response($translator->trans('Notification receiver not found.', [], 'notification_receiver'), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $receiver, $receiver->settings(), $request, $store, $forms, $twig, $urls, $translator);
    }

    /** @param array<string, bool|int|string> $settings */
    private function edit(int $actorId, ?NotificationReceiver $receiver, array $settings, Request $request, NotificationReceiverStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $secrets = array_keys(NotificationReceiverChange::CREDENTIALS + array_flip(NotificationReceiverType::CONFIRMATIONS));
        $initial = $settings + array_fill_keys($secrets, '') + ['revision' => $receiver->revision ?? ''];
        if ($receiver !== null) {
            $initial['keep_credentials'] = true;
        }
        $form = $forms->create(NotificationReceiverType::class, $initial, [
            'existing' => $receiver !== null,
            'action' => $receiver === null ? $urls->generate('notification_receiver_create') : $urls->generate('notification_receiver_edit', ['id' => $receiver->id]),
            'csrf_token_id' => $receiver === null ? 'notification_receiver_create' : 'notification_receiver_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'notification_receiver')));
            }
            $data = self::data($form);
            if ($form->get('snmp_port')->isValid() && !is_int($data['snmp_port'] ?? null)) {
                $form->get('snmp_port')->addError(new FormError($translator->trans('Enter a whole number.', [], 'notification_receiver')));
            }
            foreach (NotificationReceiverType::CONFIRMATIONS as $field => $confirmation) {
                if (($data[$field] ?? '') !== ($data[$confirmation] ?? '')) {
                    $form->get($confirmation)->addError(new FormError($translator->trans('The passphrases do not match.', [], 'notification_receiver')));
                }
            }
            if ($form->isValid()) {
                try {
                    $change = new NotificationReceiverChange(
                        array_intersect_key($data, array_flip(NotificationReceiverChange::SETTINGS)),
                        $receiver !== null && $data['keep_credentials'] === true,
                        array_intersect_key($data, NotificationReceiverChange::CREDENTIALS),
                    );
                    $saved = $store->save($actorId, $receiver?->id, $change, $receiver === null ? null : self::field($data, 'revision'));
                    // Legacy returned to the receiver after saving, so its other tabs open next.
                    return new RedirectResponse($urls->generate('notification_receiver_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
                } catch (NotificationReceiverAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'notification_receiver'), $error->unauthenticated ? 401 : 403, self::HEADERS);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'notification_receiver')));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'not found') ? 404 : 422);
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'notification_receiver')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('platform/notification_receiver_edit.html.twig', [
            'receiver' => $receiver,
            'form' => $form->createView(),
            // Read without InputBag::get(), which throws on arrays.
            'saved' => ($request->query->all()['saved'] ?? null) === '1',
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

    /** @param array<string, mixed> $data */
    private static function field(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}

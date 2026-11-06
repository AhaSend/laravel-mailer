<?php

declare(strict_types=1);

/*
 * This file is part of the ahasend/laravel-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AhaSend\Laravel\Http\Controllers;

use AhaSend\Laravel\Events;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches a Laravel event for each AhaSend webhook. An event type this
 * version does not know is still acknowledged and dispatched as
 * WebhookReceived, because AhaSend disables a webhook that keeps failing.
 */
final class WebhookController
{
    /** @var array<string, class-string<Events\WebhookEvent>> */
    public const EVENTS = [
        'message.reception' => Events\MessageReception::class,
        'message.delivered' => Events\MessageDelivered::class,
        'message.transient_error' => Events\MessageTransientError::class,
        'message.failed' => Events\MessageFailed::class,
        'message.bounced' => Events\MessageBounced::class,
        'message.suppressed' => Events\MessageSuppressed::class,
        'message.opened' => Events\MessageOpened::class,
        'message.clicked' => Events\MessageClicked::class,
        'message.unsubscribed' => Events\MessageUnsubscribed::class,
        'message.complained' => Events\MessageComplained::class,
        'message.routing' => Events\MessageRouting::class,
        'suppression.created' => Events\SuppressionCreated::class,
        'suppression.deleted' => Events\SuppressionDeleted::class,
        'domain.dns_error' => Events\DomainDnsError::class,
        'contact.created' => Events\ContactCreated::class,
        'contact.updated' => Events\ContactUpdated::class,
        'contact.deleted' => Events\ContactDeleted::class,
        'contact.added_to_list' => Events\ContactAddedToList::class,
        'contact.removed_from_list' => Events\ContactRemovedFromList::class,
        'contact.subscribed' => Events\ContactSubscribed::class,
        'contact.unsubscribed' => Events\ContactUnsubscribed::class,
        'contact.complained' => Events\ContactComplained::class,
    ];

    public function __invoke(Request $request, Dispatcher $events): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\is_string($payload['type'] ?? null)) {
            return new Response('Invalid payload', 400);
        }

        $headers = array_filter([
            'webhook-id' => $request->header('webhook-id'),
            'webhook-timestamp' => $request->header('webhook-timestamp'),
        ]);

        $event = self::EVENTS[$payload['type']] ?? null;
        if (null !== $event) {
            $events->dispatch(new $event($payload, $headers));
        }
        $events->dispatch(new Events\WebhookReceived($payload, $headers));

        return new Response('Webhook handled', 200);
    }
}

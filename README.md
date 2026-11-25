# AhaSend for Laravel

[![CI](https://github.com/AhaSend/laravel-mailer/actions/workflows/ci.yml/badge.svg)](https://github.com/AhaSend/laravel-mailer/actions/workflows/ci.yml)

[AhaSend](https://ahasend.com) for Laravel 11, 12 and 13:

- an **API v2** mail transport and an **SMTP** mail transport, so mailables,
  notifications, queued mail and `Mail::fake()` work as usual;
- **webhooks**: a route that verifies AhaSend's signatures and dispatches a
  Laravel event for each delivery, open, click, bounce and so on.

It uses AhaSend's Symfony Mailer bridge, since Laravel's mailer is built on Symfony
Mailer: `ahasend/symfony-mailer`, the official bridge with API v2 for every Symfony
version Laravel uses. That package replaces Symfony's `symfony/aha-send-mailer`, so
Composer removes `symfony/aha-send-mailer` if your app already has it.

## Requirements

- PHP 8.2 or newer (Laravel 13 needs PHP 8.3)
- Laravel 11, 12 or 13. Laravel 11 no longer receives security fixes, so Composer
  may refuse to install it unless you pass `--no-security-blocking`.
- For the API: an AhaSend API key (`aha-sk-...`) allowed to send from your domain,
  and your account ID. Both are in the AhaSend dashboard under *API Keys*.

## Installation

```sh
composer require ahasend/laravel-mailer
```

Select AhaSend in `.env`:

```env
MAIL_MAILER=ahasend
AHASEND_API_KEY=aha-sk-...
AHASEND_ACCOUNT_ID=your-account-id
MAIL_FROM_ADDRESS=hello@your-verified-domain.example
```

That's all: the package registers the `ahasend` mailer, so there is nothing to
add to `config/mail.php`. The From address's domain must be verified in AhaSend.

To change the package's settings, publish its configuration file:

```sh
php artisan vendor:publish --tag=ahasend-config
```

The key and account ID can also be `services.ahasend.key` and
`services.ahasend.account_id` in `config/services.php`, or set on a mailer.

### SMTP

To send through AhaSend's SMTP relay instead, with STARTTLS required:

```env
MAIL_MAILER=ahasend-smtp
AHASEND_SMTP_USERNAME=your-smtp-username
AHASEND_SMTP_PASSWORD=your-smtp-password
```

### Mailer settings

To use AhaSend next to another mailer, or with its own settings, add a mailer to
`config/mail.php`. Its settings win over the others:

```php
'mailers' => [
    'ahasend' => [
        'transport' => 'ahasend',
        'key' => env('AHASEND_API_KEY'),
        'account_id' => env('AHASEND_ACCOUNT_ID'),
        // optional Symfony HttpClient options, as for Laravel's other API mailers
        'client' => ['timeout' => 10],
    ],
],
```

```php
Mail::mailer('ahasend')->to($user)->send(new WelcomeMail($user));
```

Sending waits for AhaSend's answer, so queue mail sent during web requests
(`Mail::to($user)->queue(...)` or a `ShouldQueue` mailable).

## Tags, metadata and AhaSend options

Mailable tags become AhaSend tags; metadata is sent as `X-Metadata-*` headers:

```php
public function envelope(): Envelope
{
    return new Envelope(subject: 'Welcome!', tags: ['welcome'], metadata: ['user_id' => (string) $this->user->id]);
}
```

Other AhaSend options use AhaSend's
[SMTP special headers](https://ahasend.com/docs/smtp/special-headers), so the same
mailable works on both mailers:

```php
public function headers(): Headers
{
    return new Headers(text: [
        'AhaSend-Track-Opens' => 'true',
        'AhaSend-Sandbox' => 'true', // validate without delivering
    ]);
}
```

On the API mailer these are supported: `AhaSend-Tags`, `AhaSend-Track-Opens`,
`AhaSend-Track-Clicks`, `AhaSend-Sandbox`, `AhaSend-Sandbox-Result`,
`AhaSend-Metadata-Retention` and `AhaSend-Data-Retention`. A malformed value throws
before anything is sent, as does a retention outside AhaSend's limits: 1 to 30 days
for metadata, 0 to 30 for data.

The Message-ID AhaSend assigned is on the sent message:
`Mail::to($user)->send(new WelcomeMail())->getMessageId()`, or in a `MessageSent`
listener, `$event->sent->getMessageId()`. It is the `data.message_id_header` of
this message's webhooks, without its angle brackets.

## Webhooks

Create a webhook in AhaSend pointing at `https://your-app.example/ahasend/webhook`,
and set the secret it returns:

```env
AHASEND_WEBHOOK_SECRET=the-complete-secret-including-any-prefix
```

The route verifies the signature and the timestamp, then dispatches a Laravel
event. Without a configured secret, every request is refused.

```php
use AhaSend\Laravel\Events\MessageBounced;
use Illuminate\Support\Facades\Event;

Event::listen(function (MessageBounced $event) {
    $event->payload['data']['recipient'];         // the bounced address
    $event->payload['data']['message_id_header']; // the Message-ID of the message
    $event->headers['webhook-id'];                // use it to ignore a repeated delivery
});
```

All events are in `AhaSend\Laravel\Events`:

| AhaSend webhook | Event |
| --- | --- |
| `message.reception` | `MessageReception` |
| `message.delivered` | `MessageDelivered` |
| `message.transient_error` | `MessageTransientError` |
| `message.failed` | `MessageFailed` |
| `message.bounced` | `MessageBounced` |
| `message.suppressed` | `MessageSuppressed` |
| `message.opened` | `MessageOpened` |
| `message.clicked` | `MessageClicked` |
| `message.unsubscribed` | `MessageUnsubscribed` |
| `message.complained` | `MessageComplained` |
| `message.routing` | `MessageRouting` |
| `suppression.created`, `suppression.deleted` | `SuppressionCreated`, `SuppressionDeleted` |
| `domain.dns_error` | `DomainDnsError` |
| `contact.created`, `contact.updated`, `contact.deleted` | `ContactCreated`, `ContactUpdated`, `ContactDeleted` |
| `contact.added_to_list`, `contact.removed_from_list` | `ContactAddedToList`, `ContactRemovedFromList` |
| `contact.subscribed`, `contact.unsubscribed`, `contact.complained` | `ContactSubscribed`, `ContactUnsubscribed`, `ContactComplained` |
| Every webhook, including types added later | `WebhookReceived` |

Good to know:

- **Inbound routes** (`message.routing`) have their own secret. Set
  `AHASEND_ROUTE_SECRET` as well to send them to the same URL.
- **AhaSend retries** a delivery that does not answer 2xx, up to 6 times, and
  disables a webhook after 100 failed attempts in a row. Queue slow work in your
  listeners so the route answers quickly.
- **Deliveries can repeat and arrive out of order.** Use `webhook-id` to ignore
  repeats and `$event->payload['timestamp']` to order them.

### Configuration

In `config/ahasend.php` (or the matching `AHASEND_*` variables):

| Key | Default | Purpose |
| --- | --- | --- |
| `routes` | `true` | Set to `false` to handle webhooks yourself |
| `path` | `ahasend` | The route is `POST /{path}/webhook` |
| `domain` | none | Serve the route from this domain only |
| `middleware` | `[]` | Extra middleware for the route |
| `webhook.secret` | none | The webhook's secret |
| `webhook.route_secret` | none | The inbound route's secret |
| `webhook.tolerance` | `300` | Largest allowed difference, in seconds, from the signed timestamp |

To use your own route, add the same middleware:

```php
use AhaSend\Laravel\Http\Controllers\WebhookController;
use AhaSend\Laravel\Http\Middleware\VerifyWebhookSignature;

Route::post('/hooks/ahasend', WebhookController::class)->middleware(VerifyWebhookSignature::class);
```

## Recipients

On the API mailer, a message with To addresses is sent as one message to its To, Cc
and Bcc addresses, so recipients see each other and reply-all works. Bcc addresses
are never shown. With more than 50 addresses, or only Bcc addresses, each address
receives its own copy instead. Each copy then has its own Message-ID, and
`getMessageId()` on the sent message is the one of the first address AhaSend
accepted, so only that address's webhooks match it.

## Errors and rejected recipients

API errors (an unverified sender domain, an invalid key, a paused domain, ...)
throw `Symfony\Component\Mailer\Exception\HttpTransportException` with AhaSend's
message, as Laravel's other API mailers do.

AhaSend can reject a recipient while accepting the others, for example for a
blocked recipient domain. That is not an exception, even when every recipient is
rejected: a retried job would send the accepted recipients a second copy. Each
rejected recipient is logged and reported as an event:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;

Event::listen(function (AhaSendDeliveryEvent $event) {
    Log::warning('AhaSend rejected a recipient: '.$event->getMessage());
});
```

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

MIT

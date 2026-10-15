# AhaSend mail transport for Laravel

[![CI](https://github.com/AhaSend/laravel-mailer/actions/workflows/ci.yml/badge.svg)](https://github.com/AhaSend/laravel-mailer/actions/workflows/ci.yml)

Send Laravel mail through the [AhaSend](https://ahasend.com) **API v2** on
Laravel 11, 12 and 13. Mailables, notifications, queued mail and `Mail::fake()`
keep working as usual.

It uses [`ahasend/symfony-mailer`](https://github.com/AhaSend/symfony-mailer),
the API v2 transport for Symfony Mailer, which is what Laravel's mailer is
built on.

## Requirements

- PHP 8.2 or newer (Laravel 13 needs PHP 8.3)
- Laravel 11, 12 or 13. Laravel 11 no longer receives security fixes;
  Composer may refuse to install it unless you pass `--no-security-blocking`.
- An AhaSend API key (`aha-sk-...`) allowed to send from your domain, and your
  account ID. Both are in the AhaSend dashboard under *API Keys*.

## Installation

```sh
composer require ahasend/laravel-mailer
```

Add AhaSend to `config/services.php`:

```php
'ahasend' => [
    'key' => env('AHASEND_API_KEY'),
    'account_id' => env('AHASEND_ACCOUNT_ID'),
],
```

and select it in `.env`:

```env
MAIL_MAILER=ahasend
AHASEND_API_KEY=aha-sk-...
AHASEND_ACCOUNT_ID=your-account-id
MAIL_FROM_ADDRESS=hello@your-verified-domain.example
```

That's all: the package registers the `ahasend` mailer, so there is nothing
to add to `config/mail.php`. The From address's domain must be verified in
AhaSend.

To use AhaSend next to another mailer, or with its own settings, add a mailer
to `config/mail.php`. Its settings win over `config/services.php`:

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
[SMTP special headers](https://ahasend.com/docs/smtp/special-headers), so an
app moving from AhaSend's SMTP relay keeps working:

```php
public function headers(): Headers
{
    return new Headers(text: [
        'AhaSend-Track-Opens' => 'true',
        'AhaSend-Sandbox' => 'true', // validate without delivering
    ]);
}
```

Supported: `AhaSend-Tags`, `AhaSend-Track-Opens`, `AhaSend-Track-Clicks`,
`AhaSend-Sandbox`, `AhaSend-Sandbox-Result`, `AhaSend-Metadata-Retention`,
`AhaSend-Data-Retention`. A malformed value or an unknown `AhaSend-*` header
throws before anything is sent.

## Recipients

| The mail has | Result |
| --- | --- |
| To only | Each To address receives its own copy. |
| To plus Cc and/or Bcc | One message with the To and Cc headers; Bcc addresses receive it without appearing in any header. |
| Bcc only | Each Bcc address receives its own copy. |
| Cc but no To | An `InvalidArgumentException`; AhaSend needs a To address alongside Cc. |
| More than 100 recipients, or more than 50 To + Cc + Bcc with Cc/Bcc | An `InvalidArgumentException` before sending. Send in batches. |

## Errors and partial delivery

API errors (an unverified sender domain, an invalid key, a paused domain, ...)
throw `Symfony\Component\Mailer\Exception\HttpTransportException` with
AhaSend's message, as Laravel's other API mailers do.

AhaSend can accept some recipients of a message and reject others. That is
not an error, because the accepted recipients are being sent and a retried job
would send them a second copy. Each rejected recipient is reported as an
event instead:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;

Event::listen(function (AhaSendDeliveryEvent $event) {
    Log::warning('AhaSend rejected a recipient: '.$event->getMessage());
});
```

When every recipient is rejected, nothing was sent and the send throws.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Until `ahasend/symfony-mailer` is on Packagist, Composer installs it from a
`../ahasend-symfony-mailer` checkout (see `repositories` in `composer.json`).

## License

MIT

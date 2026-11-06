# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First release (1.0.0).

### Added

- `ahasend` mail transport for Laravel 11, 12 and 13, sending through the AhaSend
  API v2 with `ahasend/symfony-mailer`.
- `ahasend-smtp` mail transport, sending through AhaSend's SMTP relay with
  STARTTLS required.
- Webhooks: a `POST /ahasend/webhook` route with signature and timestamp checks,
  refusing every request until a secret is configured, and a Laravel event for
  each AhaSend webhook type, plus `WebhookReceived` for all of them.
- Auto-discovered service provider and a publishable `config/ahasend.php`, with
  credentials also read from `config/services.php` and per-mailer settings;
  `MAIL_MAILER=ahasend` works without editing `config/mail.php`.
- Mailable tags and metadata, and AhaSend's SMTP special headers as options.
- Rejected recipients dispatched as Laravel events (`AhaSendDeliveryEvent`).

[Unreleased]: https://github.com/AhaSend/laravel-mailer/commits/main

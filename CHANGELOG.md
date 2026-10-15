# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First release (1.0.0).

### Added

- `ahasend` mail transport for Laravel 11, 12 and 13, sending through the
  AhaSend API v2 with `ahasend/symfony-mailer`.
- Auto-discovered service provider; credentials from `config/services.php`
  (`AHASEND_API_KEY`, `AHASEND_ACCOUNT_ID`), with per-mailer overrides and
  HttpClient `client` options; `MAIL_MAILER=ahasend` works without editing
  `config/mail.php`.
- Mailable tags and metadata, and AhaSend's SMTP special headers as options.
- Rejected recipients of a partial delivery dispatched as Laravel events
  (`AhaSendDeliveryEvent`).

[Unreleased]: https://github.com/AhaSend/laravel-mailer/commits/main

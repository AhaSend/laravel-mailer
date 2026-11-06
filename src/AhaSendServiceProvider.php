<?php

declare(strict_types=1);

/*
 * This file is part of the ahasend/laravel-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AhaSend\Laravel;

use AhaSend\SymfonyMailer\Transport\AhaSendTransportFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendSmtpTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adds the `ahasend` mail transport (AhaSend API v2), the `ahasend-smtp` mail
 * transport (AhaSend's SMTP relay), and the webhook route.
 *
 *     // .env
 *     MAIL_MAILER=ahasend
 *     AHASEND_API_KEY=aha-sk-...
 *     AHASEND_ACCOUNT_ID=...
 */
final class AhaSendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ahasend.php', 'ahasend');

        // Lets MAIL_MAILER select them without an entry in config/mail.php.
        $config = $this->app['config'];
        foreach (['ahasend', 'ahasend-smtp'] as $transport) {
            if (!$config->has("mail.mailers.{$transport}")) {
                $config->set("mail.mailers.{$transport}", ['transport' => $transport]);
            }
        }
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('ahasend', fn (array $config): TransportInterface => $this->createApiTransport($config));
            $manager->extend('ahasend-smtp', fn (array $config): TransportInterface => $this->createSmtpTransport($config));
        });

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/ahasend.php' => $this->app->configPath('ahasend.php')], 'ahasend-config');
        }
    }

    private function registerRoutes(): void
    {
        if (!config('ahasend.routes') || $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('ahasend.domain'),
            'prefix' => config('ahasend.path'),
            'as' => 'ahasend.',
            'middleware' => config('ahasend.middleware', []),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * @param array<string, mixed> $config the mailer's entry in config/mail.php
     */
    private function createApiTransport(array $config): TransportInterface
    {
        // A mailer's own settings win over config/ahasend.php and config/services.php.
        $settings = array_merge(
            array_filter((array) $this->app['config']->get('services.ahasend', []), self::isSet(...)),
            array_filter(['key' => config('ahasend.api_key'), 'account_id' => config('ahasend.account_id')], self::isSet(...)),
            array_filter($config, self::isSet(...)),
        );

        if (blank($settings['key'] ?? null) || blank($settings['account_id'] ?? null)) {
            throw new \InvalidArgumentException('The AhaSend mailer needs an API key and an account ID: set AHASEND_API_KEY and AHASEND_ACCOUNT_ID.');
        }

        $dsn = new Dsn('ahasend+api', (string) ($settings['host'] ?? 'default'), (string) $settings['key'], (string) $settings['account_id'], isset($settings['port']) ? (int) $settings['port'] : null);

        return (new AhaSendTransportFactory($this->deliveryEvents(), $this->httpClient($settings)))->create($dsn);
    }

    /**
     * @param array<string, mixed> $config the mailer's entry in config/mail.php
     */
    private function createSmtpTransport(array $config): TransportInterface
    {
        $username = $config['username'] ?? config('ahasend.smtp.username');
        $password = $config['password'] ?? config('ahasend.smtp.password');

        if (blank($username) || blank($password)) {
            throw new \InvalidArgumentException('The AhaSend SMTP mailer needs SMTP credentials: set AHASEND_SMTP_USERNAME and AHASEND_SMTP_PASSWORD.');
        }

        return (new AhaSendSmtpTransport((string) $username, (string) $password))->setRequireTls(true);
    }

    private static function isSet(mixed $value): bool
    {
        return null !== $value && '' !== $value;
    }

    /**
     * An HttpClientInterface bound in the container wins (handy for proxies
     * and tests); otherwise the `client` options, as Laravel's own HTTP
     * transports read them; otherwise the transport's default client.
     *
     * @param array<string, mixed> $settings
     */
    private function httpClient(array $settings): ?HttpClientInterface
    {
        if ($this->app->bound(HttpClientInterface::class)) {
            return $this->app->make(HttpClientInterface::class);
        }

        if ($options = ($settings['client'] ?? false)) {
            $maxHostConnections = Arr::pull($options, 'max_host_connections', 6);
            $maxPendingPushes = Arr::pull($options, 'max_pending_pushes', 50);

            return HttpClient::create($options, $maxHostConnections, $maxPendingPushes);
        }

        return null;
    }

    /**
     * Forwards AhaSendDeliveryEvent (a recipient AhaSend rejected) to Laravel's
     * event dispatcher, so applications can listen for it with Event::listen().
     */
    private function deliveryEvents(): EventDispatcherInterface
    {
        $events = $this->app->make(Dispatcher::class);

        return new class($events) implements EventDispatcherInterface {
            public function __construct(private readonly Dispatcher $events)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof AhaSendDeliveryEvent) {
                    $this->events->dispatch($event);
                }

                return $event;
            }
        };
    }
}

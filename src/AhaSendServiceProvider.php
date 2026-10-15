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
use Illuminate\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adds the `ahasend` mail transport, which sends through the AhaSend API v2.
 *
 *     // config/services.php
 *     'ahasend' => [
 *         'key' => env('AHASEND_API_KEY'),
 *         'account_id' => env('AHASEND_ACCOUNT_ID'),
 *     ],
 *
 *     // .env
 *     MAIL_MAILER=ahasend
 */
final class AhaSendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lets MAIL_MAILER=ahasend work without a mailer entry in config/mail.php.
        $config = $this->app['config'];
        if (!$config->has('mail.mailers.ahasend')) {
            $config->set('mail.mailers.ahasend', ['transport' => 'ahasend']);
        }
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('ahasend', fn (array $config): TransportInterface => $this->createTransport($config));
        });
    }

    /**
     * @param array<string, mixed> $config the mailer's entry in config/mail.php
     */
    private function createTransport(array $config): TransportInterface
    {
        // A mailer's own settings win over config/services.php.
        $settings = array_merge((array) $this->app['config']->get('services.ahasend', []), array_filter($config, static fn (mixed $value): bool => null !== $value && '' !== $value));

        if (blank($settings['key'] ?? null) || blank($settings['account_id'] ?? null)) {
            throw new \InvalidArgumentException('The AhaSend mailer needs an API key and an account ID: set AHASEND_API_KEY and AHASEND_ACCOUNT_ID, read by config/services.php (services.ahasend.key and services.ahasend.account_id).');
        }

        $dsn = new Dsn('ahasend+api', (string) ($settings['host'] ?? 'default'), (string) $settings['key'], (string) $settings['account_id'], isset($settings['port']) ? (int) $settings['port'] : null);

        return (new AhaSendTransportFactory($this->deliveryEvents(), $this->httpClient($settings)))->create($dsn);
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
     * Forwards AhaSendDeliveryEvent (a recipient AhaSend rejected while the
     * others were sent) to Laravel's event dispatcher, so applications can
     * listen for it with Event::listen().
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

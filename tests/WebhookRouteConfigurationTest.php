<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests;

use AhaSend\Laravel\AhaSendServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class WebhookRouteConfigurationTest extends TestCase
{
    protected function withoutRoutes($app): void
    {
        $app['config']->set('ahasend.routes', false);
    }

    protected function withCustomRoute($app): void
    {
        $app['config']->set('ahasend.path', 'hooks/mail');
        $app['config']->set('ahasend.domain', 'hooks.example.test');
        $app['config']->set('ahasend.middleware', ['throttle:60,1']);
    }

    public function testTheRouteIsRegisteredByDefault(): void
    {
        $route = Route::getRoutes()->getByName('ahasend.webhook');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('ahasend/webhook', $route->uri());
    }

    #[DefineEnvironment('withoutRoutes')]
    public function testTheRouteCanBeDisabled(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('ahasend.webhook'));
    }

    #[DefineEnvironment('withCustomRoute')]
    public function testThePathDomainAndMiddlewareCanBeConfigured(): void
    {
        $route = Route::getRoutes()->getByName('ahasend.webhook');

        $this->assertSame('hooks/mail/webhook', $route->uri());
        $this->assertSame('hooks.example.test', $route->getDomain());
        $this->assertContains('throttle:60,1', $route->middleware());
    }

    public function testTheConfigurationCanBePublished(): void
    {
        $paths = ServiceProvider::pathsToPublish(AhaSendServiceProvider::class, 'ahasend-config');

        $this->assertCount(1, $paths);
        $this->assertSame(realpath(__DIR__.'/../config/ahasend.php'), realpath((string) array_key_first($paths)));
    }
}

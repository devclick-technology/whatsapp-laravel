<?php

namespace DevClick\WhatsApp\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionExceptionHandler;

class CollisionCompatibilityTest extends WhatsAppTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->extend(ExceptionHandler::class, fn (ExceptionHandler $handler): CollisionExceptionHandler => new CollisionExceptionHandler($app, $handler));
    }

    public function test_package_discovery_boots_with_the_collision_handler(): void
    {
        $this->assertInstanceOf(CollisionExceptionHandler::class, $this->app->make(ExceptionHandler::class));
        $this->artisan('package:discover')->assertExitCode(0);
    }
}

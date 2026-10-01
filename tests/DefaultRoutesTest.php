<?php

namespace DevClick\WhatsApp\Tests;

use DevClick\WhatsApp\WhatsAppServiceProvider;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class DefaultRoutesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsAppServiceProvider::class];
    }

    public function test_generic_send_route_is_disabled_by_default(): void
    {
        $this->assertTrue(Route::has('whatsapp.connect'));
        $this->assertTrue(Route::has('whatsapp.qr'));
        $this->assertFalse(Route::has('whatsapp.send-message'));
    }
}

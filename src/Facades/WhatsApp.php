<?php

namespace DevClick\WhatsApp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \DevClick\WhatsApp\UserSession forUser(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \DevClick\WhatsApp\UserSession forUserId(string $userId)
 */
class WhatsApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DevClick\WhatsApp\WhatsApp::class;
    }
}

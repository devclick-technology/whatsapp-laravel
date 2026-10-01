<?php

namespace DevClick\WhatsApp;

use Illuminate\Contracts\Auth\Authenticatable;

class WhatsApp
{
    public function __construct(private WhatsAppClient $client) {}

    public function forUser(Authenticatable $user): UserSession
    {
        return $this->forUserId((string) $user->getAuthIdentifier());
    }

    /** Only pass a trusted server-side ID, never browser input. */
    public function forUserId(string $userId): UserSession
    {
        return new UserSession($this->client, $userId);
    }
}

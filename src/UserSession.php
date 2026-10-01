<?php

namespace DevClick\WhatsApp;

use Illuminate\Support\Facades\Validator;

class UserSession
{
    private string $path;

    public function __construct(private WhatsAppClient $client, private string $userId)
    {
        if ($userId === '' || strlen($userId) > 256 || ! mb_check_encoding($userId, 'UTF-8')) {
            throw new WhatsAppException('INVALID_INPUT', 'Invalid WhatsApp user identifier.', 422);
        }
        $this->path = '/api/whatsapp/'.str_replace('.', '%2E', rawurlencode($userId));
    }

    /** @return array<string, mixed> */
    public function connect(): array
    {
        return $this->client->request('POST', '/api/whatsapp/connect', ['user_id' => $this->userId]);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->client->request('GET', $this->path.'/status');
    }

    /** @return array<string, mixed> */
    public function qr(): array
    {
        return $this->client->request('GET', $this->path.'/qr');
    }

    /** Logs out and removes the persisted Node session. @return array<string, mixed> */
    public function disconnect(): array
    {
        return $this->client->request('POST', $this->path.'/disconnect');
    }

    /** @return array<string, mixed> */
    public function sendText(string $phone, string $message): array
    {
        $validator = Validator::make(compact('phone', 'message'), [
            'phone' => ['required', 'string', 'regex:/^[1-9][0-9]{6,14}$/D'],
            'message' => ['required', 'string', 'max:4096', 'regex:/[^\s\p{Z}\x{FEFF}]/u'],
        ]);
        if ($validator->fails()) {
            throw new WhatsAppException('INVALID_INPUT', 'Use 7–15 international digits and nonblank text up to 4,096 characters.', 422);
        }

        return $this->client->request('POST', $this->path.'/send-message', compact('phone', 'message'));
    }
}

<?php

namespace DevClick\WhatsApp;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class WhatsAppClient
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $url = config('whatsapp.node_url');
        $token = config('whatsapp.node_token');
        $appId = config('whatsapp.app_id');
        $timeout = filter_var(config('whatsapp.timeout'), FILTER_VALIDATE_INT);
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ! filter_var($url, FILTER_VALIDATE_URL) || ! isset($parts['host'], $parts['scheme'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (config('whatsapp.require_https') && $parts['scheme'] !== 'https')
            || ! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)
            || ! is_string($appId) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $appId)
            || $timeout === false || $timeout < 1 || $timeout > 120) {
            throw new WhatsAppException('CONFIGURATION_ERROR', 'WhatsApp is not configured. Contact your administrator.', 503);
        }

        $sending = str_ends_with($path, '/send-message');
        try {
            $response = Http::baseUrl(rtrim($url, '/'))->withToken($token)
                ->withHeaders(['X-WhatsApp-App-Id' => $appId])->acceptJson()->asJson()
                ->connectTimeout(3)->timeout($timeout)->withoutRedirecting()
                ->send($method, $path, $method === 'GET' ? [] : ['json' => $data]);
        } catch (ConnectionException $exception) {
            $timedOut = str_contains($exception->getMessage(), 'cURL error 28');
            throw new WhatsAppException($timedOut ? 'TIMEOUT' : 'UNAVAILABLE',
                $sending ? 'Message submission outcome is unknown. Check before resubmitting; do not automatically retry.'
                    : ($timedOut ? 'WhatsApp status is unavailable because the service timed out.' : 'WhatsApp status is unavailable. Please retry later.'),
                $timedOut ? 504 : 503);
        }

        $payload = $response->json();
        if (in_array($response->status(), [401, 403], true)) {
            throw $this->invalidResponse();
        }
        if ($response->serverError() && (! is_array($payload) || ($payload['code'] ?? null) !== 'SEND_FAILED')) {
            throw new WhatsAppException('UNAVAILABLE', $sending ? 'Submission could not be confirmed. Check WhatsApp before sending again.' : 'WhatsApp status is unavailable. Please retry later.', 503);
        }
        if (! is_array($payload) || ! isset($payload['success']) || ! is_bool($payload['success'])) {
            throw $this->invalidResponse();
        }
        if ($payload['success'] === false) {
            $errors = [
                'SESSION_DISCONNECTED' => [409, 'Your WhatsApp session is disconnected. Connect again.'],
                'NOT_CONNECTED' => [409, 'WhatsApp is not ready yet. Check your connection before sending.'],
                'SEND_IN_PROGRESS' => [409, 'The previous submission is still processing. Check WhatsApp before sending again.'],
                'RATE_LIMITED' => [429, 'Too many WhatsApp requests. Wait before trying again.'],
                'INVALID_INPUT' => [422, 'The WhatsApp request is invalid. Check the recipient and text.'],
                'QR_EXPIRED' => [409, 'The QR code expired. Refresh to get a new code.'],
                'SEND_FAILED' => [502, 'Submission could not be confirmed. Check before resubmitting; do not automatically retry.'],
            ];
            $code = $payload['code'] ?? null;
            if (! is_string($code) || ! isset($errors[$code]) || $response->status() !== $errors[$code][0]) {
                throw $this->invalidResponse();
            }
            throw new WhatsAppException($code, $errors[$code][1], $errors[$code][0]);
        }
        if (! $response->successful()) {
            throw $this->invalidResponse();
        }
        if ($sending) {
            if (array_key_exists('message_id', $payload) && $payload['message_id'] === null && ($payload['confirmation'] ?? null) === 'client_completed') {
                return ['success' => true, 'message_id' => null, 'confirmation' => 'client_completed'];
            }
            if (! is_string($payload['message_id'] ?? null) || trim($payload['message_id']) === '') {
                throw $this->invalidResponse();
            }

            return ['success' => true, 'message_id' => $payload['message_id']];
        }
        $status = $payload['status'] ?? null;
        $phone = $payload['phone'] ?? null;
        if (! in_array($status, ['disconnected', 'connecting', 'qr_required', 'connected'], true)
            || ! array_key_exists('phone', $payload)
            || ($phone !== null && (! is_string($phone) || ! preg_match('/^[1-9][0-9]{6,14}$/D', $phone)))
            || ($status === 'connected' && $phone === null)
            || ($status !== 'connected' && $phone !== null)) {
            throw $this->invalidResponse();
        }
        $result = ['success' => true, 'status' => $status, 'phone' => $status === 'connected' ? $phone : null];
        if (str_ends_with($path, '/qr')) {
            $result += ['qr' => null, 'expires_at' => null];
            if ($status === 'qr_required') {
                $qr = $payload['qr'] ?? null;
                $expiry = $payload['expires_at'] ?? null;
                if (! is_string($qr) || strlen($qr) > 1048576
                    || ! preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#D', $qr, $matches)
                    || base64_encode((string) base64_decode($matches[1], true)) !== $matches[1]
                    || ! str_starts_with((string) base64_decode($matches[1], true), "\x89PNG\r\n\x1a\n")
                    || ! is_string($expiry) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expiry)) {
                    throw $this->invalidResponse();
                }
                try {
                    $expiresAt = new DateTimeImmutable($expiry);
                } catch (Throwable) {
                    throw $this->invalidResponse();
                }
                if ($expiresAt->getTimestamp() <= now()->getTimestamp()) {
                    throw new WhatsAppException('QR_EXPIRED', 'The QR code expired. Refresh to get a new code.', 409);
                }
                $result['qr'] = $qr;
                $result['expires_at'] = $expiry;
            }
        }

        return $result;
    }

    private function invalidResponse(): WhatsAppException
    {
        return new WhatsAppException('INVALID_RESPONSE', 'The WhatsApp service returned an invalid response. Please retry later.', 502);
    }
}

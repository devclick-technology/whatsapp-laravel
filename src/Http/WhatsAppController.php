<?php

namespace DevClick\WhatsApp\Http;

use DevClick\WhatsApp\WhatsApp;
use Illuminate\Http\JsonResponse;

class WhatsAppController
{
    public function __construct(private WhatsApp $whatsapp) {}

    public function connect(WhatsAppRequest $request): JsonResponse
    {
        return response()->json($this->whatsapp->forUser($request->user())->connect());
    }

    public function status(WhatsAppRequest $request): JsonResponse
    {
        return response()->json($this->whatsapp->forUser($request->user())->status());
    }

    public function qr(WhatsAppRequest $request): JsonResponse
    {
        return response()->json($this->whatsapp->forUser($request->user())->qr());
    }

    public function disconnect(WhatsAppRequest $request): JsonResponse
    {
        return response()->json($this->whatsapp->forUser($request->user())->disconnect());
    }

    public function sendText(SendTextRequest $request): JsonResponse
    {
        return response()->json($this->whatsapp->forUser($request->user())->sendText($request->validated('phone'), $request->validated('message')));
    }
}

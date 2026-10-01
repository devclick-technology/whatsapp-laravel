<?php

namespace DevClick\WhatsApp;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class WhatsAppException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $this->errorCode, 'message' => $this->getMessage()], $this->httpStatus)->header('Cache-Control', 'no-store');
    }
}

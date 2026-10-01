<?php

namespace DevClick\WhatsApp\Http;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_fill_keys(['user_id', 'app_id', 'application_id', 'token', 'media', 'image', 'format'], ['missing']);
    }
}

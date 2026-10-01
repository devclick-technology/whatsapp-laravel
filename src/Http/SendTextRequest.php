<?php

namespace DevClick\WhatsApp\Http;

class SendTextRequest extends WhatsAppRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'phone' => ['required', 'string', 'regex:/^[1-9][0-9]{6,14}$/D'],
            'message' => ['required', 'string', 'max:4096', 'regex:/[^\s\p{Z}\x{FEFF}]/u'],
        ];
    }
}

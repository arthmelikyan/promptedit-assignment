<?php

namespace App\Http\Requests;

use App\Data\WebhookData;
use Illuminate\Foundation\Http\FormRequest;

class PixelforgeWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:255', 'starts_with:evt_'],
            'type' => ['required', 'string', 'max:100'],
            'data' => ['required_unless:type,ping', 'array'],
            'data.object' => [
                'required_unless:type,ping',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || ! is_string($value['id'] ?? null) || ! str_starts_with($value['id'], 'gen_')) {
                        $fail('A valid provider generation is required.');
                    }
                },
            ],
        ];
    }

    public function toData(): WebhookData
    {
        return new WebhookData(
            id: $this->validated('id'),
            type: $this->validated('type'),
            payload: $this->json()->all(),
        );
    }
}

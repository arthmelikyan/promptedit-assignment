<?php

namespace App\Http\Requests;

use App\Data\PurchaseCreditsData;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
        ];
    }

    public function toData(): PurchaseCreditsData
    {
        return new PurchaseCreditsData(
            requestId: $this->validated('request_id'),
        );
    }
}

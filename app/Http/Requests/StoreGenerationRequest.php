<?php

namespace App\Http\Requests;

class StoreGenerationRequest extends EstimateGenerationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'request_id' => ['required', 'uuid'],
        ];
    }

    public function requestId(): string
    {
        return $this->validated('request_id');
    }
}

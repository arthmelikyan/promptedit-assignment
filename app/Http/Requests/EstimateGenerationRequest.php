<?php

namespace App\Http\Requests;

use App\Data\GenerationData;
use App\Enums\GenerationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(GenerationType::class)],
            'prompt' => ['required', 'string', 'max:4000'],
            'settings' => ['required', 'array:size,quality,reference_images'],
            'settings.size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'settings.quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'settings.reference_images' => ['sometimes', 'array', 'list', 'max:4'],
            'settings.reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function toData(): GenerationData
    {
        return new GenerationData(
            prompt: $this->validated('prompt'),
            size: $this->validated('settings.size'),
            quality: $this->validated('settings.quality'),
            referenceImages: $this->validated('settings.reference_images', []),
            type: GenerationType::from($this->validated('type')),
        );
    }
}

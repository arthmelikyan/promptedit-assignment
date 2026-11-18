<?php

namespace App\Data;

use App\Contracts\DataTransferObject;
use App\Enums\GenerationType;

final readonly class GenerationData implements DataTransferObject
{
    /** @param list<string> $referenceImages */
    public function __construct(
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImages = [],
        public GenerationType $type = GenerationType::Image,
    ) {}

    /** @return array{type: string, prompt: string, settings: array{size: string, quality: string, reference_images: list<string>}} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'prompt' => $this->prompt,
            'settings' => [
                'size' => $this->size,
                'quality' => $this->quality,
                'reference_images' => $this->referenceImages,
            ],
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}

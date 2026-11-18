<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'test'), 'credential_fingerprint' => hash('sha256', (string) config('services.pixelforge.key')), 'prompt' => 'A lighthouse at dawn', 'settings' => ['size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/style.png']], 'pricing' => [], 'estimated_min' => 300_000_000_000, 'estimated_max' => 500_000_000_000];
    }
}

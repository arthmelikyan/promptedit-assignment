<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'type' => $this->type->value,
            'settings' => $this->settings,
            'status' => $this->status->value,
            'submission_state' => $this->submission_state->value,
            'estimated_min' => (string) $this->estimated_min,
            'estimated_max' => (string) $this->estimated_max,
            'actual_charge' => $this->charge !== null ? (string) $this->charge->user_charge : ($this->status === GenerationStatus::Failed ? '0' : null),
            'usage' => $this->usage,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'outputs' => $this->outputs->map(fn ($output): array => [
                'id' => $output->id,
                'position' => $output->position,
                'media_type' => $output->media_type,
                'url' => $output->url,
                'expires_at' => $output->expires_at?->toIso8601String(),
                'expired' => $output->expires_at?->isPast() ?? false,
            ])->all(),
        ];
    }
}

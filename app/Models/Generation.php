<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\SubmissionState;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $prompt
 * @property string $input_hash
 * @property string $credential_fingerprint
 * @property int $submission_attempts
 * @property SubmissionState $submission_state
 * @property GenerationType $type
 * @property string|null $error_code
 * @property string|null $error_message
 * @property GenerationStatus $status
 * @property array{size: string, quality: string, reference_images: list<string>} $settings
 * @property array<string, mixed> $pricing
 * @property array<string, mixed>|null $rates
 * @property array<string, mixed>|null $usage
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
class Generation extends Model
{
    protected $guarded = [];

    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => GenerationType::class,
            'submission_state' => SubmissionState::class,
            'settings' => 'array',
            'pricing' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min' => 'integer',
            'estimated_max' => 'integer',
            'submission_attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'status' => GenerationStatus::class,
        ];
    }

    public function needsSubmission(): bool
    {
        return blank($this->provider_id) && ! $this->status->isTerminal();
    }

    /** @return HasMany<GenerationOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(GenerationOutput::class)->orderBy('position');
    }

    /** @return HasOne<GenerationCharge, $this> */
    public function charge(): HasOne
    {
        return $this->hasOne(GenerationCharge::class);
    }
}

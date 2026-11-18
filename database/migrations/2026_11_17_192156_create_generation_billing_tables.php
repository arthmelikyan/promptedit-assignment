<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->timestamps();
        });
        Schema::create('generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('input_hash', 64);
            $table->string('type')->default('image');
            $table->string('provider')->default('pixelforge');
            $table->string('credential_fingerprint', 64)->index();
            $table->string('provider_id')->nullable()->unique();
            $table->text('prompt');
            $table->jsonb('settings');
            $table->jsonb('pricing');
            $table->jsonb('rates')->nullable();
            $table->jsonb('usage')->nullable();
            $table->bigInteger('estimated_min');
            $table->bigInteger('estimated_max');
            $table->string('status')->default('queued');
            $table->string('submission_state')->default('pending');
            $table->unsignedInteger('submission_attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('generation_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('generation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('media_type');
            $table->text('url');
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['generation_id', 'position']);
        });
        Schema::create('generation_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('generation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('status')->default('held');
            $table->timestampsTz();
        });
        Schema::create('generation_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('generation_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('provider_cost');
            $table->bigInteger('user_charge');
            $table->unsignedInteger('markup_percent')->default(25);
            $table->timestampTz('completed_at')->index();
            $table->timestampsTz();
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('generation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('type');
            $table->bigInteger('amount');
            $table->timestampsTz();
            $table->unique(['wallet_id', 'request_id']);
        });
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id')->unique();
            $table->string('credential_fingerprint', 64);
            $table->string('type');
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'wallet_transactions', 'generation_charges', 'generation_reservations', 'generation_outputs', 'generations', 'wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

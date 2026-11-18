<?php

namespace App\Services;

use App\Contracts\GenerationProvider;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

final class PixelforgeClient implements GenerationProvider
{
    public function fingerprint(): string
    {
        return hash('sha256', (string) config('services.pixelforge.key'));
    }

    /** @return array<string, mixed> */
    public function pricing(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget('pixelforge.pricing.'.hash('sha256', (string) config('services.pixelforge.url')));
        }

        return Cache::remember('pixelforge.pricing.'.hash('sha256', (string) config('services.pixelforge.url')), 60, function (): array {
            $data = $this->request('GET', '/pricing', authenticated: false);
            if (($data['currency'] ?? '') !== 'usd' || ($data['unit'] ?? '') !== 'per_1m_tokens' || ! is_array($data['rates'] ?? null) || ! is_array($data['tokens'] ?? null)) {
                throw new ProviderException('invalid_response', 'Pricing is temporarily unavailable. Please try again.', true);
            }

            $rules = [
                'tokens.characters_per_text_token' => 'required|integer|min:1',
                'tokens.text_token_variance' => 'required|numeric|between:0,1',
                'tokens.per_reference_image' => 'required|integer|min:0',
            ];
            foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
                $rules['rates.'.$type] = ['required', 'string', 'regex:/^\d+(?:\.\d{1,12})?$/D'];
            }
            foreach (['low', 'medium', 'high'] as $quality) {
                foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                    $rules['tokens.output.'.$quality.'.'.$size] = 'required|integer|min:0';
                }
                $rules['tokens.reasoning.'.$quality.'.min'] = 'required|integer|min:0';
                $rules['tokens.reasoning.'.$quality.'.max'] = 'required|integer|gte:tokens.reasoning.'.$quality.'.min';
            }
            if (Validator::make($data, $rules)->fails()) {
                throw new ProviderException('invalid_response', 'Pricing is temporarily unavailable. Please try again.', true);
            }

            return $data;
        });
    }

    /** @return array<string, mixed> */
    public function submit(Generation $generation): array
    {
        return $this->request('POST', '/generations', [
            'prompt' => $generation->prompt,
            'size' => $generation->settings['size'],
            'quality' => $generation->settings['quality'],
            'reference_images' => $generation->settings['reference_images'],
            'metadata' => ['local_generation_id' => $generation->id],
        ], $generation->id);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $providerId): array
    {
        return $this->request('GET', '/generations/'.rawurlencode($providerId));
    }

    /** @return array<string, mixed> */
    public function listing(?string $cursor = null): array
    {
        return $this->request('GET', '/generations', array_filter([
            'limit' => 100,
            'cursor' => $cursor,
        ]));
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->request('GET', '/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = [], ?string $idempotencyKey = null, bool $authenticated = true): array
    {
        if ($authenticated && ! config('services.pixelforge.key')) {
            throw new ProviderException('not_configured', 'Pixelforge is not configured yet. Ask the operator to add an API key.');
        }
        $limiter = 'pixelforge.requests.'.$this->fingerprint();
        if (RateLimiter::tooManyAttempts($limiter, 55)) {
            throw new ProviderException('rate_limit_exceeded', 'Waiting for provider request capacity.', true, false, max(1, RateLimiter::availableIn($limiter)));
        }
        RateLimiter::hit($limiter, 60);
        $request = Http::acceptJson()->connectTimeout(3)->timeout(10);
        if ($authenticated) {
            $request = $request->withToken((string) config('services.pixelforge.key'));
        }
        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }
        try {
            $url = rtrim((string) config('services.pixelforge.url'), '/').$path;
            $response = $method === 'GET' ? $request->get($url, $data) : $request->post($url, $data);
        } catch (ConnectionException $exception) {
            throw new ProviderException('connection_error', 'Provider confirmation is delayed. Your reservation is protected while we reconcile.', true, $method === 'POST', 5);
        }

        return $this->parse($response, $method === 'POST');
    }

    /** @return array<string, mixed> */
    private function parse(Response $response, bool $submission): array
    {
        $data = $response->json();
        if (! $response->successful()) {
            $code = is_array($data) ? (string) data_get($data, 'error.code', 'provider_error') : 'provider_error';
            $message = match ($code) {
                'content_policy_violation' => 'The provider could not accept this prompt. Try changing its wording.',
                'invalid_api_key' => 'The provider API key needs attention from the operator.',
                'daily_limit_exceeded' => 'The provider daily limit has been reached. Please try again tomorrow (UTC).',
                default => 'The provider could not complete this request. Please try again later.',
            };
            $retryable = ($response->status() === 429 && $code !== 'daily_limit_exceeded') || $response->serverError();
            $uncertain = $submission && $response->serverError() && $code !== 'overloaded';
            throw new ProviderException($code, $message, $retryable, $uncertain, max(0, (int) $response->header('Retry-After')));
        }
        if (! is_array($data)) {
            throw new ProviderException('invalid_response', 'The provider returned an invalid response.', true, $submission, 5);
        }

        return $data;
    }
}

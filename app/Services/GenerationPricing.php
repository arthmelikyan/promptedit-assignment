<?php

namespace App\Services;

use App\Data\EstimateData;
use App\Data\GenerationData;
use InvalidArgumentException;

final class GenerationPricing
{
    public const int SCALE = 1_000_000_000_000;

    public const int PURCHASE = 10 * self::SCALE;

    private const array TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function fromUsd(string $amount): int
    {
        if (! is_numeric($amount) || ! preg_match('/^\d+(?:\.\d{1,12})?$/D', $amount)) {
            throw new InvalidArgumentException('Invalid exact USD amount.');
        }
        $scaled = bcmul($amount, (string) self::SCALE, 0);
        if (bccomp($scaled, (string) PHP_INT_MAX) > 0) {
            throw new InvalidArgumentException('USD amount exceeds supported range.');
        }

        return (int) $scaled;
    }

    public function withMarkup(int $providerCost): int
    {
        $amount = bcdiv(bcmul((string) $providerCost, '125', 0), '100', 12);
        if (bccomp($amount, (string) PHP_INT_MAX) > 0 || bccomp($amount, bcadd($amount, '0', 0), 12) !== 0) {
            throw new InvalidArgumentException('Charge cannot be represented exactly.');
        }

        return (int) $amount;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     */
    public function cost(array $usage, array $rates): int
    {
        $total = '0';
        foreach (self::TOKEN_TYPES as $type) {
            $count = $usage[$type] ?? null;
            $rate = $rates[$type] ?? null;
            if (! is_int($count) || $count < 0 || ! is_string($rate)) {
                throw new InvalidArgumentException('Incomplete provider billing data.');
            }
            $total = bcadd($total, bcmul((string) $count, (string) $this->fromUsd($rate), 0), 0);
        }
        $cost = bcdiv($total, '1000000', 12);
        if (bccomp($cost, (string) PHP_INT_MAX) > 0 || bccomp($cost, bcadd($cost, '0', 0), 12) !== 0) {
            throw new InvalidArgumentException('Provider cost cannot be represented exactly.');
        }

        return (int) $cost;
    }

    /** @param array<string, mixed> $pricing */
    public function estimate(GenerationData $data, array $pricing): EstimateData
    {
        $rules = $pricing['tokens'];
        $divisor = (int) $rules['characters_per_text_token'];
        if ($divisor < 1) {
            throw new InvalidArgumentException('Invalid provider token rules.');
        }
        $base = intdiv(mb_strlen($data->prompt) + $divisor - 1, $divisor);
        $variance = (string) $rules['text_token_variance'];
        if (! is_numeric($variance)) {
            throw new InvalidArgumentException('Invalid token variance.');
        }
        $lowText = max(0, (int) bcmul((string) $base, bcsub('1', $variance, 12), 0));
        $highText = (int) bcadd(bcmul((string) $base, bcadd('1', $variance, 12), 12), '0.999999999999', 0);
        $usage = [
            'input_text_tokens' => $lowText,
            'input_image_tokens' => count($data->referenceImages) * (int) $rules['per_reference_image'],
            'output_tokens' => (int) $rules['output'][$data->quality][$data->size],
            'reasoning_tokens' => (int) $rules['reasoning'][$data->quality]['min'],
        ];
        $minimum = $this->withMarkup($this->cost($usage, $pricing['rates']));
        $usage['input_text_tokens'] = $highText;
        $usage['reasoning_tokens'] = (int) $rules['reasoning'][$data->quality]['max'];

        return new EstimateData($minimum, $this->withMarkup($this->cost($usage, $pricing['rates'])), $pricing);
    }
}

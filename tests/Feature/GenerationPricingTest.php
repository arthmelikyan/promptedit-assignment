<?php

use App\Data\GenerationData;
use App\Services\GenerationPricing;

test('calculates the documented maximum cost and exact markup', function () {
    $pricing = new GenerationPricing;
    $estimate = $pricing->estimate(new GenerationData(str_repeat('a', 400), '1536x1024', 'high', ['https://example.com/a.png', 'https://example.com/b.png']), pixelforgePricing());

    expect($estimate->maximum)->toBe(467_343_750_000);
    expect($pricing->withMarkup(12_500_000))->toBe(15_625_000);
    expect($pricing->fromUsd('0.046125000'))->toBe(46_125_000_000);
});

test('estimates all size and quality combinations with exact token rules', function (string $quality, string $size, int $expected) {
    $estimate = (new GenerationPricing)->estimate(new GenerationData('abcd', $size, $quality), pixelforgePricing());

    expect($estimate->maximum)->toBe($expected);
})->with([
    ['low', '1024x1024', 21_825_000_000],
    ['low', '1536x1024', 28_825_000_000],
    ['low', '1024x1536', 28_825_000_000],
    ['medium', '1024x1024', 86_262_500_000],
    ['medium', '1536x1024', 113_762_500_000],
    ['medium', '1024x1536', 113_762_500_000],
    ['high', '1024x1024', 340_012_500_000],
    ['high', '1536x1024', 447_512_500_000],
    ['high', '1024x1536', 447_512_500_000],
]);

test('counts unicode characters rather than bytes', function () {
    $pricing = new GenerationPricing;

    expect($pricing->estimate(new GenerationData('🌅🌅🌅🌅', '1024x1024', 'low'), pixelforgePricing())->maximum)
        ->toBe($pricing->estimate(new GenerationData('abcd', '1024x1024', 'low'), pixelforgePricing())->maximum);
});

test('rejects incomplete token usage rather than billing zero', function () {
    expect(fn () => (new GenerationPricing)->cost([], pixelforgePricing()['rates']))->toThrow(InvalidArgumentException::class);
});

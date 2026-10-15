<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spectra\Data\TokenMetrics;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;

/**
 * The cost model: cache reads/writes, modality rates, long-context and dated
 * prices, aliases and currency conversion. Uses a test-only catalog so the
 * numbers don't move when the built-in catalogs are updated.
 */
function acmePricing(string $currency = 'USD'): ProviderPricing
{
    return new class($currency) extends ProviderPricing
    {
        public function __construct(protected string $catalogCurrency) {}

        public function provider(): string
        {
            return 'acme';
        }

        public function currency(): string
        {
            return $this->catalogCurrency;
        }

        protected function define(): void
        {
            $this->model('acme-1', fn ($m) => $m
                ->displayName('Acme 1')
                ->aliases('acme-1-latest', 'acme/acme-1')
                ->canGenerateText()
                ->tier('standard', inputPrice: 100, outputPrice: 400, cachedInputPrice: 10, cacheWrite5mPrice: 125, cacheWrite1hPrice: 200,
                    audioInputPrice: 300, imageInputPrice: 200, audioOutputPrice: 800, imageOutputPrice: 1000)
                ->tier('standard', inputPrice: 200, outputPrice: 600, cachedInputPrice: 20, aboveInputTokens: 200_000)
                ->tier('standard', inputPrice: 150, outputPrice: 500, since: '2027-01-01')
                ->tier('batch', inputPrice: 50, outputPrice: 200));

            $this->model('acme-1-2026-01-01', fn ($m) => $m
                ->displayName('Acme 1 (2026-01-01)')
                ->canGenerateText()
                ->cost(inputPrice: 999, outputPrice: 999));

            $this->model('acme-ocr', fn ($m) => $m
                ->displayName('Acme OCR')
                ->pricingUnit('page')
                ->cost(pricePerUnit: 0.4));
        }
    };
}

function useAcmePricing(string $currency = 'USD'): void
{
    app()->instance(PricingLookup::class, new PricingLookup([acmePricing($currency)]));
}

beforeEach(fn () => useAcmePricing());

it('bills uncached, cache-read, 5m and 1h cache-write tokens at their own rates', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 10_000 + 20_000 + 30_000,
        completionTokens: 10_000,
        cachedTokens: 20_000,
        cacheWriteTokens: 30_000,
        cacheWrite1hTokens: 10_000,
    ), at: Carbon::parse('2026-10-15'));

    // (10K uncached × 100 + 20K reads × 10 + 20K 5m-writes × 125 + 10K 1h-writes × 200) / 1M = 5.7; 10K out × 400 / 1M = 4
    expect($cost['prompt_cost'])->toEqualWithDelta(5.7, 1e-9)
        ->and($cost['completion_cost'])->toEqualWithDelta(4.0, 1e-9)
        ->and($cost['total_cost_in_cents'])->toEqualWithDelta(9.7, 1e-9);
});

it('bills audio and image tokens at their modality rates', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 30_000,
        completionTokens: 30_000,
        audioInputTokens: 10_000,
        imageInputTokens: 10_000,
        audioOutputTokens: 10_000,
        imageOutputTokens: 10_000,
    ), at: Carbon::parse('2026-10-15'));

    // in: 10K text × 100 + 10K audio × 300 + 10K image × 200 = 6; out: 10K × 400 + 10K × 800 + 10K × 1000 = 22 (per 1M)
    expect($cost['prompt_cost'])->toEqualWithDelta(6.0, 1e-9)
        ->and($cost['completion_cost'])->toEqualWithDelta(22.0, 1e-9);
});

it('never bills a breakdown beyond the prompt total', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 100_000,
        cachedTokens: 500_000,
    ), at: Carbon::parse('2026-10-15'));

    // Only the 100K prompt tokens are billed, all as cache reads: 100K × 10 / 1M
    expect($cost['prompt_cost'])->toEqualWithDelta(1.0, 1e-9);
});

it('switches to long-context prices above the threshold', function () {
    $calculator = app(CostCalculator::class);
    $at = Carbon::parse('2026-10-15');

    $short = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 200_000), at: $at);
    $long = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 200_001), at: $at);

    expect($short['prompt_cost'])->toBe(20.0)
        ->and($long['prompt_cost'])->toBe(200_001 * 200 / 1_000_000);
});

it('applies dated prices from their start date', function () {
    $calculator = app(CostCalculator::class);
    $tokens = new TokenMetrics(promptTokens: 100_000);

    expect($calculator->forTokens('acme', 'acme-1', $tokens, at: Carbon::parse('2026-12-31'))['prompt_cost'])->toEqualWithDelta(10.0, 1e-9)
        ->and($calculator->forTokens('acme', 'acme-1', $tokens, at: Carbon::parse('2027-01-01'))['prompt_cost'])->toEqualWithDelta(15.0, 1e-9);
});

it('falls back to standard prices for a tier the model lacks', function () {
    $calculator = app(CostCalculator::class);
    $tokens = new TokenMetrics(promptTokens: 100_000);
    $at = Carbon::parse('2026-10-15');

    expect($calculator->forTokens('acme', 'acme-1', $tokens, 'batch', $at)['prompt_cost'])->toEqualWithDelta(5.0, 1e-9)
        ->and($calculator->forTokens('acme', 'acme-1', $tokens, 'priority', $at)['prompt_cost'])->toEqualWithDelta(10.0, 1e-9);
});

it('resolves aliases, routing suffixes and dated ids, but never :free', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->canonicalModel('acme', 'acme-1-latest'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme/acme-1'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1:nitro'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1-20260501'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1:free'))->toBeNull();
});

it('prefers an explicit dated catalog entry over the date-stripped one', function () {
    expect(app(PricingLookup::class)->canonicalModel('acme', 'acme-1-2026-01-01'))->toBe('acme-1-2026-01-01');
});

it('prices page-billed models per page', function () {
    expect(app(CostCalculator::class)->calculateByPages('acme', 'acme-ocr', 10)['total_cost_in_cents'])->toBe(4.0);
});

it('converts catalog prices into the reporting currency', function () {
    useAcmePricing('EUR');
    config(['spectra.costs.currency' => 'USD', 'spectra.costs.exchange_rates' => ['EUR' => 1.5]]);

    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Carbon::parse('2026-10-15'));

    expect($cost['prompt_cost'])->toEqualWithDelta(15.0, 1e-9);
});

it('counts a missing exchange rate as 1:1 and warns once', function () {
    useAcmePricing('EUR');
    Log::spy();
    $calculator = new CostCalculator;

    $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000));
    $cost = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000));

    expect($cost['prompt_cost'])->toEqualWithDelta(10.0, 1e-9);
    Log::shouldHaveReceived('warning')->once();
});

it('round-trips usage arrays', function () {
    $tokens = TokenMetrics::fromArray(['input_tokens' => 10, 'output_tokens' => 5, 'cache_write_tokens' => 3, 'cached_tokens' => 2]);

    expect($tokens->promptTokens)->toBe(10)
        ->and($tokens->completionTokens)->toBe(5)
        ->and($tokens->cacheWriteTokens)->toBe(3)
        ->and(TokenMetrics::fromArray($tokens->toUsageArray()))->toEqual($tokens);
});

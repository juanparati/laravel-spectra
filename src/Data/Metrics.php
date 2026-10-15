<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Everything a handler extracts from one provider response.
 */
readonly class Metrics extends DataTransferObject
{
    /**
     * @param  array<string, int>  $toolCalls  Billable server-side tool usage keyed by the
     *                                         provider's tool-pricing key (e.g. ['web_search' => 3]).
     */
    public function __construct(
        public ?TokenMetrics $tokens = null,
        public ?ImageMetrics $image = null,
        public ?AudioMetrics $audio = null,
        public ?VideoMetrics $video = null,
        public ?SearchMetrics $search = null,
        public ?DocumentMetrics $document = null,
        public array $toolCalls = [],
        /**
         * The cost the provider itself reported for this request, in US cents
         * (e.g. OpenRouter `usage.cost`, xAI `cost_in_usd_ticks`). When set it
         * replaces the catalog estimate.
         */
        public ?float $reportedCostInCents = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(
            array_map(
                fn ($value) => $value instanceof DataTransferObject ? $value->toArray() : $value,
                parent::toArray(),
            ),
            fn ($value) => $value !== null && $value !== [],
        );
    }
}

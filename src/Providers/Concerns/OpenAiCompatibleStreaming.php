<?php

declare(strict_types=1);

namespace Spectra\Providers\Concerns;

use Spectra\Support\Tracking\StreamHandler;

/**
 * Stream handler for OpenAI-compatible `chat/completions` streams.
 *
 * Usage is normalised by the owning handler, so provider quirks (reasoning
 * tokens outside `completion_tokens`, Groq's `x_groq.usage`, reported costs)
 * are handled the same way for streamed and non-streamed responses.
 */
class OpenAiCompatibleStreaming extends StreamHandler
{
    public function __construct(
        protected OpenAiCompatibleChatHandler $handler,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function text(array $data): ?string
    {
        $text = $data['choices'][0]['delta']['content'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $usage = $this->handler->usageFrom($data);

        if ($usage === null) {
            return $currentUsage;
        }

        $normalised = $this->handler->tokensFromUsage($usage)->toUsageArray();
        $reported = $this->handler->reportedCostInCents($usage, $data);

        if ($reported !== null) {
            $normalised['reported_cost_in_cents'] = $reported;
        }

        return array_merge($currentUsage, $normalised);
    }

    /** @param  array<string, mixed>  $data */
    public function finishReason(array $data): ?string
    {
        $reason = $data['choices'][0]['finish_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        $result = array_filter([
            'model' => is_string($data['model'] ?? null) ? $data['model'] : null,
            'id' => is_string($data['id'] ?? null) ? $data['id'] : null,
        ]);

        return $result !== [] ? $result : null;
    }
}

<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Streaming;

use Spectra\Providers\XAi\Handlers\ResponsesHandler;
use Spectra\Providers\XAi\XAiUsage;
use Spectra\Support\Tracking\StreamHandler;

/**
 * xAI Responses API stream (`response.*` server-sent events).
 *
 * Text arrives in `response.output_text.delta`; the final usage and cost
 * arrive in the terminal `response.completed` (or `.incomplete` / `.failed`)
 * event, normalised by the same handler as non-streamed responses.
 */
class ResponsesStreaming extends StreamHandler
{
    protected const TERMINAL_EVENTS = ['response.completed', 'response.incomplete', 'response.failed'];

    public function __construct(
        protected ResponsesHandler $handler,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'response.output_text.delta') {
            return null;
        }

        return is_string($data['delta'] ?? null) ? $data['delta'] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $usage = $this->terminalResponse($data)['usage'] ?? null;

        if (! is_array($usage)) {
            return $currentUsage;
        }

        $normalised = $this->handler->tokensFromUsage($usage)->toUsageArray();
        $cost = XAiUsage::costInCents($usage);
        $toolCalls = XAiUsage::toolCalls($usage);

        if ($cost !== null) {
            $normalised['reported_cost_in_cents'] = $cost;
        }

        if ($toolCalls !== []) {
            $normalised['tool_calls'] = $toolCalls;
        }

        return array_merge($currentUsage, $normalised);
    }

    /** @param  array<string, mixed>  $data */
    public function finishReason(array $data): ?string
    {
        $status = $this->terminalResponse($data)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];

        $result = array_filter([
            'model' => is_string($response['model'] ?? null) ? $response['model'] : null,
            'id' => is_string($response['id'] ?? null) ? $response['id'] : null,
        ]);

        return $result !== [] ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function terminalResponse(array $data): array
    {
        if (! in_array($data['type'] ?? null, self::TERMINAL_EVENTS, true)) {
            return [];
        }

        return is_array($data['response'] ?? null) ? $data['response'] : [];
    }
}

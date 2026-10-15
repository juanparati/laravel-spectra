<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

/**
 * Reads one provider's streaming events: text, usage, finish reason, model.
 */
abstract class StreamHandler
{
    /**
     * Extract text content from a streaming chunk.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function text(array $data): ?string;

    /**
     * Extract usage/token metrics from a streaming chunk.
     *
     * Receives the current accumulated usage so providers that split
     * usage across multiple chunks (e.g. Anthropic) can accumulate.
     *
     * Token keys follow TokenMetrics::toUsageArray(): prompt_tokens is the
     * full prompt (including cached_tokens and cache_write_tokens) and
     * completion_tokens the full output (including reasoning_tokens). The
     * breakdown keys are cached_tokens, cache_write_tokens,
     * cache_write_1h_tokens, reasoning_tokens, audio_input_tokens,
     * image_input_tokens, audio_output_tokens and image_output_tokens.
     *
     * Optional extra keys the tracker applies to the request:
     * - reported_cost_in_cents (float): cost the provider reported, in US cents
     * - tool_calls (array<string, int>): billable server-tool usage
     * - pricing_tier (string): tier the provider says it served
     * - cost_multiplier (float): e.g. 1.1 for US-only inference
     *
     * @param  array<string, mixed>  $data  The normalized chunk data
     * @param  array<string, mixed>  $currentUsage  Current accumulated usage
     * @return array<string, mixed> Updated usage array
     */
    abstract public function usage(array $data, array $currentUsage): array;

    /**
     * Extract finish reason from a streaming chunk.
     *
     * Returns null if this chunk doesn't contain a finish reason.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function finishReason(array $data): ?string;

    /**
     * Extract model and response ID from a streaming chunk.
     *
     * Returns an array with optional 'model' and 'id' keys,
     * or null if this chunk doesn't contain model info.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    abstract public function model(array $data): ?array;
}

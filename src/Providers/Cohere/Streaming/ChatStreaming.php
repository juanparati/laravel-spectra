<?php

declare(strict_types=1);

namespace Spectra\Providers\Cohere\Streaming;

use Spectra\Providers\Cohere\Handlers\ChatHandler;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Cohere v2 chat stream.
 *
 * Events: `message-start` (id), `content-delta` (text in
 * `delta.message.content.text`; reasoning arrives as `.thinking` and is not
 * collected), and `message-end` (`delta.finish_reason` and `delta.usage`).
 * The model is not repeated in the stream, so it comes from the request.
 */
class ChatStreaming extends StreamHandler
{
    /** @param  array<string, mixed>  $data */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'content-delta') {
            return null;
        }

        $text = $data['delta']['message']['content']['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $usage = $data['delta']['usage'] ?? null;

        if (($data['type'] ?? null) !== 'message-end' || ! is_array($usage)) {
            return $currentUsage;
        }

        return array_merge($currentUsage, ChatHandler::tokensFromUsage($usage)->toUsageArray());
    }

    /** @param  array<string, mixed>  $data */
    public function finishReason(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'message-end') {
            return null;
        }

        $reason = $data['delta']['finish_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (($data['type'] ?? null) !== 'message-start' || ! is_string($data['id'] ?? null)) {
            return null;
        }

        return ['id' => $data['id']];
    }
}

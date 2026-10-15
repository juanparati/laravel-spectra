<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi\Handlers;

use Spectra\Contracts\Handler;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * fal.ai queue bookkeeping: `.../requests/{id}/status`, its event stream
 * `.../status/stream`, and `.../cancel`.
 *
 * These calls say nothing about cost, so they are never recorded; the
 * result fetch is. The handler is marked as returning a binary body only so
 * the status event stream is not parsed as a model stream.
 */
class QueueStatusHandler implements Handler, ReturnsBinaryResponse, SkipsResponse
{
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('#^/[^/]+/[^/]+(/[^/]+)*/requests/[^/]+/(status(/stream)?|cancel)$#', $endpoint);
    }

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return null;
    }
}

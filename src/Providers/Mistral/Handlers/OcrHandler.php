<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\DocumentMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * Mistral Document AI OCR (`POST /v1/ocr`), billed per page.
 *
 * The page count comes from `usage_info.pages_processed`. Requests with a
 * document or bounding-box annotation format bill a higher "annotated page"
 * rate, which the catalog does not model (see MistralPricing).
 */
class OcrHandler implements ExtractsModelFromResponse, Handler, MatchesResponseShape
{
    use ExtractsModelField;
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    public function endpoints(): array
    {
        return ['/v1/ocr'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $pages = $responseData['usage_info']['pages_processed']
            ?? (is_array($responseData['pages'] ?? null) ? count($responseData['pages']) : 0);

        return new Metrics(
            document: new DocumentMetrics(pages: (int) $pages),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (! is_array($response['pages'] ?? null)) {
            return null;
        }

        $markdown = array_filter(array_map(
            fn ($page) => is_array($page) && is_string($page['markdown'] ?? null) ? $page['markdown'] : null,
            $response['pages'],
        ));

        return $markdown !== [] ? implode("\n\n", $markdown) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return isset($data['pages'], $data['usage_info']);
    }
}

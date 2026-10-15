<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\GeminiUsage;
use Spectra\Support\MediaPersister;

/**
 * Gemini image generation (inline image parts of `generateContent`) and
 * Imagen `generateImages` responses.
 */
class ImageHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasMedia, MatchesResponseShape
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:generateContent',
            '/{version}/models/{model}:streamGenerateContent',
            '/{version}/models/{model}:generateImages',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $imageCount = 0;

        // Gemini generateContent image response
        foreach ($responseData['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $imageCount++;
            }
        }

        // Imagen generateImages response
        foreach ($responseData['generatedImages'] ?? [] as $image) {
            if (isset($image['image'])) {
                $imageCount++;
            }
        }

        $usage = GeminiUsage::metadata($responseData);
        $tokens = GeminiUsage::tokens($usage);

        // Image tokens bill at the image output rate. Without a per-modality
        // breakdown, the candidate tokens of a response that produced images
        // are taken as image tokens (thinking tokens stay at the text rate).
        if ($imageCount > 0 && $tokens->imageOutputTokens === 0 && ! isset($usage['candidatesTokensDetails'])) {
            $tokens = new TokenMetrics(
                promptTokens: $tokens->promptTokens,
                completionTokens: $tokens->completionTokens,
                cachedTokens: $tokens->cachedTokens,
                reasoningTokens: $tokens->reasoningTokens,
                audioInputTokens: $tokens->audioInputTokens,
                imageOutputTokens: $tokens->completionTokens - $tokens->reasoningTokens,
            );
        }

        return new Metrics(
            tokens: $tokens,
            image: new ImageMetrics(
                count: $imageCount,
            ),
            toolCalls: GeminiUsage::groundingCalls($responseData),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['modelVersion'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        $parts = [];

        // Gemini generateContent with inline image data
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $parts[] = '[generated image]';
            } elseif (isset($part['text']) && $part['text'] !== '') {
                $parts[] = $part['text'];
            }
        }

        // Imagen generateImages response
        foreach ($response['generatedImages'] ?? [] as $image) {
            if (isset($image['image'])) {
                $parts[] = '[generated image]';
            }
        }

        return ! empty($parts) ? implode("\n", $parts) : null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        // Gemini image response: candidates with inline image data
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                return true;
            }
        }

        // Imagen generateImages response
        if (isset($data['generatedImages'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $stored = [];
        $index = 0;

        // Gemini inline image data
        foreach ($responseData['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'image/')) {
                $mimeType = $part['inlineData']['mimeType'];
                $extension = match ($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'png',
                };
                $content = base64_decode($part['inlineData']['data'], true);

                if ($content === false) {
                    continue;
                }

                $stored[] = $persister->store($requestId, $index, $content, $extension);
                $index++;
            }
        }

        // Imagen generateImages response
        foreach ($responseData['generatedImages'] ?? [] as $image) {
            if (isset($image['image']['imageBytes'])) {
                $content = base64_decode($image['image']['imageBytes'], true);

                if ($content === false) {
                    continue;
                }

                $stored[] = $persister->store($requestId, $index, $content, 'png');
                $index++;
            }
        }

        return $stored;
    }
}

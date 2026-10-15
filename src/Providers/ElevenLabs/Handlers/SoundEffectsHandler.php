<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

/**
 * ElevenLabs sound effects, `POST /v1/sound-generation`. The request may set
 * `duration_seconds`; without it the model picks the length.
 */
class SoundEffectsHandler extends GeneratedAudioHandler
{
    public function endpoints(): array
    {
        return ['/v1/sound-generation'];
    }

    protected function defaultModel(): string
    {
        return 'eleven_text_to_sound_v2';
    }

    /** @param  array<string, mixed>  $requestData */
    protected function requestedSeconds(array $requestData): ?float
    {
        $seconds = $requestData['duration_seconds'] ?? null;

        return is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : null;
    }
}

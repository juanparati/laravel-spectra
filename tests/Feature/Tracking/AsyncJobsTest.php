<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Async jobs: the model is remembered from the submit request when the poll
 * can't name it, failed jobs keep the provider's error, and a JSON reply to a
 * `stream: true` request is not treated as a stream.
 */
function loadFixture(string $path): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../responses/'.$path), true);
}

it('remembers the model of a queued fal job when the result URL drops its sub-path', function () {
    $id = '764cabcf-b745-4b3e-ae38-1200304cf45b';

    Http::fake([
        'queue.fal.run/fal-ai/flux/dev' => Http::response(loadFixture('falai/queue-submit.json')),
        "queue.fal.run/fal-ai/flux/requests/{$id}" => Http::response(loadFixture('falai/queue-result-flux-dev.json')),
    ]);

    Http::post('https://queue.fal.run/fal-ai/flux/dev', ['prompt' => 'a sunset over mountains']);
    Http::get("https://queue.fal.run/fal-ai/flux/requests/{$id}");

    $row = SpectraRequest::sole();

    // 1024×768 → 1 MP, 1920×1080 → 2 MP (rounded up per image) = 3 MP × 2.5¢.
    expect($row->model)->toBe('FLUX.1 Dev')
        ->and($row->response_id)->toBe($id)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(7.5, 1e-9);
});

it('stores the provider error for a failed prediction', function () {
    Http::fake(['api.replicate.com/*' => Http::response([
        'id' => 'pred_failed_1',
        'model' => 'black-forest-labs/flux-1.1-pro',
        'version' => 'v1',
        'status' => 'failed',
        'input' => ['prompt' => 'x'],
        'output' => null,
        'error' => 'NSFW content detected',
        'created_at' => '2026-10-15T10:00:00.000Z',
        'started_at' => '2026-10-15T10:00:01.000Z',
        'completed_at' => '2026-10-15T10:00:03.500Z',
    ])]);

    Http::get('https://api.replicate.com/v1/predictions/pred_failed_1');

    $row = SpectraRequest::sole();

    expect($row->status_code)->toBe(200)
        ->and($row->error_message)->toBe('NSFW content detected')
        ->and($row->latency_ms)->toBe(2500)
        ->and((float) $row->total_cost_in_cents)->toBe(0.0);
});

it('does not treat a JSON reply to a stream request as a stream', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'id' => 'chatcmpl-json',
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3],
    ])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true, 'messages' => []]);

    $row = SpectraRequest::sole();

    expect($row->is_streaming)->toBeFalse()
        ->and($row->prompt_tokens)->toBe(11)
        ->and($row->completion_tokens)->toBe(3);
});

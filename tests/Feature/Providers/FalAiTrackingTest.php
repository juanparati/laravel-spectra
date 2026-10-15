<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

it('records fal.ai image generation response to database', function (string $fixture, string $endpoint, int $expectedImages) {
    $response = $this->loadMockResponse($fixture);

    $context = Spectra::startRequest('falai', 'fal-ai/fast-sdxl');
    $context->endpoint = $endpoint;

    $record = Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('falai')
        ->and($record->status_code)->toBe(200)
        ->and($record->image_count)->toBe($expectedImages);
})->with([
    'sync response' => ['falai/sync-image.json', '/fal-ai/fast-sdxl', 1],
    'queue response' => ['falai/queue-image.json', '/fal-ai/fast-sdxl', 1],
]);

it('records a queued job once, from its result, with the model and request id', function () {
    $id = '764cabcf-b745-4b3e-ae38-1200304cf45b';
    $base = "https://queue.fal.run/fal-ai/flux/dev/requests/{$id}";

    Http::fake([
        'queue.fal.run/fal-ai/flux/dev' => Http::response($this->loadMockResponse('falai/queue-submit.json')),
        "queue.fal.run/fal-ai/flux/dev/requests/{$id}/status*" => Http::sequence()
            ->push($this->loadMockResponse('falai/queue-status-in-progress.json'), 202)
            ->push($this->loadMockResponse('falai/queue-status-completed.json')),
        "queue.fal.run/fal-ai/flux/dev/requests/{$id}" => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json')),
    ]);

    Http::withHeaders(['Authorization' => 'Key test'])->post('https://queue.fal.run/fal-ai/flux/dev', ['prompt' => 'a sunset over mountains', 'num_images' => 2]);
    Http::get("{$base}/status?logs=1");
    Http::get("{$base}/status");

    expect(SpectraRequest::count())->toBe(0);

    Http::get($base);
    Http::get($base);

    $record = SpectraRequest::sole();

    // FLUX.1 dev bills $0.025 per megapixel, each image rounded up to whole
    // 1024×1024 megapixels: 1024×768 → 1 MP, 1920×1080 → 2 MP; 3 × 2.5 = 7.5 cents.
    expect($record->model)->toBe('FLUX.1 Dev')
        ->and($record->response_id)->toBe($id)
        ->and($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(2)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(7.5, 0.000001)
        ->and((int) SpectraDailyStat::sum('request_count'))->toBe(1);
});

it('never records the status event stream', function () {
    Http::fake(['queue.fal.run/*' => Http::response(
        "data: {\"status\": \"IN_PROGRESS\", \"request_id\": \"abc\"}\n\ndata: {\"status\": \"COMPLETED\", \"request_id\": \"abc\"}\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    $response = Http::get('https://queue.fal.run/fal-ai/flux/requests/abc/status/stream');

    expect($response->body())->toContain('COMPLETED')
        ->and(SpectraRequest::count())->toBe(0);
});

it('takes the model from the path when the result url drops the sub-path', function () {
    Http::fake(['queue.fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-flux-dev.json'))]);

    Http::get('https://queue.fal.run/fal-ai/flux/requests/764cabcf-b745-4b3e-ae38-1200304cf45b');

    expect(SpectraRequest::sole()->model)->toBe('fal-ai/flux')
        ->and(SpectraRequest::sole()->response_id)->toBe('764cabcf-b745-4b3e-ae38-1200304cf45b');
});

it('records video results as videos, not as zero images', function () {
    Http::fake(['queue.fal.run/*' => Http::response($this->loadMockResponse('falai/queue-result-video.json'))]);

    Http::get('https://queue.fal.run/fal-ai/kling-video/requests/9a1b2c3d-0000-4e5f-8a9b-123456789abc');

    $record = SpectraRequest::sole();

    expect($record->model_type)->toBe('video')
        ->and($record->video_count)->toBe(1)
        ->and($record->image_count)->toBeNull()
        ->and($record->model)->toBe('fal-ai/kling-video');
});

it('records direct calls to fal.run per image', function () {
    Http::fake(['fal.run/*' => Http::response($this->loadMockResponse('falai/sync-nano-banana.json'))]);

    Http::post('https://fal.run/fal-ai/nano-banana/edit', ['prompt' => 'add a tiny wizard hat', 'image_urls' => ['https://example.com/cat.png']]);

    $record = SpectraRequest::sole();

    // nano-banana/edit bills like nano-banana: $0.0398 per image
    expect($record->model)->toBe('Nano Banana')
        ->and($record->image_count)->toBe(1)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(3.98, 0.000001);
});

it('doubles the recraft price for vector styles', function (string $style, float $expected) {
    Http::fake(['fal.run/*' => Http::response($this->loadMockResponse('falai/sync-image.json'))]);

    Http::post('https://fal.run/fal-ai/recraft/v3/text-to-image', ['prompt' => 'a fox logo', 'style' => $style]);

    expect(SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta($expected, 0.000001);
})->with([
    // $0.04 per image, $0.08 for vector styles
    'raster' => ['realistic_image', 4.0],
    'vector' => ['vector_illustration/line_art', 8.0],
]);

it('bills flux pro ultra per image whatever its size', function () {
    Http::fake(['fal.run/*' => Http::response([
        'images' => [['url' => 'https://v3.fal.media/files/a.jpg', 'width' => 2752, 'height' => 1536, 'content_type' => 'image/jpeg']],
        'seed' => 7,
        'has_nsfw_concepts' => [false],
        'prompt' => 'x',
    ])]);

    Http::post('https://fal.run/fal-ai/flux-pro/v1.1-ultra', ['prompt' => 'x']);

    // $0.06 per image
    expect(SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(6.0, 0.000001);
});

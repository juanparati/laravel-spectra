<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestWriter;
use Workbench\App\Models\User;

/**
 * End-to-end behaviour of the tracking pipeline as real apps hit it: through
 * Laravel's HTTP client, with the HttpWatcher installed as global middleware.
 */
function openAiChunk(array $data): string
{
    return 'data: '.json_encode($data)."\n\n";
}

function openAiStreamBody(): string
{
    return openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]])
        .openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]])
        .openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 2, 'prompt_tokens_details' => ['cached_tokens' => 4]]])
        ."data: [DONE]\n\n";
}

/** A body that can only be read once, like a real network stream. */
function oneShotBody(string $contents, int $chunk = 32): PumpStream
{
    $parts = str_split($contents, $chunk);

    return new PumpStream(function () use (&$parts) {
        return array_shift($parts) ?? false;
    });
}

function streamedRequest(string $body, string $contentType = 'text/event-stream'): Response
{
    return Http::setHandler(new MockHandler([new PsrResponse(200, ['Content-Type' => $contentType], oneShotBody($body))]))
        ->withOptions(['stream' => true])
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true, 'stream_options' => ['include_usage' => true]]);
}

it('records a streamed response once the app has read it, without consuming it', function () {
    $response = streamedRequest(openAiStreamBody());

    expect(SpectraRequest::count())->toBe(0);

    $body = $response->body();

    expect($body)->toBe(openAiStreamBody());

    $row = SpectraRequest::sole();
    expect($row->is_streaming)->toBeTrue()
        ->and($row->prompt_tokens)->toBe(12)
        ->and($row->completion_tokens)->toBe(2)
        ->and($row->cached_tokens)->toBe(4)
        ->and($row->response_id)->toBe('chatcmpl-s1')
        ->and($row->time_to_first_token_ms)->not->toBeNull();
});

it('records a stream the app stops reading early', function () {
    $response = streamedRequest(openAiStreamBody());

    $response->toPsrResponse()->getBody()->read(64);
    unset($response);
    gc_collect_cycles();

    expect(SpectraRequest::count())->toBe(1)
        ->and(SpectraRequest::first()->is_streaming)->toBeTrue();
});

it('records a stream once when the app also wraps it in Spectra::stream()', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');
    $response = streamedRequest(openAiStreamBody());

    $text = implode('', iterator_to_array($tracker->track($response->body()), false));
    $tracker->finish();
    $tracker->finish();

    expect($text)->toBe('Hello')
        ->and(SpectraRequest::count())->toBe(1)
        ->and(SpectraRequest::first()->completion_tokens)->toBe(2);
});

it('tracks newline-delimited JSON streams (Ollama)', function () {
    $ndjson = json_encode(['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => 'Hi'], 'done' => false])."\n"
        .json_encode(['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 7, 'eval_count' => 3])."\n";

    $response = Http::setHandler(new MockHandler([new PsrResponse(200, ['Content-Type' => 'application/x-ndjson'], oneShotBody($ndjson))]))
        ->post('http://localhost:11434/api/chat', ['model' => 'llama3', 'messages' => []]);

    expect($response->body())->toBe($ndjson);

    $row = SpectraRequest::sole();
    expect($row->prompt_tokens)->toBe(7)
        ->and($row->completion_tokens)->toBe(3)
        ->and($row->is_streaming)->toBeTrue();
});

it('records a stream error event as a failure', function () {
    $body = openAiChunk(['id' => 'chatcmpl-e1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hi']]]])
        .openAiChunk(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);

    streamedRequest($body)->body();

    $row = SpectraRequest::sole();
    expect($row->error_message)->toBe('Overloaded')
        ->and($row->error_type)->toContain('StreamErrorException');
});

it('reads one-pass JSON bodies only as the app reads them', function () {
    $json = json_encode(['id' => 'chatcmpl-np', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]]);

    $response = Http::setHandler(new MockHandler([new PsrResponse(200, ['Content-Type' => 'application/json'], oneShotBody($json))]))
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect($response->json('choices.0.message.content'))->toBe('ok');

    $row = SpectraRequest::sole();
    expect($row->prompt_tokens)->toBe(5)
        ->and($row->is_streaming)->toBeFalse();
});

it('records provider error responses with the provider message', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached', 'type' => 'requests']], 429)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $row = SpectraRequest::sole();
    expect($row->status_code)->toBe(429)
        ->and($row->error_message)->toBe('Rate limit reached')
        ->and((float) $row->total_cost_in_cents)->toBe(0.0);
});

it('records connection failures and still throws them to the app', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('Connection timed out')]);

    expect(fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']))
        ->toThrow(ConnectionException::class);

    expect(SpectraRequest::sole()->error_message)->toBe('Connection timed out');
});

it('never lets a tracking failure reach the app', function () {
    app()->instance(StatsAggregator::class, new class extends StatsAggregator
    {
        public function recordRequest(SpectraRequest $request): void
        {
            throw new RuntimeException('stats are down');
        }
    });
    app()->forgetInstance(RequestWriter::class);
    app()->forgetInstance(Spectra\Support\Tracking\RequestPersister::class);

    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-x', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect($response->successful())->toBeTrue();
});

it('keeps raw request data out of storage when request storage is off', function () {
    config(['spectra.storage.store_prompts' => false, 'spectra.storage.store_request_data' => false]);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-p', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'secret']]]);

    expect(SpectraRequest::query()->value('request'))->toBeNull();
});

it('counts a polled job once and keeps its original start time', function () {
    $writer = app(RequestWriter::class);
    $base = ['provider' => 'openai', 'model' => 'gpt-4o', 'response_id' => 'job-1', 'status_code' => 200, 'model_type' => 'text'];

    $first = $writer->write($base + ['prompt_tokens' => 0, 'total_cost_in_cents' => 0, 'created_at' => now()->subMinute()]);
    $writer->write($base + ['prompt_tokens' => 100, 'total_cost_in_cents' => 5, 'created_at' => now()]);
    $writer->write($base + ['prompt_tokens' => 100, 'total_cost_in_cents' => 5, 'created_at' => now()]);

    $global = SpectraDailyStat::query()->whereNull('trackable_type')->sole();

    expect(SpectraRequest::count())->toBe(1)
        ->and(SpectraRequest::first()->created_at->equalTo($first->created_at))->toBeTrue()
        ->and($global->request_count)->toBe(1)
        ->and($global->prompt_tokens)->toBe(100)
        ->and((float) $global->total_cost_in_cents)->toBe(5.0);
});

it('stores the trackable morph alias so budgets and usage find it', function () {
    Relation::morphMap(['user' => User::class]);

    $user = User::create(['name' => 'a', 'email' => 'a@example.test', 'password' => 'x']);
    Spectra::forTrackable($user);

    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-m', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::first()->trackable_type)->toBe('user')
        ->and(SpectraRequest::query()->forTrackable($user)->count())->toBe(1);

    Relation::morphMap([], false);
});

it('lets an explicit trackable win over the signed-in user', function () {
    $owner = User::create(['name' => 'owner', 'email' => 'owner@example.test', 'password' => 'x']);
    $actor = User::create(['name' => 'actor', 'email' => 'actor@example.test', 'password' => 'x']);
    $this->actingAs($actor);

    Spectra::forTrackable($owner);

    expect(Spectra::startRequest('openai', 'gpt-4o')->trackableId)->toBe($owner->getKey())
        ->and(Spectra::startRequest('openai', 'gpt-4o', ['trackable_type' => User::class, 'trackable_id' => $owner->getKey()])->trackableId)->toBe($owner->getKey());
});

it('resets global settings between queued jobs', function () {
    Spectra::addGlobalTags(['job-a'])->withPricingTier('batch');

    // What the queue worker does between jobs.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $context = Spectra::startRequest('openai', 'gpt-4o');

    expect($context->tags)->toBe([])
        ->and($context->pricingTier)->toBeNull();
});

it('reads the model from multipart bodies even when the file comes first', function () {
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'hello', 'usage' => ['type' => 'duration', 'seconds' => 3]])]);

    Http::attach('file', str_repeat('A', 300_000), 'audio.mp3')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'whisper-1']);

    expect(SpectraRequest::sole()->model)->not->toBe('unknown');
});

it('tracks Gemini streamed JSON arrays as streams', function () {
    $body = json_encode([
        ['candidates' => [['content' => ['parts' => [['text' => 'Hel']], 'role' => 'model']]], 'modelVersion' => 'gemini-2.5-flash', 'responseId' => 'g-1'],
        ['candidates' => [['content' => ['parts' => [['text' => 'lo']], 'role' => 'model'], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 16], 'modelVersion' => 'gemini-2.5-flash', 'responseId' => 'g-1'],
    ]);

    $response = Http::setHandler(new MockHandler([new PsrResponse(200, ['Content-Type' => 'application/json'], oneShotBody($body))]))
        ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent', ['contents' => []]);

    expect($response->body())->toBe($body);

    $row = SpectraRequest::sole();
    expect($row->is_streaming)->toBeTrue()
        ->and($row->prompt_tokens)->toBe(9)
        ->and($row->completion_tokens)->toBe(7)
        ->and($row->reasoning_tokens)->toBe(5);
});

it('merges per-request tags and metadata with the global ones', function () {
    Spectra::addGlobalTags(['global'])->withMetadata(['env' => 'test']);

    $context = Spectra::startRequest('openai', 'gpt-4o', ['tags' => ['local'], 'metadata' => ['feature' => 'chat']]);

    expect($context->tags)->toBe(['global', 'local'])
        ->and($context->metadata)->toBe(['env' => 'test', 'feature' => 'chat']);
});

it('keeps Spectra::stream() options when it takes over a tracked stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o', ['tags' => ['wrapped'], 'metadata' => ['source' => 'manual']]);
    $response = streamedRequest(openAiStreamBody());

    iterator_to_array($tracker->track($response->body()), false);
    $tracker->finish();

    $row = SpectraRequest::with('tags')->sole();
    expect($row->tags->pluck('name')->all())->toContain('wrapped')
        ->and($row->metadata)->toMatchArray(['source' => 'manual']);
});

it('prices a stream at the tier the request asked for', function () {
    $response = Http::setHandler(new MockHandler([new PsrResponse(200, ['Content-Type' => 'text/event-stream'], oneShotBody(openAiStreamBody()))]))
        ->withOptions(['stream' => true])
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true, 'service_tier' => 'flex']);

    $response->body();

    expect(SpectraRequest::sole()->pricing_tier)->toBe('flex');
});

it('stores the provider model id even when the response echoes the request', function () {
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-id', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $row = SpectraRequest::sole();
    expect($row->snapshot)->toBe('gpt-4o')
        ->and($row->model)->toBe('GPT-4o');
});

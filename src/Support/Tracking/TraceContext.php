<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * The trace that groups every AI request of one unit of work: an HTTP
 * request, a queued job, or an Artisan command.
 *
 * The trace id is kept in Laravel's request-scoped Context (as hidden data,
 * so it never appears in logs). That gives three properties for free:
 *
 * - every AI call in the same request/job/command gets the same trace id;
 * - the next request or job starts a new trace (Context is reset between them);
 * - queued jobs dispatched during a request carry the trace on (Laravel copies
 *   hidden Context into the job payload), so request → job → AI call is one chain.
 *
 * When the incoming HTTP request carries a W3C `traceparent` header, its trace
 * id (and parent span id) is used, so Spectra's spans join the caller's
 * distributed trace.
 */
class TraceContext
{
    public const TRACE_ID = 'spectra.trace_id';

    public const PARENT_SPAN_ID = 'spectra.parent_span_id';

    /**
     * The trace id for the current unit of work, created on first use.
     */
    public static function traceId(): string
    {
        try {
            $existing = Context::getHidden(self::TRACE_ID);

            if (is_string($existing) && $existing !== '') {
                return $existing;
            }

            [$traceId, $parentSpanId] = self::fromTraceparent() ?? [(string) Str::uuid(), null];

            Context::addHidden(self::TRACE_ID, $traceId);

            if ($parentSpanId !== null) {
                Context::addHidden(self::PARENT_SPAN_ID, $parentSpanId);
            }

            return $traceId;
        } catch (Throwable) {
            // Context unavailable (e.g. a bare container): fall back to one id per call.
            return (string) Str::uuid();
        }
    }

    /**
     * The span id of the incoming request's span, from `traceparent`.
     */
    public static function parentSpanId(): ?string
    {
        try {
            $parent = Context::getHidden(self::PARENT_SPAN_ID);

            return is_string($parent) && $parent !== '' ? $parent : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Use the given trace id for the rest of this unit of work (and the jobs
     * it dispatches).
     */
    public static function set(string $traceId): void
    {
        try {
            Context::addHidden(self::TRACE_ID, $traceId);
        } catch (Throwable) {
            // Nothing to propagate to; the id still applies via Spectra's globals.
        }
    }

    /**
     * Forget the current trace, so the next AI call starts a new one.
     */
    public static function reset(): void
    {
        try {
            Context::forgetHidden([self::TRACE_ID, self::PARENT_SPAN_ID]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Parse a W3C `traceparent` header (`00-{trace-id}-{parent-id}-{flags}`)
     * from the current HTTP request. The 32-hex trace id is stored as a UUID,
     * which is the same 128 bits and exports back to the same OTLP trace id.
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function fromTraceparent(): ?array
    {
        // Only an HTTP request carries the header; the console's stand-in
        // request has none, so commands and queued jobs fall through.
        if (! config('spectra.tracking.use_traceparent', true) || ! app()->bound('request')) {
            return null;
        }

        $header = request()->header('traceparent');

        if (! is_string($header)
            || ! preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($header)), $matches)
            || trim($matches[1], '0') === ''
            || trim($matches[2], '0') === '') {
            return null;
        }

        $hex = $matches[1];
        $uuid = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

        return [$uuid, $matches[2]];
    }
}

<?php

declare(strict_types=1);

namespace Spectra\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spectra\Support\Tracking\RequestWriter;

/**
 * Saves a tracked request off the request cycle (queue or after-response).
 */
class PersistSpectraRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tags,
    ) {}

    public function handle(RequestWriter $writer): void
    {
        $writer->write($this->attributes, $this->tags);
    }
}

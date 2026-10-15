<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use RuntimeException;

/**
 * An error the provider sent inside an otherwise successful stream
 * (e.g. Anthropic's `overloaded_error` event mid-response).
 */
class StreamErrorException extends RuntimeException {}

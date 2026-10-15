<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * What a model's `pricePerUnit` is charged against.
 *
 * Token-priced models use `inputPrice` / `outputPrice` instead (cents per 1M
 * tokens); `characters` is cents per 1M characters; every other unit is cents
 * per single unit.
 */
enum PricingUnit: string
{
    case Tokens = 'tokens';
    case Minute = 'minute';
    case Second = 'second';
    case Characters = 'characters';
    case Image = 'image';
    case Megapixel = 'megapixel';
    case Video = 'video';
    case Search = 'search';
    case Page = 'page';
    case Request = 'request';
}

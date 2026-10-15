<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * fal.ai prices, in cents per image or per megapixel.
 *
 * Megapixel models round every image up to whole megapixels of 1024 x 1024
 * pixels (see the fal.ai ImageHandler). Edit endpoints that bill like their
 * base model are aliases.
 *
 * Source: https://fal.ai/pricing and each model page's billing details
 * (last reviewed 2026-10-15).
 */
class FalAiPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'falai';
    }

    protected function define(): void
    {
        $this->model('fal-ai/flux/dev', fn ($m) => $m
            ->displayName('FLUX.1 Dev')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2.5));

        $this->model('fal-ai/flux/schnell', fn ($m) => $m
            ->displayName('FLUX.1 Schnell')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('fal-ai/flux-pro/v1.1', fn ($m) => $m
            ->displayName('FLUX 1.1 Pro')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Billed per image (`billing_unit: images`), whatever its size.
        $this->model('fal-ai/flux-pro/v1.1-ultra', fn ($m) => $m
            ->displayName('FLUX Pro v1.1 Ultra')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 6));

        $this->model('fal-ai/flux-pro/kontext', fn ($m) => $m
            ->displayName('FLUX Pro Kontext')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/flux-pro/kontext/max', fn ($m) => $m
            ->displayName('FLUX Pro Kontext Max')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 8));

        // The model page no longer shows a price; left unchanged.
        $this->model('fal-ai/imagen4/preview', fn ($m) => $m
            ->displayName('Imagen 4 Preview')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Vector styles cost $0.08; FalAi::extractCostMultiplier() doubles
        // the price when the request asks for one.
        $this->model('fal-ai/recraft/v3/text-to-image', fn ($m) => $m
            ->displayName('Recraft V3 Text-to-Image')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/nano-banana', fn ($m) => $m
            ->displayName('Nano Banana')
            ->aliases('fal-ai/nano-banana/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 3.98));

        $this->model('fal-ai/nano-banana-2', fn ($m) => $m
            ->displayName('Nano Banana 2')
            ->aliases('fal-ai/nano-banana-2/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 8));

        $this->model('fal-ai/nano-banana-pro', fn ($m) => $m
            ->displayName('Nano Banana Pro')
            ->aliases('fal-ai/nano-banana-pro/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 15));
    }
}

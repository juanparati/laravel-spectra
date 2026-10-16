# Introduction

[Laravel Spectra](https://github.com/spectra-php/laravel-spectra) is a comprehensive observability package for AI and LLM operations in Laravel applications. It intercepts outgoing requests to AI providers, extracts usage metrics such as token counts and media quantities, calculates costs from a built-in pricing catalog, and persists analytics-ready records that power a built-in real-time dashboard.

Modern applications increasingly rely on AI APIs for text generation, image synthesis, embeddings, speech processing, and more. As usage grows, so does the need to answer critical operational questions: which models are consuming the most tokens, how much each feature costs, which users are driving the highest spend, and where latency and failure patterns emerge. Spectra provides a single, unified observability layer that answers all of these questions without requiring you to instrument each call manually.

<img src="/images/dashboard/home.png" alt="Spectra Dashboard" style="border-radius: 8px; margin-top: 16px;" />

## How It Works

When your application sends a request to a supported AI provider, Spectra intercepts it through one of several tracking mechanisms — automatic watchers, Guzzle middleware, or explicit manual tracking. The intercepted request flows through a processing pipeline that identifies the provider, resolves the appropriate handler, extracts usage metrics into typed data transfer objects, calculates the cost from the pricing catalog, and persists the complete record to the database. Daily statistics are aggregated in real time, and traces can optionally be exported to OpenTelemetry-compatible backends.

The entire process is transparent to your application code. In most cases, you install the package, run the installer, and tracking begins automatically with no code changes required.

## Supported Providers

For the full list of supported providers, see [Models](/models#supported-providers).

You can also add support for additional providers by creating a custom provider class. See [Custom Providers](/custom-providers) for details.

## Key Features

- **Automatic tracking** &mdash; Watchers intercept Laravel HTTP client and OpenAI PHP SDK requests with zero code changes. Guzzle middleware provides an additional integration point for direct Guzzle usage.
- **Multimodal support** &mdash; Track text completions, embeddings, image generation, video generation, text-to-speech, and speech-to-text across all major providers.
- **Cost calculation** &mdash; A built-in pricing catalog with token-based (including prompt-cache reads and writes, reasoning, audio, and image tokens), per-image, per-megapixel, per-video, per-minute, per-second, per-character, per-page, and per-request pricing. Multiple pricing tiers (standard, batch, flex, priority, fast, ultrafast) are supported per model, as well as long-context prices, dated prices, and catalogs in other currencies.
- **Budget enforcement** &mdash; Define cost, token, and request-count limits per user, team, or any Eloquent model. Enforce budgets via middleware with hard blocks or soft warning events.
- **Real-time dashboard** &mdash; A built-in SPA with request exploration, cost analysis, and per-user analytics.
- **Streaming support** &mdash; Streamed responses are tracked automatically as your code reads them, with time-to-first-token metrics and full token accounting, including streamed image generation via OpenAI's Responses API.
- **Media persistence** &mdash; Automatically download and store generated images and videos to any Laravel filesystem disk before provider URLs expire.
- **Tags and metadata** &mdash; Attach custom labels and structured metadata to any request for filtering and grouping in the dashboard.
- **User attribution** &mdash; Automatically associate requests with the authenticated user or manually assign them to any Eloquent model.
- **Flexible persistence** &mdash; Persist records synchronously, after the HTTP response, or via a queue job depending on your latency requirements.
- **OpenTelemetry integration** &mdash; Export AI request traces to any OTLP-compatible backend for correlation with your existing observability infrastructure.
- **Extensible** &mdash; Add custom provider support by implementing a handler class and registering it in the configuration.

## Hire Me

Need help integrating Spectra, tracking AI costs, or building AI-powered features into your Laravel application? I'm available for consulting and development work — see [my services at mayahi.net](https://mayahi.net/services).

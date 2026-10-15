# Troubleshooting

This page covers common issues you may encounter when using Spectra and how to resolve them.

## No Requests Are Being Tracked

**Symptoms:** The dashboard shows no data and the `spectra_requests` table is empty.

**Checklist:**

1. **Spectra is enabled** — Verify the master switch is on:
   ```bash
   SPECTRA_ENABLED=true
   ```

2. **Watcher is enabled** — Automatic tracking requires the watcher to be active:
   ```bash
   SPECTRA_WATCHER_ENABLED=true
   ```

3. **Provider is registered** — The request hostname must match a configured provider in `spectra.providers`. For example, requests to `api.openai.com` are recognized automatically, but requests to a custom or self-hosted endpoint require a [custom provider](/custom-providers) to be registered.

4. **Endpoint is trackable** — The request path must match a trackable endpoint defined in the provider's handlers. For example, OpenAI tracks `/v1/chat/completions`, `/v1/embeddings`, and `/v1/images/generations`, but does not track `/v1/models` or other non-AI endpoints.

5. **Migrations have run** — Ensure the `spectra_requests` table exists:
   ```shell
   php artisan migrate
   ```

6. **Using a supported HTTP client** — Spectra automatically tracks requests made via Laravel's `Http` facade (HttpWatcher, which also covers Prism and Laravel AI), the `openai-php/laravel` SDK (OpenAiWatcher), and Guzzle with Spectra middleware (GuzzleMiddleware). Streamed responses through these clients are tracked too. Direct `curl` calls or other HTTP clients are not tracked automatically — use the [Guzzle middleware](/usage#guzzle-middleware), [`Spectra::stream()`](/usage#spectra-stream), or [manual tracking](/usage#manual-tracking) for those.

7. **A stream was not read to the end** — A streamed request is recorded when your code reads the body to the end, closes it, or drops the response. If your code keeps the response object alive without reading it, nothing is recorded yet.

8. **Saving failed** — Spectra never throws saving errors into your app. They go to your exception handler, so check your error log (for example, for a missing column after an upgrade — see [Migrations](/migrations#v2-migration)).

## Requests Tracked But Cost Is Zero

**Symptoms:** Requests appear in the dashboard but `total_cost_in_cents` shows `0.000000`.

**Checklist:**

1. **Pricing class exists** — The pricing catalog must contain entries for the models you are using. Pricing is defined through `ProviderPricing` classes registered in `config/spectra.php` under `costs.pricing`. See [Pricing](/pricing) for details.

2. **Model name matches** — The model id must match a catalog entry: by its exact id, an alias, or the id without a snapshot date or routing suffix. See [How Spectra Finds a Model](/pricing#model-lookup). Compare the `snapshot` and `model` values in your request records with the pricing class definitions.

3. **Pricing tier exists** — If you are using a non-standard tier (such as `batch` or `flex`), a pricing tier entry must exist for that tier in the provider's pricing class.

4. **Pricing unit matches metrics** — The `pricingUnit` on the model definition must correspond to the metrics available in the response. For example, a model with `pricingUnit('tokens')` requires non-null `prompt_tokens` and `completion_tokens` values.

5. **Cost tracking is enabled** — Verify in the configuration:
   ```php
   'costs' => [
       'enabled' => true,
   ],
   ```

6. **Streamed OpenAI Chat Completions** — OpenAI only sends usage in a Chat Completions stream when the request has `'stream_options' => ['include_usage' => true]`. Without it, the request is recorded with zero tokens and zero cost. See [Streaming](/usage#streaming).

## Dashboard Shows Empty Data

**Symptoms:** The dashboard loads but displays no requests or statistics.

**Checklist:**

1. **Dashboard is enabled** — Verify the dashboard is not disabled:
   ```bash
   SPECTRA_DASHBOARD_ENABLED=true
   ```

2. **Request persistence is on** — If `store_requests` is `false`, requests are tracked in memory but not persisted to the database:
   ```bash
   SPECTRA_STORE_REQUESTS=true
   ```

3. **Database connection is correct** — If using a separate database, ensure the dashboard queries the same connection configured in `storage.connection`.

4. **Authorization gate passes** — The `viewSpectra` gate must return `true` for your user. In local development this is automatic. In production, ensure the gate is defined:
   ```php
   Gate::define('viewSpectra', function ($user) {
       return $user->isAdmin();
   });
   ```

5. **Middleware is correct** — If you added `auth` to dashboard middleware, make sure you are logged in when accessing the dashboard.

## Queue Persistence Not Working

**Symptoms:** Requests are tracked but never appear in the database when using queue mode.

**Checklist:**

1. **Queue worker is running** — Ensure a worker is processing the queue:
   ```shell
   php artisan queue:work
   ```

2. **Queue configuration is correct** — Verify the connection and queue name match your worker:
   ```php
   'queue' => [
       'enabled' => true,
       'connection' => null, // or your queue connection
       'queue' => null,      // or your queue name
   ],
   ```

3. **Check failed jobs** — The `PersistSpectraRequestJob` may be failing silently:
   ```shell
   php artisan queue:failed
   ```

4. **Queue takes priority over after-response** — These modes are mutually exclusive. When `queue.enabled` is `true`, the `after_response` setting is ignored.

## After-Response Mode Not Working

**Symptoms:** `after_response` is enabled but requests appear to persist synchronously.

This is expected behavior in two scenarios:

- **Non-HTTP context** — After-response only works during web requests. Console commands, queue jobs, and scheduled tasks always persist synchronously because there is no HTTP response lifecycle to defer to.
- **Queue is enabled** — When `queue.enabled` is `true`, it takes priority and `after_response` is ignored.

## Budget Middleware Not Enforcing

**Symptoms:** Requests proceed even when the user's budget is exceeded.

**Checklist:**

1. **Budgets are enabled** in the configuration:
   ```php
   'budget' => ['enabled' => true],
   ```

2. **A budget record exists and is active** for the user:
   ```php
   $user->aiBudget()->where('is_active', true)->first();
   ```

3. **Middleware is applied correctly** to the route:
   ```php
   Route::middleware(['auth', 'spectra.budget:openai,gpt-4o'])
       ->post('/ai/chat', ChatController::class);
   ```

4. **Hard limit is enabled** — With `hard_limit = false`, requests are allowed to proceed and only events are fired. Set `hard_limit = true` on the budget to block requests when exceeded.

## OpenTelemetry Export Not Appearing

**Symptoms:** OTEL is enabled but no traces appear in your observability backend.

**Checklist:**

1. **OTEL is enabled** in the configuration:
   ```bash
   SPECTRA_OTEL_ENABLED=true
   ```

2. **Endpoint is reachable** — Test connectivity to your OTLP endpoint:
   ```shell
   curl -v http://localhost:4318/v1/traces
   ```

3. **Authentication headers are configured** — Most cloud backends require authentication. Verify your headers in the configuration.

4. **Export timing** — In after-response mode, traces export after the HTTP response. In console or queue contexts, they export synchronously. If traces appear from CLI commands but not web requests, verify that your server supports terminable middleware.

5. **Timeout is sufficient** — If the OTLP endpoint is remote or slow, increase the timeout:
   ```bash
   SPECTRA_OTEL_TIMEOUT=30
   ```

## Stats Look Wrong After Data Changes

**Symptoms:** Dashboard charts or cost totals don't match the raw request data.

This can also happen after upgrading from 1.x: Spectra 1.x counted each poll of an async job (for example, video generation) in the daily stats.

**Solution:** Rebuild the daily statistics aggregation from the raw request records:

```shell
php artisan spectra:rebuild-stats

# Or rebuild a specific date range
php artisan spectra:rebuild-stats --from=2026-01-01 --to=2026-01-31
```

The stats are rebuilt only from requests that still exist. If you pruned old requests but kept their daily stats, rebuild only the days you still have requests for (see [`spectra:rebuild-stats`](/commands#spectra-rebuild-stats)).

## Media Files Not Being Saved

**Symptoms:** Image or video generation requests are tracked but no media files are downloaded to disk.

**Checklist:**

1. **Media persistence is enabled**:
   ```bash
   SPECTRA_MEDIA_ENABLED=true
   ```

2. **Disk is writable** — Ensure the configured filesystem disk and path exist and are writable by your application.

3. **API key is available** — Some providers require an API key to download media files. Ensure keys are configured in `spectra.api_keys`.

4. **URLs haven't expired** — Provider media URLs have limited lifetimes. If persistence is delayed (for example, via a queue with a long delay), the URLs may expire before download completes.

## Macro Not Available

**Symptoms:** `Http::withAITracking()` or `Http::withoutAITracking()` throws a "Method not found" error.

**Checklist:**

1. **Service provider is registered** — Spectra's service provider should be auto-discovered. If not, register it manually:
   ```php
   // config/app.php
   'providers' => [
       Spectra\SpectraServiceProvider::class,
   ],
   ```

2. **Spectra is enabled** — Macros are registered when Spectra boots:
   ```bash
   SPECTRA_ENABLED=true
   ```

3. **Check for conflicts** — Another package may have registered a macro with the same name. Laravel macros are global and the last registration wins.

## High Memory Usage

**Symptoms:** Application memory spikes when making many AI requests in a single process.

**Solutions:**

- **Enable queue persistence** to move database writes to background workers:
  ```bash
  SPECTRA_QUEUE_ENABLED=true
  ```

- **Disable payload storage** if you don't need request and response bodies:
  ```bash
  SPECTRA_STORE_REQUEST_DATA=false
  SPECTRA_STORE_RESPONSES=false
  ```
  Don't set `SPECTRA_STORE_REQUESTS=false` for this. It stops Spectra from saving request records at all.

- **Schedule pruning** to prevent unbounded table growth:
  ```shell
  php artisan spectra:prune --hours=168
  ```

## Duplicate Requests Being Tracked

**Symptoms:** The same AI request appears multiple times in the dashboard.

Spectra tracks each request once. Every route into a provider (the HTTP client, the OpenAI SDK, the Guzzle stacks, and `withAITracking()`) uses the same middleware, and a request sent with `withAITracking()` is skipped by the global middleware. When two records have the same provider response id, Spectra updates the first one instead of adding a new one. This is also how polled async jobs (for example, video generation) stay one request. If you wrap a stream that Spectra already tracks in `Spectra::stream()`, it is recorded once.

**Causes:**

- **Retry middleware** — If your HTTP client retries failed requests, each attempt is tracked as a separate request. This is intentional, as each retry attempt consumes tokens and incurs costs.

- **No response id** — Records are only merged when the provider sends a response id. If you track the same call twice yourself (for example, with `Spectra::track()` and a custom Guzzle middleware), and the response has no id, you get two records.

**Solution:** Use only one tracking mechanism per request path.

<a name="costs-changed-after-upgrade"></a>
## Costs Look Higher or Lower After Upgrading

**Symptoms:** After upgrading to Spectra 2.0, new requests cost more (or less) than the same requests did before.

This is expected. Spectra 2.0 prices requests more accurately:

- Prompt tokens now include cache reads and writes for every provider (Anthropic used to report only the uncached part), and cache writes are billed at their own rate.
- Reasoning tokens are now billed for every provider (Gemini thinking tokens and xAI reasoning tokens used to be left out).
- The tier the provider reports wins over the tier the request asked for.
- A cost the provider reports (OpenRouter, xAI) is used as is.
- The model the provider actually served is priced first.

Older rows keep their old values. Nothing is rewritten. See the [Upgrade Guide](/upgrade#what-changes-in-the-numbers) for details. To fix daily stats that 1.x counted more than once for polled jobs, run `php artisan spectra:rebuild-stats` once.

<a name="exchange-rate-warning"></a>
## "No Exchange Rate" Warning in the Log

**Symptoms:** The log shows a warning like `Spectra: no exchange rate from EUR to USD`.

A pricing catalog uses another currency than your reporting currency (the built-in Scaleway catalog is in EUR). Without a rate, Spectra counts those prices 1:1, so the costs are wrong. Add the rate to `config/spectra.php`:

```php
'costs' => [
    'currency' => 'USD',
    'exchange_rates' => [
        'EUR' => 1.17, // 1 EUR is worth 1.17 USD
    ],
],
```

The warning is logged once per process. See [Currency Conversion](/costs#currency-conversion).

<a name="budgets-after-upgrade"></a>
## Budgets or Per-User Usage Show Nothing for Old Requests

**Symptoms:** In an app with a morph map, budgets and per-user usage ignore requests recorded before the upgrade.

Spectra 2.0 stores the trackable type as the morph alias (`$model->getMorphClass()`). Spectra 1.x stored the class name. Update the old rows once, as shown in the [Upgrade Guide](/upgrade#trackables-use-morph-aliases).

<a name="budget-alert-once"></a>
## Budget Alert Fired Only Once

**Symptoms:** A `BudgetThresholdReached` event fired once, and not again on later requests.

This is expected. Warning, critical, and exceeded alerts fire once per budget, limit, and period. See [Alerts Fire Once per Period](/budgets#alerts-fire-once).

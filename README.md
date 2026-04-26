# Boogle Client

**Exception reporting for Laravel** — send errors and stack traces from your app to your [Boogle](https://boogle.app) instance (or any compatible ingestion endpoint).

Lightweight, framework-native integration: configure credentials, call one line from your exception handler, and keep shipping while failures show up in your dashboard.

---

## Features

- **Laravel-first** — auto-discovery service provider, facade alias `Boogle`, and Artisan helpers
- **Rich exception context** — class, message, file, line, configurable stack depth, PHP/Laravel versions, DB driver, memory, and HTTP host/method/full URL when a request exists
- **User feedback block** — IP, `User-Agent`, OS/browser (Client Hints + fallback from UA), optional safe query/body/cookie **context** (see `boogle.context`); authenticated user `id` / `uuid` / `email` / `name` when available
- **No-op when unconfigured** — if required `.env` values are missing, nothing is sent and `Boogle::isEnabled()` is `false` (useful to gate UI or to know reporting is off)
- **Environment-aware** — report only in environments you allow (default: `production`)
- **Noise control** — ignore specific exception classes (404s excluded by default) and **deduplicate** repeated identical errors for a configurable window using the cache store
- **Key masking** — configurable blacklist for input/query/session/cookies (e.g. `password`, `token`)
- **Safe by design** — reporting never throws back into your app; timeouts are bounded via Guzzle

---

## Requirements

- PHP **8.2+**
- Laravel **10**, **11**, **12**, or **13** (`illuminate/support`, `illuminate/http`)
- A cache driver configured if you rely on deduplication when `sleep` is greater than zero

---

## Installation

Install the package with Composer:

```bash
composer require andreapollastri/boogle-client
```

Publish configuration and follow the printed checklist:

```bash
php artisan boogle:install
```

That publishes `config/boogle.php`. Set your credentials in `.env`:

| Variable | Description |
|----------|-------------|
| `BOOGLE_KEY` | Personal API token from your Boogle profile |
| `BOOGLE_PROJECT_KEY` | Project identifier in Boogle |
| `BOOGLE_SERVER` | Ingestion URL (default: `https://boogle.app/api/log`) |

### Credentials and reporting

Reporting runs **only** when all of the following are set to non-empty strings (after `trim`):

- `BOOGLE_KEY` → `config('boogle.key')`
- `BOOGLE_PROJECT_KEY` → `config('boogle.project_key')`
- `BOOGLE_SERVER` → `config('boogle.server')` (or keep the default from config)

If any of these is missing, Boogle is **inert**: no HTTP request, no cache deduplication, no work in `handle()`. You can call `Boogle::isEnabled()` to detect this in your app.

The `boogle:test` Artisan command will warn and **not** send a request when Boogle is not enabled.

---

## Reporting exceptions

Wire Boogle into your global exception handler. **Laravel 11+** uses `bootstrap/app.php`; **Laravel 10 and below** use `app/Exceptions/Handler.php`.

| Laravel | Where to register |
|--------|--------------------|
| **11+** | `bootstrap/app.php` → `Application::configure(...)->withExceptions(...)` |
| **10 and older** | `app/Exceptions/Handler.php` → `register()` |

**Laravel 11+** (`bootstrap/app.php`):

```php
use Boogle\Facade as Boogle;
use Illuminate\Foundation\Configuration\Exceptions;

// Inside Application::configure(...)->withExceptions(...)
->withExceptions(function (Exceptions $exceptions) {
    Boogle::registerExceptionHandler($exceptions);
})
```

`handle()` only accepts a `Throwable`. Do **not** call `Boogle::handle($exceptions)` — the first argument to `withExceptions` is the **configuration object**, not an exception, and you will get a type error.

**Alternative** (manual registration):

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (\Throwable $e) {
        Boogle::handle($e);
    });
})
```

**Laravel 10 and below** — `app/Exceptions/Handler.php`, in `register()`:

```php
use Boogle\Facade as Boogle;
use Throwable;

public function register(): void
{
    $this->reportable(function (Throwable $e) {
        Boogle::handle($e);
    });
}
```

### Optional `handle()` arguments

`Boogle::handle()` accepts:

1. **`Throwable $exception`** — the error to report
2. **`string $fileType`** — label for the payload (default: `'php'`)
3. **`array $customData`** — merged into the `exception` object; special keys:
   - **`client`** — merged into the user-feedback `client` object (e.g. `screen` from the browser, see below)
   - **`user_feedback`** — [`array_replace_recursive`](https://www.php.net/array_replace_recursive) with the auto-generated user-feedback array (e.g. add a `note` without replacing the whole block)

```php
Boogle::handle($e, 'php', [
    'order_id'     => $orderId,
    'user_feedback' => ['note' => 'Right after checkout'],
]);
```

**Screen size / client-only data** (not available server-side) can be sent from the front end by storing values in session/flash and reading them in a custom `user_feedback` merge, or by passing a small `client` payload if your error path calls `Boogle::handle` with that array:

```php
Boogle::handle($e, 'php', [
    'client' => [
        'screen' => '1920x1080', // e.g. from JS: `${screen.width}x${screen.height}` or a layout script
    ],
]);
```

---

## User feedback and HTTP payload

Each report sends the same user-feedback object in **three** places for compatibility with different ingestion UIs and APIs:

- Top-level: **`user_feedback`** (snake_case)
- Top-level: **`userFeedback`** (camelCase, same data)
- Nested: **`exception.user_feedback`**

A typical `user_feedback` value includes:

- `type` — e.g. `error_auto`
- `source` — `boogle-laravel`
- `ip`, `user_agent`, `client` (`browser`, `os`, plus your `client` merge)
- `context` (optional) — controlled by `boogle.context` (query, input, session, headers, cookies)

Your Boogle **server** must persist and display whichever key your dashboard expects (root `userFeedback` / `user_feedback` and/or `exception.user_feedback`).

---

## Configuration

Key options in `config/boogle.php`:

| Key | Purpose |
|-----|---------|
| `environments` | Only these app environments trigger reports (default: `['production']`) |
| `except` | Exception class names that are never reported |
| `lines_count` | Maximum stack frames included in the payload |
| `sleep` | Seconds before the same fingerprint can be reported again (`0` disables deduplication) |
| `blacklist` | Key fragments/names to mask as `[REDACTED]` in nested context (e.g. `password`, `token`, `secret`) |
| `context` | `include_input`, `include_query`, `include_session`, `include_headers`, `cookie_values` (see file comments) |

Re-publish after package updates if you need the latest defaults:

```bash
php artisan vendor:publish --tag=boogle-config --force
```

---

## Artisan commands

| Command | Description |
|---------|-------------|
| `php artisan boogle:install` | Publish `config/boogle.php` and print setup steps |
| `php artisan boogle:test` | If Boogle is **enabled**, sends a test `RuntimeException` with `['test' => true]`; otherwise prints a warning and does not hit the network |

### Commands not found (`There are no commands defined in the "boogle" namespace`)

1. **Package discovery** — Ensure `andreapollastri/boogle-client` is not listed under `dont-discover` in your app’s `composer.json`. Then run:

   ```bash
   composer dump-autoload && php artisan package:discover
   ```

2. **Manual provider** — If discovery is disabled, register `Boogle\BoogleServiceProvider::class` in `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (`providers`).

3. **`APP_RUNNING_IN_CONSOLE`** — If this is set to `false` in `.env`, remove it or set it to `true` for CLI. Older package versions only registered commands when Laravel considered the app “console”; current releases register commands regardless.

---

## Testing

Swap the container binding for `Boogle\Boogle` with `Boogle\Fakes\BoogleFake` in tests to assert that expected exceptions were reported without HTTP traffic:

```php
use Boogle\Boogle;
use Boogle\Fakes\BoogleFake;

$this->app->instance(Boogle::class, $fake = new BoogleFake());

// ... exercise code that should report ...

$fake->assertReported(SomeException::class);
// or: $fake->assertNothingReported();
```

---

## License

This package is open source under the **MIT** license (see `composer.json`).

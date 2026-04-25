# Boogle Client

**Exception reporting for Laravel** — send errors and stack traces from your app to your [Boogle](https://boogle.app) instance (or any compatible ingestion endpoint).

Lightweight, framework-native integration: configure credentials, call one line from your exception handler, and keep shipping while failures show up in your dashboard.

---

## Features

- **Laravel-first** — auto-discovery service provider, facade alias `Boogle`, and Artisan helpers
- **Rich context** — exception class, message, file, line, configurable stack depth, PHP/Laravel versions, DB driver, memory, authenticated user (when available), and HTTP host/method/URL when a request exists
- **Environment-aware** — report only in environments you allow (default: `production`)
- **Noise control** — ignore specific exception classes (404s excluded by default) and **deduplicate** repeated identical errors for a configurable window using the cache store
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

---

## Reporting exceptions

Wire Boogle into your global exception handler (e.g. `bootstrap/app.php` in Laravel 11+, or `app/Exceptions/Handler.php` in older apps).

**Laravel 11+** (`bootstrap/app.php`):

```php
use Boogle\Facade as Boogle;
use Illuminate\Foundation\Configuration\Exceptions;
use Throwable;

// Inside Application::configure(...)->withExceptions(...)
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (Throwable $e) {
        Boogle::handle($e);
    });
})
```

**Classic handler** (`app/Exceptions/Handler.php`) — in `register()`:

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

### Optional arguments

`handle()` accepts:

1. **`Throwable $exception`** — the error to report  
2. **`string $fileType`** — label for the payload (default: `'php'`)  
3. **`array $customData`** — merged into the `exception` object sent to the server (e.g. tags or extra debug fields)

```php
Boogle::handle($e, 'php', ['order_id' => $orderId]);
```

---

## Configuration

Key options in `config/boogle.php`:

| Key | Purpose |
|-----|---------|
| `environments` | Only these app environments trigger reports (default: `['production']`) |
| `except` | Exception class names that are never reported |
| `lines_count` | Maximum stack frames included in the payload |
| `sleep` | Seconds before the same fingerprint can be reported again (`0` disables deduplication) |

Re-publish after package updates if you need the latest defaults:

```bash
php artisan vendor:publish --tag=boogle-config --force
```

---

## Artisan commands

| Command | Description |
|---------|-------------|
| `php artisan boogle:install` | Publish `config/boogle.php` and print setup steps |
| `php artisan boogle:test` | Send a test `RuntimeException` with `['test' => true]` so you can verify the pipeline end-to-end |

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

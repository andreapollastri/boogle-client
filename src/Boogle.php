<?php

namespace Boogle;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

class Boogle
{
    private HttpClient $client;

    public function __construct()
    {
        $this->client = new HttpClient([
            'timeout'         => 15,
            'connect_timeout' => 5,
        ]);
    }

    public function isEnabled(): bool
    {
        $key = $this->nonEmptyConfig('boogle.key');
        $project = $this->nonEmptyConfig('boogle.project_key');
        $server = $this->nonEmptyConfig('boogle.server');

        return $key !== null && $project !== null && $server !== null;
    }

    public function handle(Throwable $exception, string $fileType = 'php', array $customData = []): void
    {
        if (! $this->shouldReport($exception)) {
            return;
        }

        if ($this->isSleeping($exception)) {
            return;
        }

        try {
            $this->send($this->buildPayload($exception, $fileType, $customData));
            $this->sleep($exception);
        } catch (\Exception) {
            // Never let reporting break the app
        }
    }

    /**
     * For Laravel 11+ bootstrap: pass the withExceptions() $exceptions value here. Never pass it to handle().
     */
    public function registerExceptionHandler(object $exceptions): void
    {
        if (! method_exists($exceptions, 'reportable')) {
            return;
        }

        $exceptions->reportable(function (Throwable $e) {
            $this->handle($e);
        });
    }

    private function shouldReport(Throwable $exception): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $env = app()->environment();
        $allowedEnvs = config('boogle.environments', ['production']);

        if (! in_array($env, $allowedEnvs)) {
            return false;
        }

        foreach (config('boogle.except', []) as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        return true;
    }

    private function isSleeping(Throwable $exception): bool
    {
        $key = 'boogle:' . md5($exception->getMessage() . $exception->getFile() . $exception->getLine());

        return Cache::has($key);
    }

    private function sleep(Throwable $exception): void
    {
        $sleep = config('boogle.sleep', 60);

        if ($sleep > 0) {
            $key = 'boogle:' . md5($exception->getMessage() . $exception->getFile() . $exception->getLine());
            Cache::put($key, true, $sleep);
        }
    }

    private function buildPayload(Throwable $exception, string $fileType, array $customData): array
    {
        $request = $this->getRequest();
        $httpFromCustom = is_array($customData['http'] ?? null) ? $customData['http'] : [];
        $customRest = $customData;
        unset($customRest['http']);

        $http = array_replace_recursive(
            $this->buildHttpSnapshot($request),
            $httpFromCustom
        );

        $exceptionBlock = array_merge([
            'exception' => get_class($exception),
            'error'     => $exception->getMessage(),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'class'     => get_class($exception),
            'fileType'  => $fileType,
            'executor'  => $this->buildStackTrace($exception),
            'storage'   => $this->getStorageInfo(),
            'user'      => $this->getUser(),
            'http'      => $http,
            'host'      => $request?->getHost() ?? gethostname(),
            'method'    => $http['method'],
            'fullUrl'   => $http['url'],
        ], $customRest);

        return [
            'key'       => config('boogle.project_key'),
            'token'     => config('boogle.key'),
            'exception' => $exceptionBlock,
        ];
    }

    private function httpConfig(): array
    {
        $defaults = [
            'include_query'   => true,
            'include_payload' => true,
            'include_cookies' => true,
            'cookie_values'   => true,
            'include_session' => false,
            'include_headers' => false,
        ];
        $h = config('boogle.http');
        if (is_array($h) && $h !== []) {
            return array_merge($defaults, $h);
        }
        $c = config('boogle.context', []);
        if (is_array($c) && $c !== []) {
            return array_merge($defaults, [
                'include_query'   => $c['include_query'] ?? $defaults['include_query'],
                'include_payload' => $c['include_input'] ?? $defaults['include_payload'],
                'include_cookies' => true,
                'cookie_values'   => $c['cookie_values'] ?? $defaults['cookie_values'],
                'include_session' => $c['include_session'] ?? $defaults['include_session'],
                'include_headers' => $c['include_headers'] ?? $defaults['include_headers'],
            ]);
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHttpSnapshot(?Request $request): array
    {
        $o = $this->httpConfig();
        if (! $request) {
            return [
                'url'          => null,
                'query'        => [],
                'payload'      => [],
                'cookies'      => [],
                'method'       => null,
                'ip'           => null,
                'user_agent'   => null,
                'client'       => ['browser' => null, 'os' => null],
                'content_type' => null,
                'is_json'      => null,
                'wants_json'   => null,
                'is_ajax'      => null,
                'is_secure'    => null,
                'referer'      => null,
                'headers'      => null,
                'session'      => null,
            ];
        }

        $blacklist = $this->blacklistKeysLower();
        $query = [];
        if (! empty($o['include_query']) && $request->query() !== []) {
            $query = $this->applyBlacklist($request->query(), $blacklist);
        }

        $payload = [];
        if (! empty($o['include_payload'])) {
            $payload = $this->applyBlacklist($this->getPayloadArray($request), $blacklist);
        }

        $cookies = [];
        if (! empty($o['include_cookies']) && $request->cookies->count() > 0) {
            $allCookies = is_array($request->cookies->all()) ? $request->cookies->all() : [];
            if (! empty($o['cookie_values'])) {
                $cookies = $this->applyBlacklist($allCookies, $blacklist);
            } else {
                foreach (array_keys($allCookies) as $name) {
                    $cookies[$name] = '[REDACTED]';
                }
            }
        }

        $headers = null;
        if (! empty($o['include_headers'])) {
            $headers = $this->buildHeadersSnapshot($request, $blacklist);
        }

        $session = null;
        if (! empty($o['include_session']) && $request->hasSession()) {
            $session = $this->applyBlacklist($request->session()->all(), $blacklist);
        }

        $url = (string) $request->fullUrl();
        $ua = (string) $request->userAgent();

        return [
            'url'            => $url === '' ? null : $url,
            'query'          => $query,
            'payload'        => $payload,
            'cookies'        => $cookies,
            'method'         => $request->getMethod(),
            'ip'             => $request->ip(),
            'user_agent'     => $ua === '' ? null : $ua,
            'client'         => $this->inferClient($request, $ua),
            'content_type'   => $request->header('Content-Type'),
            'is_json'        => $request->isJson(),
            'wants_json'     => $request->expectsJson(),
            'is_ajax'        => $request->ajax(),
            'is_secure'      => $request->secure(),
            'referer'        => $request->header('Referer'),
            'headers'        => $headers,
            'session'        => $session,
        ];
    }

    private function getPayloadArray(Request $request): array
    {
        if ($request->isJson()) {
            $j = $request->json();
            if ($j !== null) {
                $all = $j->all();
                if (is_array($all)) {
                    return $all;
                }
            }

            return [];
        }
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [];
        }

        $all = $request->all();
        $queryKeys = array_keys($request->query());

        if (! is_array($all)) {
            return [];
        }

        $out = $all;
        foreach ($queryKeys as $k) {
            if (array_key_exists($k, $out)) {
                unset($out[$k]);
            }
        }

        return $out;
    }

    private function inferClient(Request $request, string $ua): array
    {
        $out = [
            'browser' => $this->inferBrowserFromUserAgent($ua),
            'os'      => $this->inferOsFromUserAgent($ua),
        ];
        if ($request->header('Sec-CH-UA-Platform')) {
            $out['os'] = trim($request->header('Sec-CH-UA-Platform', ''), ' "');
        }
        if ($request->header('Sec-CH-UA')) {
            $out['browser'] = $this->parseSecChUa($request->header('Sec-CH-UA', '')) ?? $out['browser'];
        }
        if ($out['os'] === null) {
            $out['os'] = $this->inferOsFromUserAgent($ua);
        }
        if ($out['browser'] === null) {
            $out['browser'] = $this->inferBrowserFromUserAgent($ua);
        }

        return $out;
    }

    private function parseSecChUa(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (preg_match('/"([^"]+)";\s*v="([^"]+)"/', $header, $m)) {
            return $m[1] . ' ' . $m[2];
        }

        return null;
    }

    private function inferOsFromUserAgent(string $ua): ?string
    {
        if (str_contains($ua, 'Windows NT')) {
            return 'Windows';
        }
        if (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $m)) {
            return 'macOS ' . str_replace('_', '.', $m[1]);
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod')) {
            return 'iOS';
        }
        if (str_contains($ua, 'Android')) {
            return 'Android';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }

    private function inferBrowserFromUserAgent(string $ua): ?string
    {
        if (str_contains($ua, 'Edg/')) {
            if (preg_match('/Edg\/(\d+)/', $ua, $m)) {
                return 'Edge ' . $m[1];
            }

            return 'Edge';
        }
        if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) {
            if (preg_match('/(?:OPR|Opera)\/(\d+)/', $ua, $m)) {
                return 'Opera ' . $m[1];
            }

            return 'Opera';
        }
        if (str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Edg/')) {
            if (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
                return 'Chrome ' . $m[1];
            }

            return 'Chrome';
        }
        if (str_contains($ua, 'Firefox/')) {
            if (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
                return 'Firefox ' . $m[1];
            }

            return 'Firefox';
        }
        if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') && str_contains($ua, 'Mac')) {
            if (preg_match('/Version\/([0-9.]+)/', $ua, $m)) {
                return 'Safari ' . $m[1];
            }

            return 'Safari';
        }

        return null;
    }

    private function buildHeadersSnapshot(Request $request, array $blacklist): ?array
    {
        $flat = [];
        foreach ($request->headers->all() as $name => $values) {
            if (! is_string($name)) {
                continue;
            }
            if ($this->isBlacklistedKey($name, $blacklist)) {
                $flat[$name] = '[REDACTED]';
            } else {
                $flat[$name] = is_array($values) && count($values) === 1 ? $values[0] : $values;
            }
        }

        return $this->applyBlacklist($flat, $blacklist);
    }

    private function applyBlacklist(array $data, ?array $blacklist = null): array
    {
        $blacklist = $blacklist ?? $this->blacklistKeysLower();
        $out = [];

        foreach ($data as $key => $value) {
            $keyStr = is_string($key) || is_int($key) ? (string) $key : '';
            if ($this->isBlacklistedKey($keyStr, $blacklist)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if ($value instanceof UploadedFile) {
                $out[$key] = '[UPLOAD] ' . $value->getClientOriginalName();
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->applyBlacklist($value, $blacklist);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function blacklistKeysLower(): array
    {
        $raw = config('boogle.blacklist', []);

        return array_map('strtolower', is_array($raw) ? $raw : []);
    }

    private function isBlacklistedKey(string $key, array $blacklist): bool
    {
        $lower = strtolower($key);
        foreach ($blacklist as $b) {
            if ($lower === $b || str_contains($lower, $b)) {
                return true;
            }
        }

        return false;
    }

    private function buildStackTrace(Throwable $exception): array
    {
        $lines = config('boogle.lines_count', 12);
        $frames = [];

        foreach ($exception->getTrace() as $trace) {
            $frames[] = [
                'file'     => $trace['file'] ?? null,
                'line'     => $trace['line'] ?? null,
                'class'    => $trace['class'] ?? null,
                'function' => $trace['function'] ?? null,
                'type'     => $trace['type'] ?? null,
            ];
        }

        return array_slice($frames, 0, $lines);
    }

    private function getStorageInfo(): array
    {
        return [
            'php'     => phpversion(),
            'laravel' => app()->version(),
            'driver'  => config('database.default'),
            'memory'  => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
        ];
    }

    private function getUser(): ?array
    {
        try {
            if (auth()->check()) {
                $user = auth()->user();
                $uuid = null;
                if (is_object($user)) {
                    if (isset($user->uuid)) {
                        $uuid = $user->uuid;
                    } elseif (method_exists($user, 'getUuid')) {
                        $uuid = $user->getUuid();
                    } elseif (method_exists($user, 'getAttribute')) {
                        $uuid = $user->getAttribute('uuid');
                    }
                }
                if ($uuid !== null) {
                    $uuid = match (true) {
                        is_string($uuid) => $uuid,
                        is_int($uuid) => (string) $uuid,
                        is_object($uuid) && method_exists($uuid, '__toString') => (string) $uuid,
                        default => null,
                    };
                }

                return [
                    'id'    => $user->getAuthIdentifier(),
                    'uuid'  => $uuid,
                    'email' => method_exists($user, 'getEmail') ? $user->getEmail() : ($user->email ?? null),
                    'name'  => $user->name ?? null,
                ];
            }
        } catch (\Exception) {
        }

        return null;
    }

    private function getRequest(): ?Request
    {
        try {
            if (function_exists('request')) {
                $r = request();
                if ($r instanceof Request) {
                    return $r;
                }
            }

            $r = app('request');

            return $r instanceof Request ? $r : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function nonEmptyConfig(string $name): ?string
    {
        $value = config($name);
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function send(array $payload): void
    {
        $server = config('boogle.server');

        $this->client->post($server, [
            'json'    => $payload,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }
}

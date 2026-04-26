<?php

namespace Boogle;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Http\Request;
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
        $clientFromCustom = is_array($customData['client'] ?? null) ? $customData['client'] : [];
        $feedbackFromCustom = is_array($customData['user_feedback'] ?? null) ? $customData['user_feedback'] : [];

        $customRest = $customData;
        unset($customRest['client'], $customRest['user_feedback']);

        $userFeedback = array_replace_recursive(
            $this->buildUserFeedback($request, $clientFromCustom),
            $feedbackFromCustom
        );

        return [
            'key'       => config('boogle.project_key'),
            'token'     => config('boogle.key'),
            'exception' => array_merge([
                'exception'      => get_class($exception),
                'error'          => $exception->getMessage(),
                'file'           => $exception->getFile(),
                'line'           => $exception->getLine(),
                'class'          => get_class($exception),
                'fileType'       => $fileType,
                'executor'       => $this->buildStackTrace($exception),
                'storage'        => $this->getStorageInfo(),
                'user'           => $this->getUser(),
                'host'           => $request ? $request->getHost() : gethostname(),
                'method'         => $request ? $request->getMethod() : null,
                'fullUrl'        => $request ? $request->fullUrl() : null,
                'user_feedback'  => $userFeedback,
            ], $customRest),
        ];
    }

    private function buildUserFeedback(?Request $request, array $clientOverride): array
    {
        $client = array_merge(
            $this->inferClientFromRequest($request),
            $clientOverride
        );

        $feedback = [
            'type'   => 'error_auto',
            'source' => 'boogle-laravel',
            'ip'     => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'client' => $client,
        ];

        $ctx = $this->getDebugContext($request);
        if ($ctx !== []) {
            $feedback['context'] = $ctx;
        }

        return $feedback;
    }

    private function inferClientFromRequest(?Request $request): array
    {
        $out = [
            'browser' => null,
            'os'      => null,
        ];

        if (! $request) {
            return $out;
        }

        $ua = (string) $request->userAgent();
        if ($ua === '') {
            return $out;
        }

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

    private function getDebugContext(?Request $request): array
    {
        if (! $request) {
            return [];
        }

        $ctx = config('boogle.context', []);
        $out = [];
        $blacklist = $this->blacklistKeysLower();

        if (! empty($ctx['include_query']) && $request->query() !== []) {
            $out['query'] = $this->applyBlacklist($request->query());
        }
        if (! empty($ctx['include_input'])) {
            $input = $this->getRequestInputExcluding($request);
            if ($input !== []) {
                $out['input'] = $this->applyBlacklist($input);
            }
        }
        if (! empty($ctx['include_session']) && $request->hasSession()) {
            $sessionData = $request->session()->all();
            if ($sessionData !== []) {
                $out['session'] = $this->applyBlacklist($sessionData, $blacklist);
            }
        }
        if (! empty($ctx['include_headers'])) {
            $headers = $request->headers->all();
            $flat = [];
            foreach ($headers as $name => $values) {
                if (! is_string($name)) {
                    continue;
                }
                if ($this->isBlacklistedKey($name, $blacklist)) {
                    $flat[$name] = '[REDACTED]';
                } else {
                    $flat[$name] = is_array($values) && count($values) === 1 ? $values[0] : $values;
                }
            }
            $out['headers'] = $this->applyBlacklist($flat, $blacklist);
        }
        if ($request->cookies->count() > 0) {
            if (! empty($ctx['cookie_values'])) {
                $out['cookies'] = $this->applyBlacklist(
                    is_array($request->cookies->all()) ? $request->cookies->all() : []
                );
            } else {
                $out['cookie_names'] = array_keys($request->cookies->all());
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestInputExcluding(Request $request): array
    {
        if (method_exists($request, 'except')) {
            return $request->except([]);
        }
        if (method_exists($request, 'all')) {
            $all = $request->all();

            return is_array($all) ? $all : [];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $blacklist
     * @return array<string, mixed>
     */
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
            if (is_array($value)) {
                $out[$key] = $this->applyBlacklist($value, $blacklist);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function blacklistKeysLower(): array
    {
        $raw = config('boogle.blacklist', []);

        return array_map('strtolower', is_array($raw) ? $raw : []);
    }

    /**
     * @param  array<int, string>  $blacklist
     */
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
            return app('request');
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

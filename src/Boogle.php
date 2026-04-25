<?php

namespace Boogle;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
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

        return [
            'key'       => config('boogle.project_key'),
            'token'     => config('boogle.key'),
            'exception' => array_merge([
                'exception' => get_class($exception),
                'error'     => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'class'     => get_class($exception),
                'fileType'  => $fileType,
                'executor'  => $this->buildStackTrace($exception),
                'storage'   => $this->getStorageInfo(),
                'user'      => $this->getUser(),
                'host'      => $request ? $request->getHost() : gethostname(),
                'method'    => $request ? $request->getMethod() : null,
                'fullUrl'   => $request ? $request->fullUrl() : null,
            ], $customData),
        ];
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
                return [
                    'id'    => $user->getAuthIdentifier(),
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

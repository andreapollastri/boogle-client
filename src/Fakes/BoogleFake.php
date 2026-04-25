<?php

namespace Boogle\Fakes;

use Throwable;

class BoogleFake
{
    private array $reported = [];

    public function handle(Throwable $exception, string $fileType = 'php', array $customData = []): void
    {
        $this->reported[] = $exception;
    }

    public function assertReported(string $class): void
    {
        $found = collect($this->reported)->first(fn ($e) => $e instanceof $class);
        \PHPUnit\Framework\Assert::assertNotNull($found, "Expected exception [{$class}] was not reported.");
    }

    public function assertNothingReported(): void
    {
        \PHPUnit\Framework\Assert::assertEmpty($this->reported, 'Exceptions were reported unexpectedly.');
    }

    public function getReported(): array
    {
        return $this->reported;
    }
}

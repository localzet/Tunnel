<?php declare(strict_types=1);

final class IntegrationPayload
{
    public function __construct(public string $markerPath)
    {
    }

    public function __wakeup(): void
    {
        file_put_contents($this->markerPath, 'executed');
    }
}

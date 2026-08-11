<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\StreetNetworkFillSpawner;

final class FakeStreetNetworkFillSpawner extends StreetNetworkFillSpawner
{
    /** @var list<string> */
    private array $spawned = [];

    public function __construct(private readonly string $refusedUuid = '')
    {
        parent::__construct('/app');
    }

    public function spawn(string $networkUuid): void
    {
        if ($networkUuid === $this->refusedUuid) {
            throw new \RuntimeException('The fake spawner refuses this row.');
        }

        $this->spawned[] = $networkUuid;
    }

    /**
     * @return list<string>
     */
    public function spawned(): array
    {
        return $this->spawned;
    }
}

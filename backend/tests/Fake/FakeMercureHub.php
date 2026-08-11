<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class FakeMercureHub implements HubInterface
{
    /**
     * @var list<Update>
     */
    private array $published = [];

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->published[] = $update;

        return 'fake-' . count($this->published);
    }

    /**
     * @return list<Update>
     */
    public function published(): array
    {
        return $this->published;
    }
}

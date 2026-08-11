<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class MessagePayload
{
    /**
     * @param array<string, string|int> $bodyArgs
     */
    public function __construct(
        public string $bodyKey,
        public array $bodyArgs = [],
        public string $body = '',
    ) {
    }
}

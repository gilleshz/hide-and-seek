<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SiriService;

final readonly class SiriProfile
{
    /**
     * @param array<string, string> $authHeaders
     * @param array<string, string> $query
     * @param list<string> $aliases
     * @param list<string> $cities
     */
    public function __construct(
        public string $key,
        public string $label,
        public SiriService $service,
        public string $endpoint,
        public array $authHeaders,
        public bool $available,
        public ?string $stopRefTemplate = null,
        public ?string $lineRefTemplate = null,
        public array $query = [],
        public array $aliases = [],
        public array $cities = [],
        public bool $cityOptional = false,
    ) {
    }

    public function stopRef(string $ref): ?string
    {
        return $this->applyTemplate($this->stopRefTemplate, $ref);
    }

    public function lineRef(string $ref): ?string
    {
        return $this->applyTemplate($this->lineRefTemplate, $ref);
    }

    private function applyTemplate(?string $template, string $ref): ?string
    {
        return $template === null
            ? null
            : str_replace(['{id}', '{code}'], [trim($ref), SiriRef::code($ref)], $template);
    }
}

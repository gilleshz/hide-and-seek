<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TransitCatalogSuggestionResource;
use App\Dto\TransitCatalogSuggestion;
use App\Exception\FunctionalException;
use App\Service\TransitCatalogService;

/**
 * @implements ProviderInterface<TransitCatalogSuggestionResource>
 */
final readonly class TransitCatalogProvider implements ProviderInterface
{
    private const int DEFAULT_LIMIT = 15;
    private const int MAX_LIMIT = 50;
    private const int MAX_QUERY_LENGTH = 100;
    private const float MAX_SPAN_DEGREES = 10.0;

    public function __construct(
        private TransitCatalogService $catalog,
    ) {
    }

    /** @return list<TransitCatalogSuggestionResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        [$swLat, $swLng, $neLat, $neLng] = $this->parseBbox($filters['bbox'] ?? null);
        $query = $this->parseQuery($filters['q'] ?? null);
        $limit = $this->parseLimit($filters['limit'] ?? null);

        return array_map(
            static fn (TransitCatalogSuggestion $suggestion): TransitCatalogSuggestionResource =>
                TransitCatalogSuggestionResource::fromSuggestion($suggestion),
            $this->catalog->search($swLat, $swLng, $neLat, $neLng, $query, $limit),
        );
    }

    /**
     * @return array{float, float, float, float}
     */
    private function parseBbox(mixed $value): array
    {
        $parts = is_string($value) ? explode(',', $value) : [];
        if (count($parts) !== 4) {
            throw $this->invalidBbox();
        }

        $swLat = $this->coordinate($parts[0]);
        $swLng = $this->coordinate($parts[1]);
        $neLat = $this->coordinate($parts[2]);
        $neLng = $this->coordinate($parts[3]);
        if (
            $swLat < -90.0
            || $neLat > 90.0
            || $swLng < -180.0
            || $neLng > 180.0
            || $swLat >= $neLat
            || $swLng >= $neLng
            || $neLat - $swLat > self::MAX_SPAN_DEGREES
            || $neLng - $swLng > self::MAX_SPAN_DEGREES
        ) {
            throw $this->invalidBbox();
        }

        return [$swLat, $swLng, $neLat, $neLng];
    }

    private function coordinate(string $part): float
    {
        $trimmed = trim($part);
        if (!is_numeric($trimmed) || !is_finite((float) $trimmed)) {
            throw $this->invalidBbox();
        }

        return (float) $trimmed;
    }

    private function parseQuery(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw $this->invalidQuery();
        }

        $query = trim($value);
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw $this->invalidQuery();
        }

        return $query;
    }

    private function parseLimit(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        $limit = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($limit === false || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw $this->invalidQuery();
        }

        return $limit;
    }

    private function invalidBbox(): FunctionalException
    {
        return new FunctionalException(
            message: 'Invalid transit catalog bounding box.',
            errorKey: 'transit_catalog.invalid_bbox',
        );
    }

    private function invalidQuery(): FunctionalException
    {
        return new FunctionalException(
            message: 'Invalid transit catalog search query.',
            errorKey: 'transit_catalog.invalid_query',
        );
    }
}

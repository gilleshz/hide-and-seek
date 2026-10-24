<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Dto\TransitCatalogSuggestion;
use App\Serializer\Group;
use App\State\TransitCatalogProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TransitCatalogSuggestion',
    operations: [
        new GetCollection(
            uriTemplate: '/transit/catalog',
            provider: TransitCatalogProvider::class,
            paginationEnabled: false,
        ),
    ],
    normalizationContext: ['groups' => [Group::TRANSIT_CATALOG_READ]],
)]
final class TransitCatalogSuggestionResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $id;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $provider;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $name;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $municipality;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $countryCode;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public ?string $licenseUrl = null;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $downloadUrl;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public ?string $fallbackUrl = null;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public ?string $realtimeUrl = null;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public bool $live = false;

    #[Groups([Group::TRANSIT_CATALOG_READ])]
    public string $scope;

    public static function fromSuggestion(TransitCatalogSuggestion $suggestion): self
    {
        $resource = new self();
        $resource->id = $suggestion->id;
        $resource->provider = $suggestion->provider;
        $resource->name = $suggestion->name;
        $resource->municipality = $suggestion->municipality;
        $resource->countryCode = $suggestion->countryCode;
        $resource->licenseUrl = $suggestion->licenseUrl;
        $resource->downloadUrl = $suggestion->downloadUrl;
        $resource->fallbackUrl = $suggestion->fallbackUrl;
        $resource->realtimeUrl = $suggestion->realtimeUrl;
        $resource->live = $suggestion->live;
        $resource->scope = $suggestion->scope;

        return $resource;
    }
}

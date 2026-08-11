<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Entity\GtfsSource;
use App\Serializer\Group;
use App\State\GtfsSourceUploadProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'GtfsSource',
    operations: [
        new Post(
            uriTemplate: '/gtfs-sources',
            input: false,
            processor: GtfsSourceUploadProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => [Group::GTFS_SOURCE_READ]],
    denormalizationContext: ['groups' => [Group::GTFS_SOURCE_WRITE]],
)]
final class GtfsSourceResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $uuid;

    #[Groups([Group::GTFS_SOURCE_READ])]
    public string $name;

    /** @var list<GtfsSourceRoute> */
    #[Groups([Group::GTFS_SOURCE_READ])]
    public array $routes = [];

    /**
     * @param list<array{
     *     routeId: string,
     *     shortName: string,
     *     longName: string,
     *     routeType: int,
     *     color: string,
     *     textColor: string
     * }> $routes
     */
    public static function fromSource(GtfsSource $source, array $routes): self
    {
        $resource = new self();
        $resource->uuid = $source->getUuid();
        $resource->name = $source->getName();
        $resource->routes = array_map(static function (array $r): GtfsSourceRoute {
            $route = new GtfsSourceRoute();
            $route->routeId = $r['routeId'];
            $route->shortName = $r['shortName'];
            $route->longName = $r['longName'];
            $route->routeType = $r['routeType'];
            $route->color = $r['color'];
            $route->textColor = $r['textColor'];

            return $route;
        }, $routes);

        return $resource;
    }
}

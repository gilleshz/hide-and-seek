<?php

declare(strict_types=1);

namespace App\Enum;

enum StreetClass: string
{
    case Motorway = 'motorway';
    case Trunk = 'trunk';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Tertiary = 'tertiary';
    case Residential = 'residential';
    case Pedestrian = 'pedestrian';
    case Service = 'service';
    case Track = 'track';
    case Cycleway = 'cycleway';
    case Steps = 'steps';
    case Path = 'path';
    case Sidewalk = 'sidewalk';
    case Crossing = 'crossing';
    case Footway = 'footway';
    case Other = 'other';

    /**
     * A sidewalk or crossing keeps its own class: it is a legitimate answer, and the
     * client de-ranks it so a tap lands on the street.
     *
     * @param array<mixed> $tags
     */
    public static function fromTags(array $tags): self
    {
        $highway = $tags['highway'] ?? null;
        if (!is_string($highway)) {
            return self::Other;
        }

        if ($highway === 'footway') {
            return self::fromFootway($tags['footway'] ?? null);
        }

        return self::fromHighway($highway);
    }

    private static function fromFootway(mixed $footway): self
    {
        return match ($footway) {
            'sidewalk' => self::Sidewalk,
            'crossing' => self::Crossing,
            default => self::Footway,
        };
    }

    private static function fromHighway(string $highway): self
    {
        return match ($highway) {
            'motorway', 'motorway_link' => self::Motorway,
            'trunk', 'trunk_link' => self::Trunk,
            'primary', 'primary_link' => self::Primary,
            'secondary', 'secondary_link' => self::Secondary,
            'tertiary', 'tertiary_link' => self::Tertiary,
            'residential', 'living_street', 'unclassified' => self::Residential,
            'pedestrian' => self::Pedestrian,
            'service' => self::Service,
            'track' => self::Track,
            'cycleway' => self::Cycleway,
            'steps' => self::Steps,
            'path', 'bridleway' => self::Path,
            default => self::Other,
        };
    }
}

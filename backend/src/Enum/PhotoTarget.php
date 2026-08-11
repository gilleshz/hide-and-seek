<?php

declare(strict_types=1);

namespace App\Enum;

enum PhotoTarget: string
{
    case Tree = 'tree';
    case Sky = 'sky';
    case Selfie = 'selfie';
    case WidestStreet = 'widest_street';
    case TallestStructureInSightline = 'tallest_structure_in_sightline';
    case BuildingVisibleFromStation = 'building_visible_from_station';
    case TallestBuildingVisibleFromStation = 'tallest_building_from_station';
    case TraceNearestStreet = 'trace_nearest_street';
    case TwoBuildings = 'two_buildings';
    case RestaurantInterior = 'restaurant_interior';
    case TrainPlatform = 'train_platform';
    case Park = 'park';
    case GroceryStoreAisle = 'grocery_store_aisle';
    case PlaceOfWorship = 'place_of_worship';
    case StreetsTraced = 'streets_traced';
    case TallestMountainVisibleFromStation = 'tallest_mountain_from_station';
    case BiggestBodyOfWaterInZone = 'biggest_body_of_water_in_zone';
    case FiveBuildings = 'five_buildings';

    public function label(Edition $edition): string
    {
        return match ($this) {
            self::Tree => 'a tree',
            self::Sky => 'the sky',
            self::Selfie => 'yourself',
            self::WidestStreet => 'the widest street near you',
            self::TallestStructureInSightline => 'the tallest structure in your sightline',
            self::BuildingVisibleFromStation => 'any building visible from your station',
            self::TallestBuildingVisibleFromStation => 'the tallest building visible from your station',
            self::TraceNearestStreet => 'your nearest street or path, traced',
            self::TwoBuildings => 'two buildings',
            self::RestaurantInterior => 'a restaurant interior',
            self::TrainPlatform => 'a train platform',
            self::Park => 'a park',
            self::GroceryStoreAisle => 'a grocery store aisle',
            self::PlaceOfWorship => 'a place of worship',
            self::StreetsTraced => match ($edition) {
                Edition::Metric => '1 km of streets, traced',
                Edition::Imperial => 'half a mile of streets, traced',
            },
            self::TallestMountainVisibleFromStation => 'the tallest mountain visible from your station',
            self::BiggestBodyOfWaterInZone => 'the biggest body of water in your zone',
            self::FiveBuildings => 'five buildings',
        };
    }

    public function requirement(): ?string
    {
        return match ($this) {
            self::TraceNearestStreet => 'Trace it from intersection to intersection.',
            self::StreetsTraced => 'It must be continuous, with at least 5 turns and no doubling back.',
            default => null,
        };
    }

    public function minimumSize(): GameSize
    {
        return match ($this) {
            self::Tree,
            self::Sky,
            self::Selfie,
            self::WidestStreet,
            self::TallestStructureInSightline,
            self::BuildingVisibleFromStation => GameSize::Small,
            self::TallestBuildingVisibleFromStation,
            self::TraceNearestStreet,
            self::TwoBuildings,
            self::RestaurantInterior,
            self::TrainPlatform,
            self::Park,
            self::GroceryStoreAisle,
            self::PlaceOfWorship => GameSize::Medium,
            self::StreetsTraced,
            self::TallestMountainVisibleFromStation,
            self::BiggestBodyOfWaterInZone,
            self::FiveBuildings => GameSize::Large,
        };
    }

    public function isAvailableFor(GameSize $size): bool
    {
        return $size->ordinal() >= $this->minimumSize()->ordinal();
    }
}

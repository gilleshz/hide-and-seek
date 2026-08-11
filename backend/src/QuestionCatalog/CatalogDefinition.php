<?php

declare(strict_types=1);

namespace App\QuestionCatalog;

use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;

final class CatalogDefinition
{
    /** @return list<CatalogCategory> */
    public static function all(): array
    {
        return [
            self::radar(),
            self::thermometer(),
            self::matching(),
            self::measuring(),
            self::tentacles(),
            self::photos(),
        ];
    }

    /** @return list<CatalogCategory> */
    public static function forGame(GameSize $size, Edition $edition): array
    {
        $categories = [];
        foreach (self::all() as $category) {
            $options = array_filter($category->options, fn(CatalogOption $opt): bool =>
                $opt->availableInSize($size) && $opt->availableInEdition($edition));
            if ($options !== []) {
                $categories[] = new CatalogCategory(
                    key: $category->key,
                    options: array_values($options),
                );
            }
        }

        return $categories;
    }

    private static function radar(): CatalogCategory
    {
        return new CatalogCategory(
            key: QuestionCategory::Radar,
            options: [
                new CatalogOption(label: '500 m', meters: 500.0, edition: Edition::Metric),
                new CatalogOption(label: '1 km', meters: 1000.0, edition: Edition::Metric),
                new CatalogOption(label: '2 km', meters: 2000.0, edition: Edition::Metric),
                new CatalogOption(label: '5 km', meters: 5000.0, edition: Edition::Metric),
                new CatalogOption(label: '10 km', meters: 10000.0, edition: Edition::Metric),
                new CatalogOption(label: '15 km', meters: 15000.0, edition: Edition::Metric),
                new CatalogOption(label: '40 km', meters: 40000.0, edition: Edition::Metric),
                new CatalogOption(label: '80 km', meters: 80000.0, edition: Edition::Metric),
                new CatalogOption(label: '160 km', meters: 160000.0, edition: Edition::Metric),
                new CatalogOption(label: '¼ mi', meters: 402.336, edition: Edition::Imperial),
                new CatalogOption(label: '½ mi', meters: 804.672, edition: Edition::Imperial),
                new CatalogOption(label: '1 mi', meters: 1609.344, edition: Edition::Imperial),
                new CatalogOption(label: '3 mi', meters: 4828.032, edition: Edition::Imperial),
                new CatalogOption(label: '5 mi', meters: 8046.72, edition: Edition::Imperial),
                new CatalogOption(label: '10 mi', meters: 16093.44, edition: Edition::Imperial),
                new CatalogOption(label: '25 mi', meters: 40233.6, edition: Edition::Imperial),
                new CatalogOption(label: '50 mi', meters: 80467.2, edition: Edition::Imperial),
                new CatalogOption(label: '100 mi', meters: 160934.4, edition: Edition::Imperial),
                new CatalogOption(label: 'Custom', meters: null, custom: true),
            ],
        );
    }

    private static function thermometer(): CatalogCategory
    {
        return new CatalogCategory(
            key: QuestionCategory::Thermometer,
            options: [
                new CatalogOption(label: '1 km', meters: 1000.0, minSize: GameSize::Small, edition: Edition::Metric),
                new CatalogOption(label: '5 km', meters: 5000.0, minSize: GameSize::Small, edition: Edition::Metric),
                new CatalogOption(label: '15 km', meters: 15000.0, minSize: GameSize::Medium, edition: Edition::Metric),
                new CatalogOption(label: '75 km', meters: 75000.0, minSize: GameSize::Large, edition: Edition::Metric),
                new CatalogOption(label: '½ mi', meters: 804.672, minSize: GameSize::Small, edition: Edition::Imperial),
                new CatalogOption(label: '3 mi', meters: 4828.032, minSize: GameSize::Small, edition: Edition::Imperial),
                new CatalogOption(label: '10 mi', meters: 16093.44, minSize: GameSize::Medium, edition: Edition::Imperial),
                new CatalogOption(label: '50 mi', meters: 80467.2, minSize: GameSize::Large, edition: Edition::Imperial),
            ],
        );
    }

    private static function matching(): CatalogCategory
    {
        $featureOptions = [
            FeatureType::CommercialAirport,
            FeatureType::AdminBoundary1st, FeatureType::AdminBoundary2nd,
            FeatureType::AdminBoundary3rd, FeatureType::AdminBoundary4th,
            FeatureType::Mountain, FeatureType::Park,
            FeatureType::AmusementPark, FeatureType::Zoo, FeatureType::Aquarium,
            FeatureType::GolfCourse, FeatureType::Museum, FeatureType::MovieTheater,
            FeatureType::Hospital, FeatureType::Library, FeatureType::Consulate,
        ];

        $options = array_map(fn(FeatureType $ft): CatalogOption =>
            new CatalogOption(label: $ft->value, featureType: $ft), $featureOptions);
        array_splice($options, 1, 0, [new CatalogOption(label: 'Transit Line', transitLine: true)]);
        $options[] = new CatalogOption(
            label: "Station's Name Length",
            featureType: FeatureType::TransitStation,
            stationNameLength: true,
        );

        return new CatalogCategory(
            key: QuestionCategory::Matching,
            options: $options,
        );
    }

    private static function measuring(): CatalogCategory
    {
        $featureOptions = [
            FeatureType::CommercialAirport, FeatureType::RailStation,
            FeatureType::HighSpeedRailLine,
            FeatureType::BorderInternational, FeatureType::Border1st, FeatureType::Border2nd,
            FeatureType::Coastline, FeatureType::BodyOfWater,
            FeatureType::Mountain, FeatureType::Park,
            FeatureType::AmusementPark, FeatureType::Zoo, FeatureType::Aquarium,
            FeatureType::GolfCourse, FeatureType::Museum, FeatureType::MovieTheater,
            FeatureType::Hospital, FeatureType::Library, FeatureType::Consulate,
        ];

        $options = array_map(fn(FeatureType $ft): CatalogOption =>
            new CatalogOption(label: $ft->value, featureType: $ft), $featureOptions);
        $options[] = new CatalogOption(label: 'Sea Level', seaLevel: true);

        return new CatalogCategory(
            key: QuestionCategory::Measuring,
            options: $options,
        );
    }

    private static function tentacles(): CatalogCategory
    {
        return new CatalogCategory(
            key: QuestionCategory::Tentacles,
            options: [
                new CatalogOption(
                    label: 'Museum',
                    featureType: FeatureType::Museum,
                    minSize: GameSize::Medium,
                    meters: 2000.0
                ),
                new CatalogOption(
                    label: 'Library',
                    featureType: FeatureType::Library,
                    minSize: GameSize::Medium,
                    meters: 2000.0
                ),
                new CatalogOption(
                    label: 'Movie Theater',
                    featureType: FeatureType::MovieTheater,
                    minSize: GameSize::Medium,
                    meters: 2000.0
                ),
                new CatalogOption(
                    label: 'Hospital',
                    featureType: FeatureType::Hospital,
                    minSize: GameSize::Medium,
                    meters: 2000.0
                ),
                new CatalogOption(
                    label: 'Zoo',
                    featureType: FeatureType::Zoo,
                    minSize: GameSize::Large,
                    meters: 25000.0
                ),
                new CatalogOption(
                    label: 'Aquarium',
                    featureType: FeatureType::Aquarium,
                    minSize: GameSize::Large,
                    meters: 25000.0
                ),
                new CatalogOption(
                    label: 'Amusement Park',
                    featureType: FeatureType::AmusementPark,
                    minSize: GameSize::Large,
                    meters: 25000.0
                ),
                new CatalogOption(
                    label: 'Rail Station',
                    featureType: FeatureType::RailStation,
                    minSize: GameSize::Large,
                    meters: 25000.0
                ),
            ],
        );
    }

    private static function photos(): CatalogCategory
    {
        return new CatalogCategory(
            key: QuestionCategory::Photos,
            options: [
                new CatalogOption(label: 'A Tree', photoTarget: PhotoTarget::Tree),
                new CatalogOption(label: 'The Sky', photoTarget: PhotoTarget::Sky),
                new CatalogOption(label: 'You', photoTarget: PhotoTarget::Selfie),
                new CatalogOption(label: 'Widest Street', photoTarget: PhotoTarget::WidestStreet),
                new CatalogOption(label: 'Tallest Structure in Your Sightline', photoTarget: PhotoTarget::TallestStructureInSightline),
                new CatalogOption(label: 'Any Building Visible from Station', photoTarget: PhotoTarget::BuildingVisibleFromStation),
                new CatalogOption(label: 'Tallest Building Visible from Station', minSize: GameSize::Medium, photoTarget: PhotoTarget::TallestBuildingVisibleFromStation),
                new CatalogOption(label: 'Nearest Street or Path, Traced', minSize: GameSize::Medium, photoTarget: PhotoTarget::TraceNearestStreet),
                new CatalogOption(label: 'Two Buildings', minSize: GameSize::Medium, photoTarget: PhotoTarget::TwoBuildings),
                new CatalogOption(label: 'Restaurant Interior', minSize: GameSize::Medium, photoTarget: PhotoTarget::RestaurantInterior),
                new CatalogOption(label: 'Train Platform', minSize: GameSize::Medium, photoTarget: PhotoTarget::TrainPlatform),
                new CatalogOption(label: 'Park', minSize: GameSize::Medium, photoTarget: PhotoTarget::Park),
                new CatalogOption(label: 'Grocery Store Aisle', minSize: GameSize::Medium, photoTarget: PhotoTarget::GroceryStoreAisle),
                new CatalogOption(label: 'Place of Worship', minSize: GameSize::Medium, photoTarget: PhotoTarget::PlaceOfWorship),
                new CatalogOption(
                    label: '1 km of Streets, Traced',
                    minSize: GameSize::Large,
                    edition: Edition::Metric,
                    photoTarget: PhotoTarget::StreetsTraced,
                ),
                new CatalogOption(
                    label: 'Half a Mile of Streets, Traced',
                    minSize: GameSize::Large,
                    edition: Edition::Imperial,
                    photoTarget: PhotoTarget::StreetsTraced,
                ),
                new CatalogOption(label: 'Tallest Mountain Visible from Station', minSize: GameSize::Large, photoTarget: PhotoTarget::TallestMountainVisibleFromStation),
                new CatalogOption(label: 'Biggest Body of Water in Your Zone', minSize: GameSize::Large, photoTarget: PhotoTarget::BiggestBodyOfWaterInZone),
                new CatalogOption(label: 'Five Buildings', minSize: GameSize::Large, photoTarget: PhotoTarget::FiveBuildings),
            ],
        );
    }
}

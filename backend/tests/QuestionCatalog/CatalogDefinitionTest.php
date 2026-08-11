<?php

declare(strict_types=1);

namespace App\Tests\QuestionCatalog;

use App\Enum\FeatureType;
use App\Enum\QuestionCategory;
use App\QuestionCatalog\CatalogCategory;
use App\QuestionCatalog\CatalogDefinition;
use App\QuestionCatalog\CatalogOption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogDefinition::class)]
final class CatalogDefinitionTest extends TestCase
{
    #[Test]
    public function matchingExposesATransitLineOptionFlaggedAsTransitLine(): void
    {
        $transitLine = null;
        foreach (self::category(QuestionCategory::Matching)->options as $option) {
            if ($option->transitLine) {
                $transitLine = $option;
                break;
            }
        }

        self::assertInstanceOf(CatalogOption::class, $transitLine);
        self::assertSame('Transit Line', $transitLine->label);
        self::assertNull($transitLine->featureType);
    }

    #[Test]
    public function matchingExposesAStationNameLengthOption(): void
    {
        $stationNameLength = null;
        foreach (self::category(QuestionCategory::Matching)->options as $option) {
            if ($option->stationNameLength) {
                $stationNameLength = $option;
                break;
            }
        }

        self::assertInstanceOf(CatalogOption::class, $stationNameLength);
        self::assertSame("Station's Name Length", $stationNameLength->label);
        self::assertSame(FeatureType::TransitStation, $stationNameLength->featureType);
        self::assertTrue($stationNameLength->stationNameLength);
    }

    #[Test]
    public function theTransitLineOptionFollowsCommercialAirport(): void
    {
        $options = self::category(QuestionCategory::Matching)->options;

        self::assertSame(FeatureType::CommercialAirport, $options[0]->featureType);
        self::assertTrue($options[1]->transitLine);
    }

    #[Test]
    public function matchingOmitsTheRemovedFeatureOptions(): void
    {
        $features = self::featureTypes(QuestionCategory::Matching);

        self::assertNotContains(FeatureType::TransitStation, $features);
        self::assertNotContains(FeatureType::RailStation, $features);
        self::assertContains(FeatureType::AdminBoundary1st, $features);
        self::assertContains(FeatureType::AdminBoundary2nd, $features);
        self::assertContains(FeatureType::AdminBoundary3rd, $features);
        self::assertContains(FeatureType::AdminBoundary4th, $features);
        self::assertContains(FeatureType::CommercialAirport, $features);
    }

    #[Test]
    public function measuringExposesASeaLevelOption(): void
    {
        $seaLevel = null;
        foreach (self::category(QuestionCategory::Measuring)->options as $option) {
            if ($option->seaLevel) {
                $seaLevel = $option;
                break;
            }
        }

        self::assertInstanceOf(CatalogOption::class, $seaLevel);
        self::assertSame('Sea Level', $seaLevel->label);
        self::assertNull($seaLevel->featureType);
        self::assertTrue($seaLevel->seaLevel);
    }

    #[Test]
    public function measuringIncludesHighSpeedRailLine(): void
    {
        self::assertContains(FeatureType::HighSpeedRailLine, self::featureTypes(QuestionCategory::Measuring));
    }

    #[Test]
    public function measuringIncludesTheAdminBorderTypes(): void
    {
        $features = self::featureTypes(QuestionCategory::Measuring);

        self::assertContains(FeatureType::BorderInternational, $features);
        self::assertContains(FeatureType::Border1st, $features);
        self::assertContains(FeatureType::Border2nd, $features);
    }

    /** @return list<FeatureType> */
    private static function featureTypes(QuestionCategory $key): array
    {
        $types = [];
        foreach (self::category($key)->options as $option) {
            if ($option->featureType !== null && !$option->stationNameLength) {
                $types[] = $option->featureType;
            }
        }

        return $types;
    }

    private static function category(QuestionCategory $key): CatalogCategory
    {
        foreach (CatalogDefinition::all() as $category) {
            if ($category->key === $key) {
                return $category;
            }
        }

        self::fail(sprintf('No catalog category for %s.', $key->value));
    }
}

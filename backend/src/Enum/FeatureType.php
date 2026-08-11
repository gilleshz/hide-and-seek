<?php

declare(strict_types=1);

namespace App\Enum;

enum FeatureType: string
{
    case TransitStation = 'transit_station';
    case CommercialAirport = 'commercial_airport';
    case RailStation = 'rail_station';
    case MetroLine = 'metro_line';
    case HighSpeedRailLine = 'high_speed_rail_line';
    case Mountain = 'mountain';
    case Park = 'park';
    case BodyOfWater = 'body_of_water';
    case Coastline = 'coastline';
    case AdminBoundary1st = 'admin_boundary_1st';
    case AdminBoundary2nd = 'admin_boundary_2nd';
    case AdminBoundary3rd = 'admin_boundary_3rd';
    case AdminBoundary4th = 'admin_boundary_4th';
    case BorderInternational = 'border_international';
    case Border1st = 'border_admin_1st';
    case Border2nd = 'border_admin_2nd';
    case Hospital = 'hospital';
    case Library = 'library';
    case Museum = 'museum';
    case MovieTheater = 'movie_theater';
    case Zoo = 'zoo';
    case Aquarium = 'aquarium';
    case AmusementPark = 'amusement_park';
    case GolfCourse = 'golf_course';
    case Consulate = 'consulate';

    public function label(): string
    {
        return match ($this) {
            self::TransitStation => 'transit station',
            self::CommercialAirport => 'airport',
            self::RailStation => 'rail station',
            self::MetroLine => 'metro line',
            self::HighSpeedRailLine => 'high-speed rail',
            self::Mountain => 'mountain',
            self::Park => 'park',
            self::BodyOfWater => 'body of water',
            self::Coastline => 'coastline',
            self::AdminBoundary1st => '1st admin division',
            self::AdminBoundary2nd => '2nd admin division',
            self::AdminBoundary3rd => '3rd admin division',
            self::AdminBoundary4th => '4th admin division',
            self::BorderInternational => 'international border',
            self::Border1st => '1st division border',
            self::Border2nd => '2nd division border',
            self::Hospital => 'hospital',
            self::Library => 'library',
            self::Museum => 'museum',
            self::MovieTheater => 'movie theater',
            self::Zoo => 'zoo',
            self::Aquarium => 'aquarium',
            self::AmusementPark => 'amusement park',
            self::GolfCourse => 'golf course',
            self::Consulate => 'consulate',
        };
    }

    public function geometryKind(): string
    {
        return match ($this) {
            self::CommercialAirport,
            self::Park,
            self::BodyOfWater,
            self::AdminBoundary1st,
            self::AdminBoundary2nd,
            self::AdminBoundary3rd,
            self::AdminBoundary4th => 'areal',
            self::MetroLine,
            self::HighSpeedRailLine,
            self::Coastline,
            self::BorderInternational,
            self::Border1st,
            self::Border2nd => 'linear',
            default => 'point',
        };
    }

    public function adminRank(): ?int
    {
        return match ($this) {
            self::AdminBoundary1st => 1,
            self::AdminBoundary2nd => 2,
            self::AdminBoundary3rd => 3,
            self::AdminBoundary4th => 4,
            default => null,
        };
    }

    public static function adminTypeForRank(int $rank): ?FeatureType
    {
        return match ($rank) {
            1 => self::AdminBoundary1st,
            2 => self::AdminBoundary2nd,
            3 => self::AdminBoundary3rd,
            4 => self::AdminBoundary4th,
            default => null,
        };
    }

    public function isAdminBorder(): bool
    {
        return match ($this) {
            self::BorderInternational, self::Border1st, self::Border2nd => true,
            default => false,
        };
    }
}

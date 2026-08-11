<?php

declare(strict_types=1);

namespace App;

use App\Enum\Edition;
use App\Enum\GameSize;

final class RoundTiming
{
    private const array HIDING_PERIOD_MINUTES = [
        GameSize::Small->value => 30,
        GameSize::Medium->value => 60,
        GameSize::Large->value => 180,
    ];

    // Printed on the Move powerup: S 10, M 20, L 60.
    private const array MOVE_PERIOD_MINUTES = [
        GameSize::Small->value => 10,
        GameSize::Medium->value => 20,
        GameSize::Large->value => 60,
    ];

    private const int STANDARD_ANSWER_WINDOW_MINUTES = 5;

    private const array TIME_TRAP_INTERVAL_MINUTES = [
        GameSize::Small->value => 15,
        GameSize::Medium->value => 30,
        GameSize::Large->value => 60,
    ];

    private const array TIME_TRAP_INCREMENT_MINUTES = [
        GameSize::Small->value => 4,
        GameSize::Medium->value => 6,
        GameSize::Large->value => 10,
    ];

    private const array RADIUS_METERS = [
        Edition::Metric->value => [
            GameSize::Small->value => 500.0,
            GameSize::Medium->value => 500.0,
            GameSize::Large->value => 1000.0,
        ],
        Edition::Imperial->value => [
            GameSize::Small->value => 402.336,
            GameSize::Medium->value => 402.336,
            GameSize::Large->value => 804.672,
        ],
    ];

    public static function hidingPeriodMinutes(GameSize $size): int
    {
        return self::HIDING_PERIOD_MINUTES[$size->value];
    }

    public static function movePeriodMinutes(GameSize $size): int
    {
        return self::MOVE_PERIOD_MINUTES[$size->value];
    }

    public static function timeTrapIntervalMinutes(GameSize $size): int
    {
        return self::TIME_TRAP_INTERVAL_MINUTES[$size->value];
    }

    public static function timeTrapIncrementMinutes(GameSize $size): int
    {
        return self::TIME_TRAP_INCREMENT_MINUTES[$size->value];
    }

    public static function defaultRadiusMeters(GameSize $size, Edition $edition): float
    {
        return self::RADIUS_METERS[$edition->value][$size->value];
    }

    public static function standardAnswerWindowMinutes(): int
    {
        return self::STANDARD_ANSWER_WINDOW_MINUTES;
    }

    public static function photoAnswerWindowMinutes(GameSize $size): int
    {
        return match ($size) {
            GameSize::Large => 20,
            default => 10,
        };
    }
}

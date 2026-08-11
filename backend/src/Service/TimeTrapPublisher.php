<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Entity\TimeTrap;
use App\Enum\TimeTrapAction;
use App\RoundTiming;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class TimeTrapPublisher
{
    public function __construct(
        private MercureJwtService $mercure,
        private HubInterface $hub,
    ) {
    }

    public function publish(TimeTrap $trap, TimeTrapAction $action): void
    {
        $round = $trap->getRound();
        $size = $round->getGame()->getSize();
        $payload = json_encode([
            'type' => 'time-trap',
            'action' => $action->value,
            'uuid' => $trap->getUuid(),
            'roundUuid' => $round->getUuid(),
            'stationName' => $trap->getStationName(),
            'lat' => (string) $trap->getPoint()->getLatitude(),
            'lng' => (string) $trap->getPoint()->getLongitude(),
            'placedAt' => $trap->getPlacedAt()->format(DateFormat::ISO8601_UTC),
            'status' => $trap->getStatus()->value,
            'intervalMinutes' => (string) RoundTiming::timeTrapIntervalMinutes($size),
            'incrementMinutes' => (string) RoundTiming::timeTrapIncrementMinutes($size),
            'valueSeconds' => (string) $trap->effectiveValueSecondsAt(new \DateTimeImmutable()),
            'frozenValueSeconds' => self::optionalString($trap->getFrozenValueSeconds()),
            'detectedByName' => $trap->getDetectedByPlayer()?->getDisplayName(),
            'awardedSeconds' => self::optionalString($trap->getAwardedSeconds()),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update($this->mercure->timeTrapTopic($round->getGame(), $round), $payload));
    }

    private static function optionalString(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

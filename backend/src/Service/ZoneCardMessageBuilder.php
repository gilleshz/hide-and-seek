<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MessagePayload;
use App\Entity\HidingZone;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\ZoneCard;
use App\RoundTiming;

final readonly class ZoneCardMessageBuilder
{
    public function __construct(private QuestionMessageFormatter $formatter)
    {
    }

    public function formatDistance(float $meters, Edition $edition): string
    {
        return $this->formatter->formatDistance($meters, $edition);
    }

    public function radiusCardMessage(ZoneCard $card, string $radius): MessagePayload
    {
        return match ($card) {
            ZoneCard::ProsperousHome => new MessagePayload(
                bodyKey: 'zone.prosperous_home',
                bodyArgs: ['radius' => $radius],
                body: sprintf('Curse of the Prosperous Home: the hiding zone is now %s wide.', $radius),
            ),
            ZoneCard::TinyHome => new MessagePayload(
                bodyKey: 'zone.tiny_home',
                bodyArgs: ['radius' => $radius],
                body: sprintf('Curse of the Tiny Home: the hiding zone is now %s wide.', $radius),
            ),
            ZoneCard::Move => throw new \LogicException('Move does not resize the zone.'),
        };
    }

    public function moveMessage(Round $round, ?string $station): MessagePayload
    {
        $minutes = RoundTiming::movePeriodMinutes($round->getGame()->getSize());
        if ($station === null) {
            return new MessagePayload(
                bodyKey: 'zone.move_played',
                bodyArgs: ['min' => $minutes],
                body: sprintf(
                    'Move played: the hiders have %d min to settle somewhere new and the seekers are frozen. '
                    . 'They must reveal the station they are leaving.',
                    $minutes,
                ),
            );
        }

        return new MessagePayload(
            bodyKey: 'zone.move_played_from',
            bodyArgs: ['min' => $minutes, 'station' => $station],
            body: sprintf(
                'Move played: the hiders have %d min to settle somewhere new and the seekers are frozen. '
                . 'They are leaving %s.',
                $minutes,
                $station,
            ),
        );
    }

    public function changeMessage(
        Round $round,
        HidingZone $zone,
        bool $movedStation,
        bool $changedRadius,
    ): MessagePayload {
        $radius = $this->formatter->formatDistance($zone->getRadiusMeters(), $round->getGame()->getEdition());

        return match (true) {
            $movedStation && $changedRadius => new MessagePayload(
                bodyKey: 'zone.moved_and_resized',
                bodyArgs: ['radius' => $radius],
                body: sprintf('The hiding zone moved and is now %s wide.', $radius),
            ),
            $movedStation => new MessagePayload(
                bodyKey: 'zone.moved',
                body: 'The hiding zone moved.',
            ),
            default => new MessagePayload(
                bodyKey: 'zone.resized',
                bodyArgs: ['radius' => $radius],
                body: sprintf('The hiding zone is now %s wide.', $radius),
            ),
        };
    }
}

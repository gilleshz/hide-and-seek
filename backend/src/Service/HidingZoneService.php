<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ZonePlacement;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Side;
use App\Enum\ZoneCard;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\RoundTiming;
use App\Storage\ImageStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class HidingZoneService
{
    public function __construct(
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private RoundMembershipRepository $memberships,
        private HidingZoneRepository $zones,
        private HiderGuard $hiderGuard,
        private ChatService $chatService,
        private ImageStorageInterface $imageStorage,
        private PossibleAreaService $possibleAreas,
        private RoundService $roundService,
        private RoundClock $clock,
        private HidingZonePublisher $publisher,
        private ZoneCardMessageBuilder $zoneCardMessageBuilder,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The Mercure publish fires inside the transaction before commit (same trade-off as
     * QuestionService): a hub failure rolls the zone back, delivered SSE events stay delivered.
     */
    public function setZone(string $roundUuid, string $playerUuid, ZonePlacement $placement): HidingZone
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($roundUuid, $playerUuid, $placement): HidingZone {
                [$round] = $this->resolveHider($roundUuid, $playerUuid);

                $resolvedRadius = $placement->radiusMeters
                    ?? RoundTiming::defaultRadiusMeters($round->getGame()->getSize(), $round->getGame()->getEdition());

                $existing = $this->zones->findOneByRound($round);
                $this->assertFreeToPlace($round, $existing);
                if ($existing === null) {
                    $zone = new HidingZone($round, $placement->point, $resolvedRadius);
                    $zone->setStationName($placement->stationName);
                    $this->zones->save($zone);
                    $this->publisher->publishZone($round, $zone);
                    $this->announceChange($round, $zone, movedStation: true, changedRadius: true);

                    return $zone;
                }

                $movedStation = !self::samePoint($existing->getStationPoint(), $placement->point);
                $changedRadius = $existing->getRadiusMeters() !== $resolvedRadius;

                $existing->setStationPoint($placement->point)->setRadiusMeters($resolvedRadius);
                // A station name only travels with the point it names, so a radius-only call must not clear it.
                if ($movedStation) {
                    $existing->setStationName($placement->stationName);
                }
                $this->zones->save($existing);
                $this->publisher->publishZone($round, $existing);
                $this->announceChange($round, $existing, $movedStation, $changedRadius);

                return $existing;
            },
        );
    }

    /**
     * The zone only reaches hiders live on their own Mercure topic, so a hider whose app restarts has no
     * way back to it. This hands it over once, to a caller the token proves is a hider on this round: the
     * API key is shared and player UUIDs are public, so neither can gate a seeker-proof coordinate.
     */
    public function currentZoneForSubscriber(string $roundUuid): ?HidingZone
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $this->hiderGuard->assertHider($round, 'hiding_zone.not_hider');

        return $this->zones->findOneByRound($round);
    }

    /**
     * Once the seekers are hunting, the zone only changes by playing one of the three physical cards,
     * each backed by a photo of the card.
     */
    public function playCard(
        string $roundUuid,
        string $playerUuid,
        ZoneCard $card,
        UploadedFile $cardPhoto,
    ): HidingZone {
        [$round, $player] = $this->resolveHider($roundUuid, $playerUuid);

        $zone = $this->zones->findOneByRound($round);
        if ($zone === null) {
            throw new FunctionalException(
                message: 'There is no hiding zone to play this card against.',
                errorKey: 'zone_card.no_zone',
            );
        }

        $this->assertPlayable($round, $card);
        $imageRef = $this->imageStorage->store($round->getGame()->getUuid(), $cardPhoto);

        if ($card === ZoneCard::Move) {
            $this->playMove($player, $zone, $imageRef);

            return $zone;
        }

        return $this->playRadiusCard($player, $zone, $card, $imageRef);
    }

    /**
     * @return array{Round, Player}
     */
    private function resolveHider(string $roundUuid, string $playerUuid): array
    {
        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $player = $this->players->findOneByUuid($playerUuid);
        if ($player === null) {
            throw new EntityNotFoundException(message: 'Player not found.', errorKey: 'player.not_found');
        }

        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Hider) {
            throw new FunctionalException(message: 'Only a hider may set the hiding zone.', errorKey: 'hiding_zone.not_hider');
        }

        return [$round, $player];
    }

    /**
     * An existing zone stops being free to nudge once the hunt starts; only a card moves or resizes it.
     * Placing a first zone late stays allowed, otherwise hiders who let the hiding period run out could
     * never set one.
     */
    private function assertFreeToPlace(Round $round, ?HidingZone $existing): void
    {
        if ($existing !== null && $this->clock->isSeeking($round)) {
            throw new FunctionalException(
                message: 'Once the seekers are hunting, the hiding zone only changes by playing a card.',
                errorKey: 'hiding_zone.card_required',
            );
        }
    }

    private function assertPlayable(Round $round, ZoneCard $card): void
    {
        if (!$this->clock->isSeeking($round)) {
            throw new FunctionalException(
                message: 'Zone cards only come into play once the seekers are hunting.',
                errorKey: 'zone_card.not_seeking',
            );
        }

        if ($round->getEndgameStartedAt() !== null && !$card->playableDuringEndgame()) {
            throw new FunctionalException(
                message: 'This card cannot be played during the endgame.',
                errorKey: 'zone_card.endgame',
            );
        }
    }

    private function playRadiusCard(Player $hider, HidingZone $zone, ZoneCard $card, string $imageRef): HidingZone
    {
        $factor = $card->radiusFactor() ?? throw new \LogicException('Only Move carries no radius factor.');
        $round = $zone->getRound();

        $zone->setRadiusMeters($zone->getRadiusMeters() * $factor);
        $this->zones->save($zone);
        $this->publisher->publishZone($round, $zone);

        $payload = $this->zoneCardMessageBuilder->radiusCardMessage(
            $card,
            $this->zoneCardMessageBuilder->formatDistance($zone->getRadiusMeters(), $round->getGame()->getEdition()),
        );
        $this->chatService->postCardPlay(
            game: $round->getGame(),
            sender: $hider,
            imageRef: $imageRef,
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
        );

        return $zone;
    }

    /**
     * The card's price is naming the abandoned station, the one place a hider's station reaches the
     * seekers. It is a past station released by the card itself, never a live position, and the zone
     * that replaces it stays hidden as always.
     */
    private function playMove(Player $hider, HidingZone $zone, string $imageRef): void
    {
        $round = $zone->getRound();
        $station = $zone->getStationName();

        $this->possibleAreas->clearConstraints($round);
        $this->roundService->openMovePeriod($round);

        $payload = $this->zoneCardMessageBuilder->moveMessage($round, $station);
        $this->chatService->postCardPlay(
            game: $round->getGame(),
            sender: $hider,
            imageRef: $imageRef,
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
        );
    }

    private function announceChange(Round $round, HidingZone $zone, bool $movedStation, bool $changedRadius): void
    {
        if ((!$movedStation && !$changedRadius) || !$this->clock->isSeeking($round)) {
            return;
        }

        $payload = $this->zoneCardMessageBuilder->changeMessage($round, $zone, $movedStation, $changedRadius);
        $this->chatService->postSystem(
            game: $round->getGame(),
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
        );
    }

    private static function samePoint(Point $a, Point $b): bool
    {
        return (float) $a->getLatitude() === (float) $b->getLatitude()
            && (float) $a->getLongitude() === (float) $b->getLongitude();
    }
}

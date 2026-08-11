<?php

declare(strict_types=1);

namespace App\Service;

use App\DateFormat;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\SeekerCandidateMarker;
use App\Repository\SeekerCandidateMarkerRepository;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class SeekerCandidateMarkerService
{
    public function __construct(
        private SeekerCandidateMarkerRepository $markers,
        private MercureJwtService $mercure,
        private HubInterface $hub,
    ) {
    }

    public function create(Round $round, Player $player, float $lat, float $lng): SeekerCandidateMarker
    {
        $marker = new SeekerCandidateMarker($round, $player, new Point($lng, $lat));
        $this->markers->save($marker);

        $payload = json_encode([
            'type' => 'seeker-candidate-added',
            'uuid' => $marker->getUuid(),
            'playerUuid' => $player->getUuid(),
            'lat' => $lat,
            'lng' => $lng,
            'createdAt' => $marker->getCreatedAt()->format(DateFormat::ISO8601_UTC),
        ], JSON_THROW_ON_ERROR);

        $this->hub->publish(new Update(
            $this->mercure->seekerCandidatesTopic($round->getGame(), $round),
            $payload,
            private: true,
        ));

        return $marker;
    }

    public function delete(SeekerCandidateMarker $marker): void
    {
        $round = $marker->getRound();
        $topic = $this->mercure->seekerCandidatesTopic($round->getGame(), $round);
        $payload = json_encode([
            'type' => 'seeker-candidate-removed',
            'uuid' => $marker->getUuid(),
        ], JSON_THROW_ON_ERROR);

        $this->markers->remove($marker);

        $this->hub->publish(new Update($topic, $payload, private: true));
    }
}

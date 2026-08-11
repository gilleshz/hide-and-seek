<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\LeaderboardEntryResource;
use App\Entity\Game;
use App\Exception\EntityNotFoundException;
use App\Repository\GameRepository;
use App\Repository\RoundRepository;

/**
 * @implements ProviderInterface<LeaderboardEntryResource>
 */
final readonly class LeaderboardCollectionProvider implements ProviderInterface
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
    ) {
    }

    /**
     * @return list<LeaderboardEntryResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $gameKey = $uriVariables['gameKey'] ?? null;
        $game = is_string($gameKey) ? $this->games->findOneByUuid($gameKey) : null;
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $entries = $this->entries($game);
        // Best score first; round number breaks ties so the ordering is total regardless of page rendering.
        usort(
            $entries,
            static fn (LeaderboardEntryResource $a, LeaderboardEntryResource $b): int
                => [$b->scoreSeconds, $a->roundNumber] <=> [$a->scoreSeconds, $b->roundNumber],
        );

        return $entries;
    }

    /**
     * @return list<LeaderboardEntryResource>
     */
    private function entries(Game $game): array
    {
        $entries = [];
        foreach ($this->rounds->findCaughtByGame($game) as $index => $round) {
            if ($round->getScoreSeconds() === null) {
                continue;
            }
            $entries[] = LeaderboardEntryResource::fromEntity($round, $index + 1);
        }

        return $entries;
    }
}

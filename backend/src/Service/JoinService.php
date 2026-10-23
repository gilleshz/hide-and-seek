<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\JoinResult;
use App\Entity\Game;
use App\Entity\Identity;
use App\Entity\Player;
use App\Entity\Round;
use App\Exception\EntityNotFoundException;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class JoinService
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private EntityManagerInterface $entityManager,
        private RosterNotifier $roster,
        private ChatService $chatService,
    ) {
    }

    /**
     * The identity is resolved from the device key before the join; a seat belongs to that identity,
     * so rejoining (even without a leave in between) requires the same device.
     */
    public function join(string $gameKey, Identity $identity): JoinResult
    {
        $game = $this->games->findOneByUuid($gameKey)
            ?? $this->games->findOneBy(['joinCode' => strtoupper($gameKey)]);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return $this->entityManager->wrapInTransaction(
            fn (): JoinResult => $this->joinTransactional($game, $identity),
        );
    }

    private function joinTransactional(Game $game, Identity $identity): JoinResult
    {
        $seat = $this->players->findOneByGameAndIdentity($game, $identity);
        if ($seat !== null) {
            return new JoinResult($this->rejoin($seat), false);
        }

        return new JoinResult($this->register($game, $identity), true);
    }

    private function rejoin(Player $player): Player
    {
        // Never left: plain reconnect, announcing it would spam the log.
        if (!$player->hasLeft()) {
            return $player;
        }

        $this->players->save($player->markReturned());
        $this->roster->publishChanged($player->getGame());
        $this->announce($player->getGame(), $player->getDisplayName(), isNewPlayer: false);

        return $player;
    }

    private function register(Game $game, Identity $identity): Player
    {
        $player = new Player($game, $identity);
        $this->players->save($player);
        // Roster event first so a client has the name before the announcement lands.
        $this->roster->publishChanged($game);
        $this->announce($game, $identity->getDisplayName(), isNewPlayer: true);

        return $player;
    }

    private function announce(Game $game, string $displayName, bool $isNewPlayer): void
    {
        $this->chatService->postSystem(
            game: $game,
            body: $isNewPlayer ? "{$displayName} joined the game." : "{$displayName} rejoined the game.",
            bodyKey: $isNewPlayer ? 'system.player_joined' : 'system.player_rejoined',
            bodyArgs: ['name' => $displayName],
        );
    }

    public function roundFor(Game $game): Round
    {
        // Rejoin lands on the current round; a stale UUID would 404 every later ping.
        $round = $this->rounds->findActiveByGame($game) ?? $this->rounds->findLatestByGame($game);
        if ($round === null) {
            throw new \LogicException('GameService must create a round alongside every game.');
        }

        return $round;
    }
}

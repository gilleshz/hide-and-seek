<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Exception\EntityNotFoundException;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LeaveService
{
    public function __construct(
        private GameRepository $games,
        private PlayerRepository $players,
        private RosterNotifier $roster,
        private ChatService $chatService,
        private RoundMembershipRepository $memberships,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function leave(string $gameUuid, string $playerUuid): Player
    {
        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->players->findOneByUuid($playerUuid);
        if ($player === null || $player->getGame()->getId() !== $game->getId()) {
            throw new EntityNotFoundException(message: 'Player not found in this game.', errorKey: 'player.not_found');
        }

        // One transaction: an announced departure that did not persist would be a lie in the log.
        $this->entityManager->wrapInTransaction(function () use ($game, $player): void {
            $displayName = $player->getDisplayName();
            $this->chatService->postSystem(
                game: $game,
                body: "{$displayName} left the game.",
                bodyKey: 'system.player_left',
                bodyArgs: ['name' => $displayName],
            );
            // Marked, not deleted: their messages and read receipts wait for them to come back.
            $this->memberships->removeByPlayer($player);
            $this->players->save($player->markLeft(new \DateTimeImmutable()));
        });
        $this->roster->publishChanged($game);

        return $player;
    }

    // Host gate lives in GameService::removePlayer; self-removal is the same as leaving.
    public function remove(string $gameUuid, string $playerUuid, string $actingPlayerUuid): Player
    {
        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $player = $this->players->findOneByUuid($playerUuid);
        if ($player === null || $player->getGame()->getId() !== $game->getId()) {
            throw new EntityNotFoundException(message: 'Player not found in this game.', errorKey: 'player.not_found');
        }

        $removedByHost = $actingPlayerUuid !== $player->getUuid();
        $this->entityManager->wrapInTransaction(function () use ($game, $player, $removedByHost): void {
            $displayName = $player->getDisplayName();
            $this->chatService->postSystem(
                game: $game,
                body: $removedByHost ? "{$displayName} was removed from the game." : "{$displayName} left the game.",
                bodyKey: $removedByHost ? 'system.player_removed' : 'system.player_left',
                bodyArgs: ['name' => $displayName],
            );
            $this->memberships->removeByPlayer($player);
            $this->players->save($player->markLeft(new \DateTimeImmutable()));
        });
        $this->roster->publishChanged($game);

        return $player;
    }
}

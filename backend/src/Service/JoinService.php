<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\JoinResult;
use App\Entity\Game;
use App\Entity\Identity;
use App\Entity\Player;
use App\Entity\Round;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameRepository;
use App\Repository\IdentityRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class JoinService
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private IdentityRepository $identities,
        private EntityManagerInterface $entityManager,
        private RosterNotifier $roster,
        private ChatService $chatService,
    ) {
    }

    /**
     * An identity is the server-wide name, authenticated by an opaque device credential: rejoining
     * (even without a leave in between) requires the same device key, so name-only takeovers die.
     * The host can flag an identity so its owner can move to a new device.
     */
    public function join(string $gameKey, string $name, string $deviceKey): JoinResult
    {
        $game = $this->games->findOneByUuid($gameKey)
            ?? $this->games->findOneBy(['joinCode' => strtoupper($gameKey)]);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return $this->entityManager->wrapInTransaction(
            fn (): JoinResult => $this->joinTransactional($game, $name, $deviceKey),
        );
    }

    private function joinTransactional(Game $game, string $name, string $deviceKey): JoinResult
    {
        $identity = $this->resolveIdentity($name, $deviceKey);
        $seat = $this->players->findOneByGameAndIdentity($game, $identity);
        if ($seat !== null) {
            return new JoinResult($this->rejoin($seat), false);
        }

        return new JoinResult($this->register($game, $identity), true);
    }

    private function resolveIdentity(string $name, string $deviceKey): Identity
    {
        $name = trim($name);
        // The xact lock plus READ COMMITTED makes a unique-violation retry unnecessary.
        $this->identities->lockName($name);
        $identity = $this->identities->findByName($name);
        if ($identity === null) {
            $identity = new Identity($name, $deviceKey);
            $this->identities->save($identity);

            return $identity;
        }
        if ($identity->credentialMatches($deviceKey)) {
            return $identity;
        }
        if ($identity->isCredentialResetRequested()) {
            $this->identities->save($identity->replaceCredential($deviceKey));

            return $identity;
        }

        throw new FunctionalException(
            'This name is already used on another device. Ask the person who runs your game server to allow a new device, or pick a different name.',
            ErrorKey::JOIN_DEVICE_MISMATCH,
        );
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
        $this->announce($game, $identity->getName(), isNewPlayer: true);

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

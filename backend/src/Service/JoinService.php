<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\JoinResult;
use App\Entity\Account;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\AccountRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class JoinService
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
        private PlayerRepository $players,
        private AccountRepository $accounts,
        private EntityManagerInterface $entityManager,
        private RosterNotifier $roster,
        private ChatService $chatService,
    ) {
    }

    /**
     * An account is the server-wide identity, the password authenticates it: reconnecting (even
     * without a leave in between) requires the password chosen at first join, so name-only
     * takeovers die.
     */
    public function join(string $gameKey, string $name, ?string $password = null): JoinResult
    {
        $game = $this->games->findOneByUuid($gameKey)
            ?? $this->games->findOneBy(['joinCode' => strtoupper($gameKey)]);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        return $this->entityManager->wrapInTransaction(
            fn (): JoinResult => $this->joinTransactional($game, $name, $password),
        );
    }

    private function joinTransactional(Game $game, string $name, ?string $password): JoinResult
    {
        $account = $this->resolveAccount($name, $password);
        $seat = $this->players->findOneByGameAndAccount($game, $account);
        if ($seat !== null) {
            return new JoinResult($this->rejoin($seat), false);
        }

        return new JoinResult($this->register($game, $account), true);
    }

    private function resolveAccount(string $name, ?string $password): Account
    {
        $this->accounts->lockName($name);
        $account = $this->accounts->findByName($name);
        if ($account === null) {
            if ($password === null || $password === '') {
                throw new FunctionalException(
                    message: 'A password is required to join this game.',
                    errorKey: ErrorKey::JOIN_PASSWORD_REQUIRED,
                );
            }

            return $this->createAccount($name, $password);
        }
        if ($password === null || $password === '') {
            throw new FunctionalException(
                message: "This name is already used by another player. If it's yours, enter its password. Otherwise, pick a different name.",
                errorKey: ErrorKey::JOIN_PASSWORD_REQUIRED,
            );
        }
        if (!$account->passwordMatches($password)) {
            throw new FunctionalException(
                message: "Wrong password for this name. If it's not your name, pick a different one.",
                errorKey: ErrorKey::JOIN_PASSWORD_INVALID,
            );
        }

        return $account;
    }

    private function createAccount(string $name, string $password): Account
    {
        $account = new Account($name, $password);
        try {
            $this->accounts->save($account);
        } catch (UniqueConstraintViolationException) {
            // The advisory lock serialises join-vs-join, so the loser here raced the account API:
            // the failed flush closed the entity manager, so surface the retryable 'name taken'
            // error and let the client rejoin (the retry then verifies against the committed account).
            throw new FunctionalException(
                message: "This name is already used by another player. If it's yours, enter its password. Otherwise, pick a different name.",
                errorKey: ErrorKey::JOIN_PASSWORD_REQUIRED,
            );
        }

        return $account;
    }

    private function rejoin(Player $player): Player
    {
        // Never left: plain reconnect, announcing it would spam the log.
        if (!$player->hasLeft()) {
            return $player;
        }

        $this->players->save($player->markReturned());
        $this->roster->publishChanged($player->getGame());
        $this->announce($player->getGame(), $player->getAccount()->getName(), isNewPlayer: false);

        return $player;
    }

    private function register(Game $game, Account $account): Player
    {
        $player = new Player($game, $account);
        $this->players->save($player);
        // Roster event first so a client has the name before the announcement lands.
        $this->roster->publishChanged($game);
        $this->announce($game, $account->getName(), isNewPlayer: true);

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

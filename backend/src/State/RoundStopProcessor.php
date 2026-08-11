<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RoundResource;
use App\Dto\RoundStopInput;
use App\Dto\ScoreBonus;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\RoundService;

/**
 * @implements ProcessorInterface<mixed, RoundResource>
 */
final readonly class RoundStopProcessor implements ProcessorInterface
{
    public function __construct(
        private RoundService $roundService,
        private RoundRepository $rounds,
        private IdentityResolver $identity,
        private RoundMembershipRepository $memberships,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): RoundResource {
        $round = $this->resolveRound($uriVariables);
        $player = $this->identity->requirePlayer();
        $this->assertSameGame($round, $player);

        $stop = $data instanceof RoundStopInput ? $data : new RoundStopInput();
        // A declared find scores the hiders' bonuses: only a hider may press it (SCORE-6).
        if ($stop->caught) {
            $this->assertHider($round, $player);
        }

        return RoundResource::fromEntity(
            $this->roundService->stop($round->getUuid(), self::bonus($stop), $stop->hidingSeconds, $stop->caught),
        );
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function resolveRound(array $uriVariables): Round
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        return $round;
    }

    private function assertSameGame(Round $round, Player $player): void
    {
        if ($round->getGame()->getUuid() !== $player->getGame()->getUuid()) {
            throw new FunctionalException(
                message: 'Player does not belong to this round\'s game.',
                errorKey: 'round.player_wrong_game',
            );
        }
    }

    private function assertHider(Round $round, Player $player): void
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership?->getSide() !== Side::Hider) {
            throw new FunctionalException(
                message: 'Only a hider on this round may declare a scored end.',
                errorKey: 'round.not_hider',
            );
        }
    }

    private static function bonus(RoundStopInput $stop): ScoreBonus
    {
        return new ScoreBonus($stop->bonusMinutes ?? 0, $stop->bonusPercent ?? 0);
    }
}

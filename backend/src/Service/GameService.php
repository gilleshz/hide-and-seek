<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GameCleanupResult;
use App\Dto\GameConfigInput;
use App\Dto\GameInput;
use App\Entity\Game;
use App\Entity\GameArea;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\RoundStatus;
use App\ErrorKey;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\GameAreaRepository;
use App\Repository\GameRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class GameService
{
    public function __construct(
        private GameRepository $games,
        private RoundRepository $rounds,
        private GameAreaRepository $gameAreas,
        private PlayerRepository $players,
        private NominatimService $nominatim,
        private AdminLevelResolver $adminLevelResolver,
        private EntityManagerInterface $entityManager,
        #[Autowire('%app.max_boundary_span_deg%')]
        private float $maxBoundarySpanDeg,
        private HeavyWorkGuard $heavyWork,
        private GameTransitBuilder $transitBuilder,
        private GameCleanupService $cleanupService,
        private LeaveService $leaveService,
    ) {
    }

    public function create(GameInput $input): Game
    {
        return $this->heavyWork->run(fn () => $this->entityManager->wrapInTransaction(function () use ($input): Game {
            $game = new Game($input->name, $input->size, $input->edition);
            $game->setJoinCode($this->generateJoinCode());
            $this->games->save($game);

            if ($input->areas !== []) {
                $this->buildBoundary($game, $input->areas);
                $game->setAdminLevels($this->adminLevelResolver->resolve($game));
            }

            $hasSelectedLines = $input->selectedTransitLines !== [] || $input->selectedGtfsLines !== [];
            if ($game->getBoundarySwLat() !== null && $hasSelectedLines) {
                $this->transitBuilder->buildForGame($game, $input->selectedTransitLines, $input->selectedGtfsLines);
            }

            $this->rounds->save(new Round($game));

            return $game;
        }));
    }

    public function delete(string $uuid, string $playerUuid): void
    {
        $game = $this->games->findOneByUuid($uuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $this->assertHost($game, $playerUuid);

        $this->cleanupService->purge($game);
    }

    public function cleanup(?\DateTimeImmutable $createdBefore, bool $includeInProgress): GameCleanupResult
    {
        $purged = [];
        $skipped = 0;
        foreach ($this->games->findAllCreatedBefore($createdBefore) as $game) {
            if (!$includeInProgress && $this->rounds->hasRunningRound($game)) {
                ++$skipped;
                continue;
            }
            $purged[] = $this->cleanupService->purge($game);
        }

        return new GameCleanupResult($purged, $skipped);
    }

    private function generateJoinCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; ++$i) {
            $code = '';
            for ($j = 0; $j < 6; ++$j) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if (!$this->games->findOneBy(['joinCode' => $code])) {
                return $code;
            }
        }
        throw new \RuntimeException('Failed to generate a unique join code after ' . $maxAttempts . ' attempts.');
    }

    public function updateConfig(string $uuid, GameConfigInput $input, string $playerUuid): Game
    {
        $game = $this->games->findOneByUuid($uuid);
        if ($game === null) {
            throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        }

        $this->assertHost($game, $playerUuid);

        $this->applyName($game, $input);
        $this->applyStructural($game, $input);
        $this->games->save($game);

        return $game;
    }

    // The first player to join owns the game; roster membership makes this the same-game check too.
    private function assertHost(Game $game, string $playerUuid, string $errorKey = 'game.only_host_can_delete'): void
    {
        $roster = $this->players->findByGameOrdered($game);
        if ($roster === []) {
            throw new FunctionalException(message: 'Game has no players.', errorKey: 'game.no_players');
        }

        if ($roster[0]->getUuid() !== $playerUuid) {
            throw new FunctionalException(
                message: 'Only the host (first player) can manage the game.',
                errorKey: $errorKey,
            );
        }
    }

    public function removePlayer(string $gameUuid, string $playerUuid, string $actingPlayerUuid): Player
    {
        $game = $this->games->findOneByUuid($gameUuid)
            ?? throw new EntityNotFoundException(message: 'Game not found.', errorKey: 'game.not_found');
        $this->assertHost($game, $actingPlayerUuid, ErrorKey::PLAYER_REMOVE_NOT_HOST);

        return $this->leaveService->remove($gameUuid, $playerUuid, $actingPlayerUuid);
    }

    private function applyName(Game $game, GameConfigInput $input): void
    {
        if ($input->name !== null) {
            $game->setName($input->name);
        }
    }

    /**
     * The boundary is deliberately absent here: it is the union of the selected areas, and a bbox
     * written on its own would contradict the stored polygon, the GameArea rows and the clip the
     * transit tiles were built against. Editing it means re-deriving all of those.
     */
    private function applyStructural(Game $game, GameConfigInput $input): void
    {
        if ($input->size === null && $input->edition === null) {
            return;
        }

        $active = $this->rounds->findActiveByGame($game);
        $locked = $active !== null && $active->getStatus() !== RoundStatus::Lobby;
        if ($locked) {
            throw new FunctionalException(message: 'Structural changes are blocked during an active round.', errorKey: 'game.structural_changes_blocked');
        }

        if ($input->size !== null) {
            $game->setSize($input->size);
        }
        if ($input->edition !== null) {
            $game->setEdition($input->edition);
        }
    }

    /** @param list<array<string, mixed>> $areas */
    private function buildBoundary(Game $game, array $areas): void
    {
        $refs = array_map(fn(array $a): array => [
            'osmType' => isset($a['osmType']) && is_string($a['osmType']) ? $a['osmType'] : 'relation',
            'osmId' => isset($a['osmId']) && is_numeric($a['osmId']) ? (int) $a['osmId'] : 0,
        ], $areas);
        $geometries = $this->nominatim->fetchAreaGeometry($refs);

        if ($geometries === []) {
            throw new FunctionalException(message: 'No geometry found for the selected areas.', errorKey: 'game.no_geometry');
        }

        $geoJsonStrings = [];
        foreach ($geometries as $geom) {
            $area = new GameArea(
                $game,
                (string) $geom['osmType'],
                (int) $geom['osmId'],
                (string) $geom['name'],
                isset($geom['adminLevel']) ? (int) $geom['adminLevel'] : null,
            );
            $this->gameAreas->insert($area);
            $geoJsonStrings[] = $geom['geoJson'];
        }

        $boundaryGeoJson = $this->gameAreas->unionGeoJsonToGeometryString($geoJsonStrings);
        $game->setBoundaryGeoJson($boundaryGeoJson);
        $this->games->save($game, flush: true);
        $this->games->updateBoundaryFromGeoJson($game, $boundaryGeoJson ?? '');
        $this->entityManager->refresh($game);

        $span = $this->games->getBoundarySpan($game);
        if ($span !== null && ($span[0] > $this->maxBoundarySpanDeg || $span[1] > $this->maxBoundarySpanDeg)) {
            throw new FunctionalException(
                message: sprintf('Boundary is too large (%.1f° x %.1f°). Choose a smaller set of areas.', $span[0], $span[1]),
                errorKey: 'game.boundary_too_large',
            );
        }
    }
}

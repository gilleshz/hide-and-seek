<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\FeatureCollectionResource;
use App\Enum\FeatureType;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\FeatureRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Service\HeavyWorkGuard;
use App\Service\IdentityResolver;
use App\Service\OverpassService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<FeatureCollectionResource>
 */
final readonly class FeatureCollectionProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private FeatureRepository $features,
        private RoundMembershipRepository $memberships,
        private OverpassService $overpassService,
        private HeavyWorkGuard $heavyWork,
        private LoggerInterface $logger,
        private IdentityResolver $identity,
    ) {
    }

    /**
     * @return list<FeatureCollectionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        if (!is_string($roundUuid)) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $round = $this->rounds->findOneByUuid($roundUuid);
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $request = $context['request'] ?? null;
        $typeParam = $request instanceof Request ? $request->query->getString('type') : '';

        if ($typeParam === '') {
            throw new FunctionalException(message: 'Feature type is required.', errorKey: 'feature_collection.type_required');
        }

        $type = FeatureType::tryFrom($typeParam);
        if ($type === null) {
            throw new FunctionalException(message: 'Unknown feature type.', errorKey: 'feature_collection.unknown_type');
        }

        $player = $this->identity->requirePlayer();
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership?->getSide() !== Side::Seeker) {
            throw new FunctionalException(message: 'Only a seeker may query features.', errorKey: 'feature_collection.seeker_only');
        }

        $game = $round->getGame();
        if ($this->features->countByGameAndType($game, $type) === 0) {
            try {
                $this->heavyWork->run(fn () => $this->overpassService->ingestFeatureType($game, $type));
            } catch (\RuntimeException $e) {
                $this->logger->error('Lazy feature ingest failed', ['featureType' => $type->value, 'gameUuid' => $game->getUuid(), 'exception' => $e]);
                return [];
            }
        }

        $features = $this->features->findByGameAndType($game, $type);

        return array_map(
            static fn (array $row): FeatureCollectionResource => FeatureCollectionResource::fromFeature(
                (string) $row['uuid'],
                $row['name'] ?? null,
                (float) $row['lat'],
                (float) $row['lng'],
            ),
            $features,
        );
    }
}

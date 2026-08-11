<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\GameConfigInput;
use App\Dto\GameInput;
use App\Entity\Game;
use App\Entity\GameTransitLine;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\RoundTiming;
use App\Serializer\Group;
use App\State\GameDeleteProcessor;
use App\State\GamePatchProcessor;
use App\State\GamePostProcessor;
use App\State\GameProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'Game',
    operations: [
        new Post(
            uriTemplate: '/games',
            input: GameInput::class,
            processor: GamePostProcessor::class,
        ),
        new Get(
            uriTemplate: '/games/{uuid}',
            provider: GameProvider::class,
        ),
        new Patch(
            uriTemplate: '/games/{uuid}',
            input: GameConfigInput::class,
            provider: GameProvider::class,
            processor: GamePatchProcessor::class,
        ),
        new Post(
            uriTemplate: '/games/{uuid}/delete',
            input: false,
            provider: GameProvider::class,
            processor: GameDeleteProcessor::class,
            status: 204,
        ),
    ],
    normalizationContext: ['groups' => [Group::GAME_READ]],
    denormalizationContext: ['groups' => [Group::GAME_WRITE]],
)]
final class GameResource
{
    #[ApiProperty(identifier: true)]
    #[Groups([Group::GAME_READ])]
    public string $uuid;

    #[Groups([Group::GAME_READ])]
    public string $joinCode = '';

    #[Groups([Group::GAME_READ])]
    public string $name;

    #[Groups([Group::GAME_READ])]
    public GameSize $size;

    #[Groups([Group::GAME_READ])]
    public Edition $edition;

    #[Groups([Group::GAME_READ])]
    public int $defaultHidingPeriodMinutes;

    #[Groups([Group::GAME_READ])]
    public \DateTimeImmutable $createdAt;

    #[Groups([Group::GAME_READ])]
    public string $roundUuid;

    #[Groups([Group::GAME_READ])]
    public ?float $boundarySwLat;

    #[Groups([Group::GAME_READ])]
    public ?float $boundarySwLng;

    #[Groups([Group::GAME_READ])]
    public ?float $boundaryNeLat;

    #[Groups([Group::GAME_READ])]
    public ?float $boundaryNeLng;

    #[Groups([Group::GAME_READ])]
    public ?string $boundaryGeoJson = null;

    #[Groups([Group::GAME_READ])]
    public ?string $transitTilesPath = null;

    /**
     * @var list<array{
     *     osmType: string, osmId: int, ref: string, name: string,
     *     colour: string, routeType: string, network: string, operator: string
     * }>
     */
    #[Groups([Group::GAME_READ])]
    public array $selectedTransitLines = [];

    /**
     * @param list<GameTransitLine> $transitLines
     */
    public static function fromEntity(Game $game, Round $round, array $transitLines = []): self
    {
        $self = new self();
        $self->uuid = $game->getUuid();
        $self->joinCode = $game->getJoinCode() ?? '';
        $self->name = $game->getName();
        $self->size = $game->getSize();
        $self->edition = $game->getEdition();
        $self->defaultHidingPeriodMinutes = RoundTiming::hidingPeriodMinutes($game->getSize());
        $self->createdAt = $game->getCreatedAt();
        $self->roundUuid = $round->getUuid();
        $self->boundarySwLat = $game->getBoundarySwLat();
        $self->boundarySwLng = $game->getBoundarySwLng();
        $self->boundaryNeLat = $game->getBoundaryNeLat();
        $self->boundaryNeLng = $game->getBoundaryNeLng();
        $self->boundaryGeoJson = $game->getBoundaryGeoJson();
        $self->transitTilesPath = $game->getTransitTilesPath();
        $self->selectedTransitLines = array_map(static fn (GameTransitLine $line): array => [
            'osmType' => $line->getOsmType(),
            'osmId' => $line->getOsmId(),
            'ref' => $line->getRef(),
            'name' => $line->getName(),
            'colour' => $line->getColour() ?? '',
            'routeType' => $line->getRouteType(),
            'network' => $line->getNetwork(),
            'operator' => $line->getOperator() ?? '',
        ], $transitLines);

        return $self;
    }
}

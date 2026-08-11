<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Edition;
use App\Enum\GameSize;
use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class GameInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups([Group::GAME_WRITE])]
    public string $name = '';

    #[Groups([Group::GAME_WRITE])]
    public GameSize $size = GameSize::Medium;

    #[Groups([Group::GAME_WRITE])]
    public Edition $edition = Edition::Metric;

    /** @var list<array<string, mixed>> */
    #[Assert\Count(max: 12)]
    #[Groups([Group::GAME_WRITE])]
    public array $areas = [];

    // Refs travel as quoted argv, so printable chars are safe; only control chars and over-length are refused.
    /** @var list<array<string, mixed>> */
    #[Assert\Count(max: 100)]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'osmType' => new Assert\Optional([
                    new Assert\Choice(choices: ['relation', 'way', 'node']),
                    new Assert\Length(max: 16),
                ]),
                'osmId' => new Assert\Optional([
                    new Assert\Type('int'),
                    new Assert\GreaterThanOrEqual(0),
                ]),
                'ref' => new Assert\Optional(new Assert\Regex('/^[^\x00-\x1F\x7F]{0,50}$/')),
                'name' => new Assert\Optional(new Assert\Length(max: 200)),
                'routeType' => new Assert\Optional(new Assert\Length(max: 30)),
                'network' => new Assert\Optional(new Assert\Length(max: 255)),
                'colour' => new Assert\Optional(new Assert\Length(max: 20)),
                'operator' => new Assert\Optional(new Assert\Length(max: 255)),
            ],
            allowExtraFields: true,
        ),
    ])]
    #[Groups([Group::GAME_WRITE])]
    public array $selectedTransitLines = [];

    /** @var list<array{gtfsSourceUuid: string, routeId: string, ref: string, name: string, colour: string, routeType: string, network: string, operator: string}> */
    #[Assert\Count(max: 100)]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'gtfsSourceUuid' => new Assert\Optional(new Assert\Uuid()),
                'routeId' => new Assert\Optional(new Assert\Length(max: 200)),
                'ref' => new Assert\Optional(new Assert\Regex('/^[^\x00-\x1F\x7F]{0,50}$/')),
                'name' => new Assert\Optional(new Assert\Length(max: 200)),
                'routeType' => new Assert\Optional(new Assert\Length(max: 30)),
                'network' => new Assert\Optional(new Assert\Length(max: 255)),
                'colour' => new Assert\Optional(new Assert\Length(max: 20)),
                'operator' => new Assert\Optional(new Assert\Length(max: 255)),
            ],
            allowExtraFields: true,
        ),
    ])]
    #[Groups([Group::GAME_WRITE])]
    public array $selectedGtfsLines = [];
}

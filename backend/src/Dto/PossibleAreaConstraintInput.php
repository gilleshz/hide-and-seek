<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class PossibleAreaConstraintInput
{
    #[Assert\NotBlank]
    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_WRITE])]
    public string $geoJson = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['include', 'exclude'])]
    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_WRITE])]
    public string $mode = 'include';

    #[Groups([Group::POSSIBLE_AREA_CONSTRAINT_WRITE])]
    public ?string $label = null;
}

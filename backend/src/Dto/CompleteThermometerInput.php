<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class CompleteThermometerInput
{
    #[Assert\NotNull]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $endLat = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups([Group::QUESTION_WRITE])]
    public ?float $endLng = null;
}

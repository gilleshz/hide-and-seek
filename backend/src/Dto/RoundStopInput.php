<?php

declare(strict_types=1);

namespace App\Dto;

use App\Serializer\Group;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class RoundStopInput
{
    #[Assert\Range(min: 0, max: 1440)]
    #[Groups([Group::ROUND_WRITE])]
    public ?int $bonusMinutes = null;

    #[Assert\Range(min: 0, max: 1000)]
    #[Groups([Group::ROUND_WRITE])]
    public ?int $bonusPercent = null;

    /** Hiding time shown on screen when the clock froze to collect the bonuses. */
    #[Assert\PositiveOrZero]
    #[Groups([Group::ROUND_WRITE])]
    public ?int $hidingSeconds = null;

    /** Set by the end-round dialog; the lobby's abort button does not. */
    #[Groups([Group::ROUND_WRITE])]
    public bool $caught = false;
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

final readonly class JoinResult
{
    public function __construct(
        public Player $player,
        public bool $isNew,
    ) {
    }
}

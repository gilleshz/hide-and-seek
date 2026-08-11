<?php

declare(strict_types=1);

namespace App\Service;

use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\HeavyWorkLockRepository;

/**
 * Serialises the expensive jobs (game creation, Overpass ingests) so a burst of requests cannot
 * exhaust the box or hammer the Overpass mirrors with concurrent multi-minute fetches.
 */
final readonly class HeavyWorkGuard
{
    public function __construct(private HeavyWorkLockRepository $locks)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        if (!$this->locks->tryAcquireGlobal()) {
            throw new FunctionalException(
                message: 'A heavy build or ingest is already running; retry shortly.',
                errorKey: ErrorKey::HEAVY_WORK_BUSY,
            );
        }

        try {
            return $work();
        } finally {
            $this->locks->releaseGlobal();
        }
    }
}

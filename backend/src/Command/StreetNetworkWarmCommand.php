<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RoundStreetNetwork;
use App\Repository\RoundStreetNetworkRepository;
use App\Service\StreetNetworkFillSpawner;
use App\Service\StreetNetworkService;
use App\StreetNetworkRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Database only: it shares the `scheduler_default` consumer with `app:round:tick`, so an Overpass fetch
 * here would stall live games' phase transitions for minutes. `app:street-network:fill` does the
 * blocking work in a detached child.
 */
#[AsCommand(
    name: 'app:street-network:warm',
    description: 'Hand every enqueued hiding zone to a detached street-network fetch.',
)]
#[AsPeriodicTask(frequency: '30 seconds')]
final class StreetNetworkWarmCommand extends Command
{
    public function __construct(
        private RoundStreetNetworkRepository $networks,
        private StreetNetworkService $streetNetworkService,
        private StreetNetworkFillSpawner $spawner,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $handled = 0;
        foreach ($this->networks->findPendingForWarm(StreetNetworkRules::WARM_BATCH_LIMIT) as $network) {
            $handled += $this->handle($network);
        }

        if ($handled > 0) {
            new SymfonyStyle($input, $output)->success(sprintf('Handled %d street network row(s).', $handled));
        }

        return Command::SUCCESS;
    }

    private function handle(RoundStreetNetwork $network): int
    {
        try {
            if ($this->streetNetworkService->retireIfFinished($network)) {
                return 1;
            }

            // Probe the fill's per-round advisory lock: a fill in flight would only exit at it.
            $round = $network->getRound();
            if ($this->networks->acquireWarmLock($round)) {
                $this->networks->releaseWarmLock($round);
                $this->spawner->spawn($network->getUuid());
            }

            return 1;
        } catch (\Throwable $e) {
            $this->logger->warning('Street-network warm skipped row {uuid}: {reason}', [
                'uuid' => $network->getUuid(),
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        }
    }
}

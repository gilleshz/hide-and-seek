<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GtfsSourceRepository;
use App\Repository\PlayerLocationRepository;
use App\Service\GtfsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsCommand(
    name: 'app:locations:purge',
    description: 'Delete location history from ended rounds and orphaned GTFS uploads older than 7 days.',
)]
#[AsPeriodicTask(frequency: '6 hours', jitter: 300)]
final class LocationPurgeCommand extends Command
{
    private const string RETENTION_WINDOW = '-7 days';

    public function __construct(
        private PlayerLocationRepository $locations,
        private GtfsSourceRepository $sources,
        private GtfsService $gtfsService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = new \DateTimeImmutable(self::RETENTION_WINDOW);

        $deletedLocations = $this->locations->deleteStale($before);

        $deletedSources = 0;
        foreach ($this->sources->findOrphansCreatedBefore($before) as $source) {
            $this->gtfsService->deleteFile($source);
            $this->sources->remove($source);
            ++$deletedSources;
        }

        $this->logger->info(
            'Location purge: deleted {locations} stale locations and {sources} orphaned GTFS sources.',
            ['locations' => $deletedLocations, 'sources' => $deletedSources],
        );

        return Command::SUCCESS;
    }
}

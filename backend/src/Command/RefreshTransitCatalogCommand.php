<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TransitCatalogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Boot warm-up and on-demand refresh. It is deliberately not a periodic task: the
 * shared scheduler consumer is sequential, and a catalog download there would stall
 * app:round:tick and its auto-reveals. A stale snapshot refreshes lazily on search.
 */
#[AsCommand(
    name: 'app:transit-catalog:refresh',
    description: 'Download the Mobility Database GTFS catalog snapshot.',
)]
final class RefreshTransitCatalogCommand extends Command
{
    public function __construct(
        private readonly TransitCatalogService $catalog,
        #[Autowire('%app.transit_catalog_file%')]
        private readonly string $catalogFile,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $rowCount = $this->catalog->refresh();
        } catch (\Throwable $e) {
            $this->logger->error('Transit catalog refresh failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            new SymfonyStyle($input, $output)->error('Transit catalog refresh failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        new SymfonyStyle($input, $output)->success(
            sprintf('Refreshed %d transit catalog row(s) into %s.', $rowCount, $this->catalogFile),
        );

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StreetNetworkService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliberately not a periodic task: `app:street-network:warm` spawns one detached instance per row so the
 * Overpass fetch never occupies the scheduler consumer.
 */
#[AsCommand(
    name: 'app:street-network:fill',
    description: 'Fetch and cache the OSM street network of one enqueued hiding zone.',
)]
final class StreetNetworkFillCommand extends Command
{
    public function __construct(
        private StreetNetworkService $streetNetworkService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'The round street network uuid to fetch.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        if (!is_string($uuid) || $uuid === '') {
            return Command::INVALID;
        }

        try {
            $this->streetNetworkService->fill($uuid);
        } catch (\Throwable $e) {
            $this->logger->error('Street-network fill aborted for {uuid}: {reason}', [
                'uuid' => $uuid,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

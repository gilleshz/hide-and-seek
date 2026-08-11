<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FeatureRepository;
use App\Repository\GameRepository;
use App\Service\OverpassService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingest-features',
    description: 'Ingest OSM features via Overpass for a game boundary.',
)]
final class IngestFeaturesCommand extends Command
{
    public function __construct(
        private GameRepository $games,
        private FeatureRepository $features,
        private OverpassService $overpassService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('gameUuid', InputArgument::REQUIRED, 'The UUID of the game.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation if features already exist.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gameUuid = $input->getArgument('gameUuid');
        if (!is_string($gameUuid)) {
            $io->error('Game UUID must be a string.');

            return Command::FAILURE;
        }

        $game = $this->games->findOneByUuid($gameUuid);
        if ($game === null) {
            $io->error("Game '{$gameUuid}' not found.");

            return Command::FAILURE;
        }

        if (
            $game->getBoundarySwLat() === null
            || $game->getBoundarySwLng() === null
            || $game->getBoundaryNeLat() === null
            || $game->getBoundaryNeLng() === null
        ) {
            $io->error('Game has no boundary set. Set the boundary before ingesting features.');

            return Command::FAILURE;
        }

        $existingCount = $this->features->countByGame($game);
        if ($existingCount > 0 && !$input->getOption('force')) {
            $confirm = $io->confirm(
                "Features already exist for this game ({$existingCount} found). Ingest again?",
                false,
            );
            if (!$confirm) {
                $io->info('Cancelled.');

                return Command::SUCCESS;
            }
        }

        $io->info('Ingesting features from Overpass... This may take a while.');

        try {
            $count = $this->overpassService->ingestFeatures($game);
            $io->success("Ingested {$count} features.");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Ingestion failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}

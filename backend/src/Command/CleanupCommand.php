<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\GameCleanupResult;
use App\Repository\GameRepository;
use App\Service\GameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'jetlag:cleanup',
    description: 'Purge games (DB rows, uploaded files and transit tiles) from the server.',
)]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly GameService $gameService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'created-before',
                null,
                InputOption::VALUE_REQUIRED,
                'Only purge games created strictly before this date (YYYY-MM-DD).',
            )
            ->addOption(
                'include-in-progress',
                null,
                InputOption::VALUE_REQUIRED,
                'Also purge games with a running round (true/false).',
                'false',
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $createdBefore = $this->parseCreatedBefore($input->getOption('created-before'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $includeInProgress = filter_var($input->getOption('include-in-progress'), FILTER_VALIDATE_BOOL);

        $candidateCount = $this->games->countCreatedBefore($createdBefore);
        if ($candidateCount === 0) {
            $io->success('No matching games to purge.');

            return Command::SUCCESS;
        }

        if (!$this->confirm($io, $input, $candidateCount, $createdBefore, $includeInProgress)) {
            $io->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->report($io, $this->gameService->cleanup($createdBefore, $includeInProgress));

        return Command::SUCCESS;
    }

    private function parseCreatedBefore(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        // '!' resets the time to 00:00:00 so "before <date>" means before the start of that day.
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $display = is_string($value) ? $value : get_debug_type($value);

            throw new \InvalidArgumentException(
                sprintf("Invalid --created-before value '%s'. Expected format YYYY-MM-DD.", $display),
            );
        }

        return $date;
    }

    private function confirm(
        SymfonyStyle $io,
        InputInterface $input,
        int $candidateCount,
        ?\DateTimeImmutable $createdBefore,
        bool $includeInProgress,
    ): bool {
        if ($input->getOption('force') === true) {
            return true;
        }

        $scope = $createdBefore === null
            ? 'all games'
            : sprintf('games created before %s', $createdBefore->format('Y-m-d'));
        $inProgress = $includeInProgress ? ' (including in-progress games)' : ' (in-progress games are skipped)';
        $io->warning(sprintf(
            'About to purge up to %d %s%s, permanently deleting their data and files.',
            $candidateCount,
            $scope,
            $inProgress,
        ));

        return $io->confirm('Continue?', false);
    }

    private function report(SymfonyStyle $io, GameCleanupResult $result): void
    {
        if ($io->isVerbose()) {
            foreach ($result->purged as $game) {
                $io->writeln(sprintf('Purged <info>%s</info> (%s)', $game->uuid, $game->name));
                foreach ($game->removedPaths as $path) {
                    $io->writeln("  removed file: {$path}");
                }
            }
        }

        $io->success(sprintf(
            'Purged %d game(s). Skipped %d in-progress game(s).',
            $result->deletedCount(),
            $result->skippedInProgress,
        ));
    }
}

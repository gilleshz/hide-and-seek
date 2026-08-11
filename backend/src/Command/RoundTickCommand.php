<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\RoundStatus;
use App\Repository\RoundRepository;
use App\Service\QuestionService;
use App\Service\RoundService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsCommand(name: 'app:round:tick', description: 'Close elapsed hiding periods and overdue answers, announcing both.')]
#[AsPeriodicTask(frequency: '10 seconds')]
final class RoundTickCommand extends Command
{
    public function __construct(
        private RoundRepository $rounds,
        private RoundService $roundService,
        private QuestionService $questionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $active = $this->rounds->findByStatuses([RoundStatus::Hiding, RoundStatus::Seeking]);
        foreach ($active as $round) {
            $this->roundService->tick($round);
        }
        $this->questionService->revealPastDeadline();

        return Command::SUCCESS;
    }
}

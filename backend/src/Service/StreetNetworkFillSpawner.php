<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class StreetNetworkFillSpawner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Shell-backgrounded rather than Process::start(): PHP terminates a started child when the Process object
     * is destroyed, and this fetch outlives the tick that asked for it by minutes. The shell exits at once,
     * so run() returns immediately and the fetch is reparented away from the scheduler worker.
     */
    public function spawn(string $networkUuid): void
    {
        $command = 'exec nohup php bin/console app:street-network:fill '
            . escapeshellarg($networkUuid) . ' > /dev/null 2>&1 &';

        Process::fromShellCommandline($command, $this->projectDir)->run();
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'jetlag:identity:allow-new-device',
    description: 'Allow an identity to bind a new device on its next join.',
)]
final class AllowNewDeviceCommand extends Command
{
    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name of the identity that may move to a new device.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        if (!is_string($name) || trim($name) === '') {
            $io->error('Identity name must be a non-empty string.');

            return Command::FAILURE;
        }

        $name = trim($name);
        /** @var Identity|null $identity */
        $identity = $this->entityManager->wrapInTransaction(function () use ($name): ?Identity {
            $this->identities->lockName($name);
            $identity = $this->identities->findByName($name);
            if ($identity === null) {
                return null;
            }

            $this->identities->save($identity->requestCredentialReset());

            return $identity;
        });

        if ($identity === null) {
            $io->error(sprintf('No identity named "%s".', $name));

            return Command::FAILURE;
        }

        $io->warning(sprintf(
            'This transfers every game seat held by "%s" to the next device that joins.',
            $identity->getName(),
        ));
        $io->success(sprintf(
            'The next join as "%s" from a different device will be accepted.',
            $identity->getName(),
        ));

        return Command::SUCCESS;
    }
}

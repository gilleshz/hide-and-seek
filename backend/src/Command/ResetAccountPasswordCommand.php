<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'jetlag:account:reset-password',
    description: 'Reset an account password to a newly generated random password.',
)]
final class ResetAccountPasswordCommand extends Command
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@%&*#^$';

    public function __construct(
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the account whose password is reset.')
            ->addOption(
                'length',
                null,
                InputOption::VALUE_REQUIRED,
                'Length of the generated password (4-64).',
                '16',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $length = $this->parseLength($input->getOption('length'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $name = $input->getArgument('name');
        if (!is_string($name)) {
            $io->error('Account name must be a string.');

            return Command::FAILURE;
        }

        $account = $this->accounts->findByName($name);
        if ($account === null) {
            $io->error(sprintf('No account named "%s".', $name));

            return Command::FAILURE;
        }

        $password = $this->generatePassword($length);
        $account->resetPassword($password);
        $this->accounts->save($account);

        $io->success(sprintf('Password reset for account "%s".', $account->getName()));
        $io->writeln('New password: ' . $password);

        return Command::SUCCESS;
    }

    private function parseLength(mixed $value): int
    {
        $length = is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 4, 'max_range' => 64]])
            : false;

        if ($length === false) {
            throw new \InvalidArgumentException('Password length must be between 4 and 64.');
        }

        return $length;
    }

    private function generatePassword(int $length): string
    {
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }
}

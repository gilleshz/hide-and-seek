<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetAccountPasswordCommandTest extends KernelTestCase
{
    #[Test]
    public function itResetsThePasswordWithADefaultLength16Password(): void
    {
        $account = $this->seedAccount();

        $tester = $this->tester();
        $tester->execute(['name' => $account->getName()]);

        $tester->assertCommandIsSuccessful();
        $printed = $this->printedPassword($tester->getDisplay());
        self::assertSame(16, strlen($printed));
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9!@%&*#^$]+$/', $printed);
        $stored = $this->accounts()->findByName($account->getName());
        self::assertNotNull($stored);
        self::assertTrue($stored->passwordMatches($printed));
        self::assertFalse($stored->passwordMatches('seed-password'));
    }

    #[Test]
    public function itHonorsAnExplicitLength(): void
    {
        $account = $this->seedAccount();

        $tester = $this->tester();
        $tester->execute(['name' => $account->getName(), '--length' => '8']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(8, strlen($this->printedPassword($tester->getDisplay())));
    }

    #[Test]
    #[DataProvider('outOfRangeLengths')]
    public function itRejectsAnOutOfRangeLength(int $length): void
    {
        $account = $this->seedAccount();

        $tester = $this->tester();
        $tester->execute(['name' => $account->getName(), '--length' => (string) $length]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Password length must be between 4 and 64.', $tester->getDisplay());
        $stored = $this->accounts()->findByName($account->getName());
        self::assertNotNull($stored);
        self::assertTrue($stored->passwordMatches('seed-password'));
    }

    #[Test]
    public function itFailsForAnUnknownName(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'cmd-reset-missing-' . uniqid()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No account named', $tester->getDisplay());
    }

    /** @return array<string, array{int}> */
    public static function outOfRangeLengths(): array
    {
        return ['too short' => [3], 'too long' => [65]];
    }

    private function seedAccount(): Account
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $account = AccountFactory::create('cmd-reset-' . uniqid(), 'seed-password');
        $em->persist($account);
        $em->flush();

        return $account;
    }

    private function accounts(): AccountRepository
    {
        /** @var AccountRepository $accounts */
        $accounts = static::getContainer()->get(AccountRepository::class);

        return $accounts;
    }

    private function tester(): CommandTester
    {
        // Reuse the already-booted kernel; rebooting here would close the EntityManager used to seed data.
        static::getContainer();
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('jetlag:account:reset-password'));
    }

    private function printedPassword(string $display): string
    {
        $prefix = 'New password: ';
        $start = strpos($display, $prefix);
        self::assertNotFalse($start);

        return explode("\n", substr($display, $start + strlen($prefix)))[0];
    }
}

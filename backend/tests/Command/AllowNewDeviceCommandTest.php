<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Identity;
use App\Repository\IdentityRepository;
use App\Tests\Support\IdentityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AllowNewDeviceCommandTest extends KernelTestCase
{
    #[Test]
    public function itFlagsTheIdentityForANewDevice(): void
    {
        $identity = $this->seedIdentity();

        $tester = $this->tester();
        $tester->execute(['name' => $identity->getName()]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('next join', $tester->getDisplay());
        $this->clearEntityManager();
        $stored = $this->identities()->findByName($identity->getName());
        self::assertNotNull($stored);
        self::assertTrue($stored->isCredentialResetRequested());
    }

    #[Test]
    public function itIsIdempotent(): void
    {
        $identity = $this->seedIdentity();

        $tester = $this->tester();
        $tester->execute(['name' => $identity->getName()]);
        $tester->execute(['name' => $identity->getName()]);

        $tester->assertCommandIsSuccessful();
        $this->clearEntityManager();
        $stored = $this->identities()->findByName($identity->getName());
        self::assertNotNull($stored);
        self::assertTrue($stored->isCredentialResetRequested());
    }

    #[Test]
    public function itFailsForAnUnknownName(): void
    {
        $tester = $this->tester();
        $tester->execute(['name' => 'cmd-missing-' . uniqid()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No identity named', $tester->getDisplay());
    }

    private function seedIdentity(): Identity
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $identity = IdentityFactory::create('cmd-allow-' . uniqid(), 'seed-device-key');
        $em->persist($identity);
        $em->flush();

        return $identity;
    }

    private function clearEntityManager(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
    }

    private function identities(): IdentityRepository
    {
        /** @var IdentityRepository $identities */
        $identities = static::getContainer()->get(IdentityRepository::class);

        return $identities;
    }

    private function tester(): CommandTester
    {
        // Reuse the already-booted kernel; rebooting here would close the EntityManager used to seed data.
        static::getContainer();
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('jetlag:identity:allow-new-device'));
    }
}

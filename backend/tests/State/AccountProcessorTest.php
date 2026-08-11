<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\AccountInput;
use App\Entity\Account;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\AccountRepository;
use App\State\AccountProcessor;
use App\Tests\Support\AccountFactory;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccountProcessor::class)]
final class AccountProcessorTest extends TestCase
{
    private const string NAME = 'Alice';
    private const string PASSWORD = 'correct-password';

    /** The processor wraps the create-or-verify in a transaction; the test doubles must run the closure. */
    private function transactionalEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function processor(
        AccountRepository $accounts,
        ?ManagerRegistry $registry = null,
    ): AccountProcessor {
        return new AccountProcessor(
            $this->createStub(ValidatorInterface::class),
            $accounts,
            $this->transactionalEntityManager(),
            $registry ?? $this->createStub(ManagerRegistry::class),
        );
    }

    private function input(string $name = self::NAME, ?string $password = self::PASSWORD): AccountInput
    {
        $input = new AccountInput();
        $input->name = $name;
        $input->password = $password;

        return $input;
    }

    /** Registry whose resetManager() hands back an EM whose repository finds $account by name. */
    private function registryResolvingTo(?Account $account): ManagerRegistry
    {
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($account);

        $fresh = $this->createStub(ObjectManager::class);
        $fresh->method('getRepository')->willReturn($repository);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('resetManager')->willReturn($fresh);

        return $registry;
    }

    #[Test]
    public function itCreatesAndSavesANewAccountWhenNoneExists(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);
        $accounts->expects(self::once())->method('save');

        $this->processor($accounts)->process($this->input(), new Post());
    }

    #[Test]
    public function itAcceptsTheCorrectPasswordForAnExistingName(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByName')->willReturn(AccountFactory::create(self::NAME, self::PASSWORD));
        $accounts->expects(self::never())->method('save');

        $this->processor($accounts)->process($this->input(), new Post());
    }

    #[Test]
    public function itRejectsAWrongPasswordForAnExistingName(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findByName')->willReturn(AccountFactory::create(self::NAME, self::PASSWORD));

        try {
            $this->processor($accounts)->process($this->input(password: 'wrong-password'), new Post());
            self::fail('Expected a FunctionalException for a wrong password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::ACCOUNT_NAME_TAKEN, $e->getErrorKey());
        }
    }

    #[Test]
    public function itVerifiesAgainstTheCommittedAccountWhenTheNameRaceIsLost(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);
        $accounts->expects(self::once())
            ->method('save')
            ->willThrowException(
                new UniqueConstraintViolationException(PdoException::new(new \PDOException('name race')), null),
            );

        $registry = $this->registryResolvingTo(AccountFactory::create(self::NAME, self::PASSWORD));

        $this->processor($accounts, $registry)->process($this->input(), new Post());
    }

    #[Test]
    public function itRejectsTheNameWhenTheRaceLosersPasswordDoesNotMatch(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findByName')->willReturn(null);
        $accounts->expects(self::once())
            ->method('save')
            ->willThrowException(
                new UniqueConstraintViolationException(PdoException::new(new \PDOException('name race')), null),
            );

        $registry = $this->registryResolvingTo(AccountFactory::create(self::NAME, self::PASSWORD));

        try {
            $this->processor($accounts, $registry)
                ->process($this->input(password: 'wrong-password'), new Post());
            self::fail('Expected a FunctionalException for the lost name race with a wrong password.');
        } catch (FunctionalException $e) {
            self::assertSame(ErrorKey::ACCOUNT_NAME_TAKEN, $e->getErrorKey());
        }
    }
}

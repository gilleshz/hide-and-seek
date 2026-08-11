<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\AccountInput;
use App\Entity\Account;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @implements ProcessorInterface<AccountInput, null>
 */
final readonly class AccountProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $this->validator->validate($data);

        try {
            return $this->entityManager->wrapInTransaction(
                fn (): null => $this->createOrVerify($data),
            );
        } catch (UniqueConstraintViolationException) {
            return $this->resolveLostRace($data);
        }
    }

    private function createOrVerify(AccountInput $data): null
    {
        // The advisory lock only serialises inside a transaction, hence wrapInTransaction.
        $this->accountRepository->lockName($data->name);
        $account = $this->accountRepository->findByName($data->name);
        if ($account !== null) {
            if (!$account->passwordMatches($data->password ?? '')) {
                throw new FunctionalException(
                    "Wrong password for this name. If it's not your name, pick a different one.",
                    ErrorKey::ACCOUNT_NAME_TAKEN,
                );
            }

            return null;
        }

        $this->accountRepository->save(new Account($data->name, $data->password ?? ''));

        return null;
    }

    /**
     * Backstop for a future writer that skips the lock: the failed flush closed the entity
     * manager, so re-open it and verify against the committed account.
     */
    private function resolveLostRace(AccountInput $data): null
    {
        $fresh = $this->managerRegistry->resetManager();
        $account = $fresh->getRepository(Account::class)->findOneBy(['name' => $data->name]);
        if ($account instanceof Account && $account->passwordMatches($data->password ?? '')) {
            return null;
        }

        throw new FunctionalException(
            "Wrong password for this name. If it's not your name, pick a different one.",
            ErrorKey::ACCOUNT_NAME_TAKEN,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\AccountPasswordInput;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\Repository\AccountRepository;

/**
 * @implements ProcessorInterface<AccountPasswordInput, null>
 */
final readonly class AccountPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private AccountRepository $accountRepository,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $this->validator->validate($data);

        $account = $this->accountRepository->findByName($data->name);
        if ($account === null || !$account->passwordMatches($data->currentPassword)) {
            throw new FunctionalException(
                'The current password is wrong for this name.',
                ErrorKey::ACCOUNT_PASSWORD_INVALID,
            );
        }

        $this->accountRepository->save($account->resetPassword($data->newPassword));

        return null;
    }
}

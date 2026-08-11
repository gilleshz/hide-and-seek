<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\AskedQuestionResource;
use App\Dto\AskQuestionInput;
use App\Exception\EntityNotFoundException;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\QuestionService;

/**
 * @implements ProcessorInterface<AskQuestionInput, AskedQuestionResource>
 */
final readonly class AskedQuestionProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundRepository $rounds,
        private QuestionService $questionService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): AskedQuestionResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $asker = $this->identity->requirePlayer();

        return AskedQuestionResource::fromEntity($this->questionService->ask($round, $asker, $data));
    }
}

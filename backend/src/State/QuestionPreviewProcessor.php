<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\QuestionPreviewResource;
use App\Dto\QuestionPreviewInput;
use App\Exception\EntityNotFoundException;
use App\Repository\RoundRepository;
use App\Service\IdentityResolver;
use App\Service\QuestionPreviewService;

/**
 * @implements ProcessorInterface<QuestionPreviewInput, QuestionPreviewResource>
 */
final readonly class QuestionPreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private RoundRepository $rounds,
        private QuestionPreviewService $previewService,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): QuestionPreviewResource {
        $this->validator->validate($data);

        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        $asker = $this->identity->requirePlayer();

        return QuestionPreviewResource::fromResult(
            $this->previewService->preview($round, $asker, $data),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\AskedQuestionResource;
use App\Dto\CompleteThermometerInput;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Repository\AskedQuestionRepository;
use App\Service\IdentityResolver;
use App\Service\QuestionService;

/**
 * @implements ProcessorInterface<CompleteThermometerInput, AskedQuestionResource>
 */
final readonly class CompleteThermometerProcessor implements ProcessorInterface
{
    public function __construct(
        private ValidatorInterface $validator,
        private AskedQuestionRepository $askedQuestions,
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
        if ($data->endLat === null || $data->endLng === null) {
            throw new FunctionalException(message: 'An end position is required to complete the thermometer.', errorKey: 'thermometer.end_position_required');
        }

        $questionUuid = $uriVariables['questionUuid'] ?? null;
        $question = is_string($questionUuid) ? $this->askedQuestions->findOneByUuid($questionUuid) : null;
        if ($question === null) {
            throw new EntityNotFoundException(message: 'Question not found.', errorKey: 'question.not_found');
        }

        $asker = $this->identity->requirePlayer();

        return AskedQuestionResource::fromEntity(
            $this->questionService->completeThermometer($question, $asker, $data->endLat, $data->endLng),
        );
    }
}

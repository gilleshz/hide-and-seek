<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Exception\EntityNotFoundException;
use App\Repository\AskedQuestionRepository;
use App\Service\IdentityResolver;
use App\Service\QuestionService;
use App\Service\UploadedImageReader;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class VetoQuestionProcessor implements ProcessorInterface
{
    public function __construct(
        private AskedQuestionRepository $askedQuestions,
        private QuestionService $questionService,
        private UploadedImageReader $upload,
        private IdentityResolver $identity,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        $questionUuid = $uriVariables['questionUuid'] ?? null;
        $question = is_string($questionUuid) ? $this->askedQuestions->findOneByUuid($questionUuid) : null;
        if ($question === null) {
            throw new EntityNotFoundException(message: 'Question not found.', errorKey: 'question.not_found');
        }

        $cardPhoto = $this->upload->image();
        $player = $this->identity->requirePlayer();

        $this->questionService->veto($question, $player, $cardPhoto);

        return null;
    }
}

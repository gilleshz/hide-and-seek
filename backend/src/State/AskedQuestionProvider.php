<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AskedQuestionResource;
use App\Repository\AskedQuestionRepository;
use App\Service\QuestionService;

/**
 * @implements ProviderInterface<AskedQuestionResource>
 */
final readonly class AskedQuestionProvider implements ProviderInterface
{
    public function __construct(
        private AskedQuestionRepository $askedQuestions,
        private QuestionService $questionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AskedQuestionResource
    {
        $uuid = $uriVariables['questionUuid'] ?? null;
        $question = is_string($uuid) ? $this->askedQuestions->findOneByUuid($uuid) : null;
        if ($question === null) {
            return null;
        }

        $this->questionService->currentState($question);

        return AskedQuestionResource::fromEntity($question);
    }
}

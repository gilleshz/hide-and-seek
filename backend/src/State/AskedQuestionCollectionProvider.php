<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AskedQuestionResource;
use App\Entity\AskedQuestion;
use App\Exception\EntityNotFoundException;
use App\Repository\AskedQuestionRepository;
use App\Repository\RoundRepository;
use App\Service\QuestionService;

/**
 * @implements ProviderInterface<AskedQuestionResource>
 */
final readonly class AskedQuestionCollectionProvider implements ProviderInterface
{
    public function __construct(
        private RoundRepository $rounds,
        private AskedQuestionRepository $askedQuestions,
        private QuestionService $questionService,
    ) {
    }

    /**
     * @return list<AskedQuestionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $roundUuid = $uriVariables['roundUuid'] ?? null;
        $round = is_string($roundUuid) ? $this->rounds->findOneByUuid($roundUuid) : null;
        if ($round === null) {
            throw new EntityNotFoundException(message: 'Round not found.', errorKey: 'round.not_found');
        }

        return array_map(
            function (AskedQuestion $question): AskedQuestionResource {
                $this->questionService->currentState($question);

                return AskedQuestionResource::fromEntity($question);
            },
            $this->askedQuestions->findByRound($round),
        );
    }
}

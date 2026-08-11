<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AskQuestionInput;
use App\Entity\AskedQuestion;
use App\Entity\GameTransitLine;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\MeasuringResult;
use App\Enum\QuestionCategory;
use App\Enum\QuestionStatus;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\ThermometerResult;
use App\ErrorKey;
use App\Exception\FunctionalException;
use App\GeoDistance;
use App\QuestionCatalog\CatalogCategory;
use App\QuestionCatalog\CatalogDefinition;
use App\QuestionCatalog\CatalogOption;
use App\Repository\AskedQuestionRepository;
use App\Repository\FeatureRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\RoundMembershipRepository;
use App\RoundTiming;
use App\Storage\ImageStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class QuestionService
{
    private const float MIN_THERMOMETER_TRAVEL_METERS = 1.0;

    public function __construct(
        private RoundMembershipRepository $memberships,
        private AskedQuestionRepository $askedQuestions,
        private GameTransitLineRepository $transitLines,
        private GameTransitStationRepository $transitStations,
        private HidingZoneRepository $hidingZones,
        private PlayerLocationRepository $playerLocations,
        private ChatService $chatService,
        private PossibleAreaService $possibleAreaService,
        private FeatureRepository $features,
        private QuestionMessageFormatter $formatter,
        private OverpassService $overpassService,
        private LoggerInterface $logger,
        private ImageStorageInterface $imageStorage,
        private RoundClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The chat/Mercure side effects fire inside the transaction, before commit: a failure after a
     * publish rolls the DB back while already-delivered SSE events stay delivered (deliberate: the
     * alternative was a committed, stuck partial state), and a hub outage fails the flow closed with
     * a 500. The wrapped methods hold their row locks until commit, so keep them short.
     */
    public function ask(Round $round, Player $asker, AskQuestionInput $input): AskedQuestion
    {
        $this->validateAgainstCatalog($round->getGame()->getSize(), $round->getGame()->getEdition(), $input);
        $this->assertSeeker($round, $asker);
        $this->assertSeekersAreHunting($round);
        $this->assertNoOutstandingQuestion($round);

        $question = $this->buildQuestion($round, $asker, $input);

        $previous = $this->askedQuestions->findAnsweredOrVetoedByRound($round);
        $repeatCount = 1;
        foreach ($previous as $prev) {
            if ($this->questionMatchesQuestion($question, $prev)) {
                $repeatCount++;
            }
        }
        $question->setRepeatCount($repeatCount);

        return $this->entityManager->wrapInTransaction(function () use ($question, $asker): AskedQuestion {
            $this->askedQuestions->save($question);
            $this->postAskMessage($question, $asker);

            return $question;
        });
    }

    public function completeThermometer(
        AskedQuestion $question,
        Player $asker,
        float $endLat,
        float $endLng,
    ): AskedQuestion {
        return $this->entityManager->wrapInTransaction(
            function () use ($question, $asker, $endLat, $endLng): AskedQuestion {
                $this->assertCompletableThermometer($question, $asker);
                $startPoint = $question->getStartPoint();
                if ($startPoint === null) {
                    throw new \LogicException('Thermometer question is missing its start point.');
                }

                $endPoint = new Point($endLng, $endLat);
                $traveledMeters = GeoDistance::metersBetween($startPoint, $endPoint);
                if ($traveledMeters < self::MIN_THERMOMETER_TRAVEL_METERS) {
                    throw new FunctionalException(
                        message: 'You have not moved from the thermometer start point yet.',
                        errorKey: 'question.thermometer_not_moved',
                    );
                }

                $deadline = new \DateTimeImmutable()
                    ->modify(sprintf('+%d minutes', RoundTiming::standardAnswerWindowMinutes()));
                $question
                    ->setEndPoint($endPoint)
                    ->setRevealDeadlineAt($deadline);
                $this->askedQuestions->save($question);
                $payload = $this->formatter->thermometerCompleteBody($question);
                $this->chatService->postQuestion(
                    game: $question->getRound()->getGame(),
                    sender: $asker,
                    body: $payload->body,
                    bodyKey: $payload->bodyKey,
                    bodyArgs: $payload->bodyArgs,
                    questionUuid: $question->getUuid(),
                );

                return $question;
            },
        );
    }

    public function cancel(AskedQuestion $question, Player $asker): void
    {
        $this->entityManager->wrapInTransaction(function () use ($question, $asker): void {
            $this->assertSeeker($question->getRound(), $asker);
            $this->currentState($question);
            if ($question->getStatus() !== QuestionStatus::Open) {
                throw new FunctionalException(
                    message: 'Only open questions can be cancelled.',
                    errorKey: 'question.cancel_only_open',
                );
            }
            if ($question->getRevealedAt() !== null) {
                throw new FunctionalException(
                    message: 'Question has already been revealed.',
                    errorKey: 'question.already_revealed',
                );
            }

            $replacedQuestion = $this->askedQuestions->findByReplacedByUuid($question->getUuid());
            if ($replacedQuestion !== null) {
                $replacedQuestion->setReplacedByUuid(null);
                $this->askedQuestions->save($replacedQuestion);
            }
            $originalUuid = $question->getReplacedQuestionUuid();
            if ($originalUuid !== null) {
                $original = $this->askedQuestions->findOneByUuid($originalUuid);
                if ($original !== null) {
                    $original->setReplacedByUuid(null);
                    $this->askedQuestions->save($original);
                }
            }

            $this->chatService->postQuestionSystemNotice(
                game: $question->getRound()->getGame(),
                body: 'Question cancelled.',
                bodyKey: 'system.question_cancelled',
                questionUuid: $question->getUuid(),
            );
            $this->askedQuestions->remove($question);
        });
    }

    public function veto(AskedQuestion $question, Player $hider, UploadedFile $cardPhoto): void
    {
        $this->entityManager->wrapInTransaction(function () use ($question, $hider, $cardPhoto): void {
            $this->assertHider($question->getRound(), $hider);
            $this->assertPendingQuestion($question);
            $this->assertThermometerCompleted($question);

            $this->claimOrFail($question, QuestionStatus::Vetoed);
            $question->setRevealDeadlineAt(null);
            $this->askedQuestions->save($question);

            $this->chatService->postQuestionCancelled(
                game: $question->getRound()->getGame(),
                sender: $hider,
                body: 'Question vetoed. No cards drawn.',
                bodyKey: 'system.question_vetoed',
                questionUuid: $question->getUuid(),
                imageRef: $this->storeCardPhoto($question, $cardPhoto),
            );
        });
    }

    public function randomize(AskedQuestion $question, Player $hider, UploadedFile $cardPhoto): AskedQuestion
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($question, $hider, $cardPhoto): AskedQuestion {
                $this->assertHider($question->getRound(), $hider);
                $this->assertPendingQuestion($question);

                $game = $question->getRound()->getGame();
                $catalog = CatalogDefinition::forGame($game->getSize(), $game->getEdition());

                $categoryCatalog = null;
                foreach ($catalog as $cat) {
                    if ($cat->key === $question->getCategory()) {
                        $categoryCatalog = $cat;
                        break;
                    }
                }

                if ($categoryCatalog === null) {
                    throw new FunctionalException(
                        message: 'No questions available in this category.',
                        errorKey: 'question.no_questions_in_category',
                    );
                }

                $askedInRound = $this->askedQuestions->findByRoundAndCategory(
                    $question->getRound(),
                    $question->getCategory(),
                );

                $availableOptions = array_values(array_filter(
                    $categoryCatalog->options,
                    fn(CatalogOption $opt): bool => $this->optionNotYetAsked($opt, $askedInRound, $question),
                ));

                if ($availableOptions === []) {
                    throw new FunctionalException(
                        message: 'All questions in this category have already been asked.',
                        errorKey: 'question.all_questions_asked',
                    );
                }

                $chosen = $availableOptions[array_rand($availableOptions)];

                $replacement = $this->buildQuestionFromCatalog(
                    $question->getRound(),
                    $question->getAskerPlayer(),
                    $question->getCategory(),
                    $chosen,
                    $question,
                );

                // Claimed only once everything that can throw is done, so a rejection cannot strand the question.
                $this->claimOrFail($question, QuestionStatus::Randomized);
                $question->setRevealDeadlineAt(null);
                $question->setReplacedByUuid($replacement->getUuid());
                $this->askedQuestions->save($question);

                $replacement->setReplacedQuestionUuid($question->getUuid());
                $this->askedQuestions->save($replacement);

                $this->chatService->postQuestionCancelled(
                    game: $game,
                    sender: $hider,
                    body: sprintf(
                        'Question randomized. Answering a %s question instead.',
                        $question->getCategory()->value,
                    ),
                    bodyKey: 'system.question_randomized',
                    bodyArgs: ['category' => ucfirst($question->getCategory()->value)],
                    questionUuid: $question->getUuid(),
                    imageRef: $this->storeCardPhoto($question, $cardPhoto),
                );
                $this->postAskMessage($replacement, $question->getAskerPlayer());

                return $replacement;
            },
        );
    }

    public function reveal(AskedQuestion $question, Player $revealingPlayer): AskedQuestion
    {
        return $this->entityManager->wrapInTransaction(function () use ($question, $revealingPlayer): AskedQuestion {
            $this->assertHider($question->getRound(), $revealingPlayer);
            if ($question->getRevealedAt() !== null) {
                throw new FunctionalException(
                    message: 'Question has already been revealed.',
                    errorKey: 'question.already_revealed',
                );
            }
            if ($question->getStatus() !== QuestionStatus::Open) {
                throw new FunctionalException(
                    message: 'Only open questions can be revealed.',
                    errorKey: 'question.reveal_only_open',
                );
            }
            if ($question->getCategory() === QuestionCategory::Thermometer && $question->getEndPoint() === null) {
                throw new FunctionalException(
                    message: 'Thermometer is still traveling; complete it before revealing.',
                    errorKey: 'question.thermometer_still_traveling',
                );
            }

            $latest = $this->playerLocations->findLatestByRoundAndPlayer($question->getRound(), $revealingPlayer);
            if ($latest === null) {
                throw new FunctionalException(
                    message: 'Hider has no recorded location yet.',
                    errorKey: 'question.hider_no_location',
                );
            }

            // A teammate or the deadline can land between the check above and here; say so rather than 200.
            $revealed = $this->applyAnswerAndReveal(
                $question,
                $latest->getPoint(),
                $revealingPlayer,
                $latest->getAltitude(),
            );
            if (!$revealed) {
                throw new FunctionalException(
                    message: 'Question has already been revealed.',
                    errorKey: 'question.already_revealed',
                );
            }

            return $question;
        });
    }

    public function revealWithPhoto(AskedQuestion $question, Player $hider, UploadedFile $file): AskedQuestion
    {
        return $this->entityManager->wrapInTransaction(function () use ($question, $hider, $file): AskedQuestion {
            $this->assertHider($question->getRound(), $hider);
            if ($question->getRevealedAt() !== null) {
                throw new FunctionalException(
                    message: 'Question has already been revealed.',
                    errorKey: 'question.already_revealed',
                );
            }
            if ($question->getStatus() !== QuestionStatus::Open) {
                throw new FunctionalException(
                    message: 'Only open questions can be revealed.',
                    errorKey: 'question.reveal_only_open',
                );
            }
            if ($question->getCategory() !== QuestionCategory::Photos) {
                throw new FunctionalException(
                    message: 'Only photo questions can be answered with a photo.',
                    errorKey: 'question.photo_answer_only_photos',
                );
            }

            $hiderPoint = $this->playerLocations
                ->findLatestByRoundAndPlayer($question->getRound(), $hider)?->getPoint();
            if ($hiderPoint === null) {
                throw new FunctionalException(
                    message: 'Hider has no recorded location yet.',
                    errorKey: 'question.hider_no_location',
                );
            }

            // Claimed before the upload: losing the race must not leave an orphan image in storage.
            $revealedAt = new \DateTimeImmutable();
            if (!$this->askedQuestions->claimUnrevealed($question, $revealedAt)) {
                throw new FunctionalException(
                    message: 'Question has already been revealed.',
                    errorKey: 'question.already_revealed',
                );
            }

            $question->setRevealedAt($revealedAt);
            $this->possibleAreaService->computeAfterReveal($question, $hiderPoint);

            $imageRef = $this->imageStorage->store(
                $question->getRound()->getGame()->getUuid(),
                $file,
            );

            $this->chatService->postPhotoAnswer(
                game: $question->getRound()->getGame(),
                sender: $hider,
                imageRef: $imageRef,
                questionUuid: $question->getUuid(),
            );

            $this->askedQuestions->save($question);

            return $question;
        });
    }

    /**
     * Past-deadline answers used to surface only when a client happened to read them, so a table with
     * nobody's phone awake sat on an answer it already owed.
     */
    public function revealPastDeadline(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $due = $this->askedQuestions->findPastRevealDeadline(
                [RoundStatus::Hiding, RoundStatus::Seeking],
                new \DateTimeImmutable(),
            );
            foreach ($due as $question) {
                $this->currentState($question);
            }
        });
    }

    public function currentState(AskedQuestion $question): AskedQuestion
    {
        return $this->entityManager->wrapInTransaction(function () use ($question): AskedQuestion {
            if (!$this->shouldAutoReveal($question)) {
                return $question;
            }

            $location = $this->playerLocations->findFreshestHiderLocationByRound($question->getRound());
            if ($location === null) {
                // The one-outstanding rule then blocks the round until a seeker cancels: make it visible.
                $this->logger->warning('Auto-reveal deadline passed with no hider location on record', [
                    'questionUuid' => $question->getUuid(),
                    'roundUuid' => $question->getRound()->getUuid(),
                ]);

                return $question;
            }

            // Every client's refresh trips this, so losing the claim is the normal case, not an error.
            $this->applyAnswerAndReveal(
                $question,
                $location->getPoint(),
                $location->getPlayer(),
                $location->getAltitude(),
            );

            return $question;
        });
    }

    public function computeRadarAnswer(Point $hiderPoint, Point $seekerPoint, float $radiusMeters): bool
    {
        return GeoDistance::metersBetween($hiderPoint, $seekerPoint) <= $radiusMeters;
    }

    public function computeThermometerAnswer(Point $hiderPoint, Point $start, Point $end): ThermometerResult
    {
        $startDistance = GeoDistance::metersBetween($hiderPoint, $start);
        $endDistance = GeoDistance::metersBetween($hiderPoint, $end);

        return $endDistance < $startDistance ? ThermometerResult::Hotter : ThermometerResult::Colder;
    }

    private function assertSeeker(Round $round, Player $player): void
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Seeker) {
            throw new FunctionalException(
                message: 'Only a seeker may ask questions.',
                errorKey: 'question.seeker_only',
            );
        }
    }

    /**
     * Questions belong to the seeking phase: a hiding period still running, whether the round's first
     * one or one a Move card opened, leaves the seekers nothing to ask about.
     */
    private function assertSeekersAreHunting(Round $round): void
    {
        if ($this->clock->isSeeking($round)) {
            return;
        }

        [$message, $errorKey] = match (true) {
            $this->clock->isMoveWindowOpen($round) => [
                'Seekers are frozen until the hiders finish moving.',
                'question.seekers_frozen',
            ],
            $round->getStatus() === RoundStatus::Hiding => [
                'The hiders are still hiding. Questions open when the hiding period ends.',
                'question.hiding_period',
            ],
            default => ['This round is not taking questions.', 'question.round_not_seeking'],
        };

        throw new FunctionalException(message: $message, errorKey: $errorKey);
    }

    private function assertHider(Round $round, Player $player): void
    {
        $membership = $this->memberships->findOneByRoundAndPlayer($round, $player);
        if ($membership === null || $membership->getSide() !== Side::Hider) {
            throw new FunctionalException(
                message: 'Only a hider may reveal the answer.',
                errorKey: 'question.hider_only',
            );
        }
    }

    private function assertNoOutstandingQuestion(Round $round): void
    {
        $outstanding = $this->askedQuestions->findOutstandingByRound($round);
        if ($outstanding === null) {
            return;
        }

        $this->currentState($outstanding);
        if ($outstanding->getRevealedAt() === null) {
            throw new FunctionalException(
                message: 'A question is already awaiting an answer for this round.',
                errorKey: 'question.already_awaiting',
            );
        }
    }

    private function buildQuestion(Round $round, Player $asker, AskQuestionInput $input): AskedQuestion
    {
        $question = new AskedQuestion($round, $asker, $input->category, $this->initialDeadline($round, $input));

        match ($input->category) {
            QuestionCategory::Radar => $this->applyRadarInputs($question, $input),
            QuestionCategory::Thermometer => $this->applyThermometerInputs($question, $input),
            QuestionCategory::Matching => $this->applyMatchingInputs($question, $input),
            QuestionCategory::Measuring => $this->applyMeasuringInputs($question, $input),
            QuestionCategory::Tentacles => $this->applyTentaclesInputs($question, $input),
            QuestionCategory::Photos => $this->applyPhotoInputs($question, $input),
        };

        if ($question->getFeatureType() !== null) {
            $game = $question->getRound()->getGame();
            if ($this->features->countByGameAndType($game, $question->getFeatureType()) === 0) {
                try {
                    $this->overpassService->ingestFeatureType($game, $question->getFeatureType());
                } catch (\RuntimeException $e) {
                    $this->logger->error('Lazy feature ingest failed in question safety net', ['featureType' => $question->getFeatureType()->value, 'gameUuid' => $game->getUuid(), 'exception' => $e]);
                }
            }
        }

        return $question;
    }

    private function applyFeatureInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        if ($input->featureType === null || $input->seekerLat === null || $input->seekerLng === null) {
            throw new FunctionalException(
                message: 'This question requires a feature type and the seeker position.',
                errorKey: 'question.missing_feature_type',
            );
        }

        $question
            ->setFeatureType($input->featureType)
            ->setSeekerPoint(new Point($input->seekerLng, $input->seekerLat));
    }

    private function applyMeasuringInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        if ($input->seaLevel === true) {
            if ($input->seekerLat !== null && $input->seekerLng !== null) {
                $question->setSeekerPoint(new Point($input->seekerLng, $input->seekerLat));
            }

            $question
                ->setSeekerAltitude($input->seekerAltitude)
                ->setSeaLevel(true);

            return;
        }

        $this->applyFeatureInputs($question, $input);
    }

    private function applyMatchingInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        if ($input->stationNameLength === true) {
            if ($input->seekerLat === null || $input->seekerLng === null) {
                throw new FunctionalException(
                    message: 'This question requires the seeker position.',
                    errorKey: ErrorKey::QUESTION_SEEKER_POSITION_REQUIRED,
                );
            }

            $question
                ->setFeatureType(FeatureType::TransitStation)
                ->setSeekerPoint(new Point($input->seekerLng, $input->seekerLat))
                ->setStationNameLength(true);

            return;
        }

        if ($input->featureType !== null) {
            $this->applyFeatureInputs($question, $input);

            return;
        }

        if ($input->transitLineOsmId === null || $input->transitLineOsmType === null) {
            throw new FunctionalException(
                message: 'This question requires a feature type or a transit line.',
                errorKey: 'question.missing_feature_type',
            );
        }

        $game = $question->getRound()->getGame();
        $line = $this->transitLines->findOneByGameAndOsm(
            $game,
            $input->transitLineOsmType,
            (int) $input->transitLineOsmId,
        );
        if ($line === null) {
            throw new FunctionalException(
                message: 'The transit line you are riding was not found for this game.',
                errorKey: 'question.transit_line_not_found',
            );
        }

        $question
            ->setTransitLineUuid($line->getUuid())
            ->setTransitLineLabel($this->transitLineLabel($line));
    }

    private function transitLineLabel(GameTransitLine $line): string
    {
        $ref = trim($line->getRef());
        $name = trim($line->getName());

        return match (true) {
            $ref !== '' && $name !== '' => sprintf('%s: %s', $ref, $name),
            $ref !== '' => $ref,
            default => $name,
        };
    }

    private function applyTentaclesInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        $this->applyFeatureInputs($question, $input);

        if ($input->withinMeters === null) {
            throw new FunctionalException(
                message: 'Tentacles questions require a range.',
                errorKey: 'question.tentacles_missing_range',
            );
        }

        $question->setWithinMeters($input->withinMeters);
    }

    private function applyRadarInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        if ($input->radiusMeters === null || $input->seekerLat === null || $input->seekerLng === null) {
            throw new FunctionalException(
                message: 'Radar questions require a radius and the seeker position.',
                errorKey: 'question.radar_missing_radius',
            );
        }

        $question
            ->setRadiusMeters($input->radiusMeters)
            ->setSeekerPoint(new Point($input->seekerLng, $input->seekerLat))
            ->setIsCustomRadius($input->isCustomRadius);
    }

    private function applyThermometerInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        if ($input->startLat === null || $input->startLng === null || $input->distanceMeters === null) {
            throw new FunctionalException(
                message: 'Thermometer questions require a start position and a distance.',
                errorKey: 'question.thermometer_missing_position',
            );
        }

        $question
            ->setStartPoint(new Point($input->startLng, $input->startLat))
            ->setDistanceMeters($input->distanceMeters);
    }

    private function applyPhotoInputs(AskedQuestion $question, AskQuestionInput $input): void
    {
        $size = $question->getRound()->getGame()->getSize();
        if ($input->photoTarget === null || !$input->photoTarget->isAvailableFor($size)) {
            throw new FunctionalException(
                message: 'Photo questions require a photo target available for this game size.',
                errorKey: 'question.photo_target_unavailable',
            );
        }

        $question->setPhotoTarget($input->photoTarget);
    }

    private function initialDeadline(Round $round, AskQuestionInput $input): ?\DateTimeImmutable
    {
        if ($input->category === QuestionCategory::Thermometer) {
            return null;
        }

        $windowMinutes = $input->category === QuestionCategory::Photos
            ? RoundTiming::photoAnswerWindowMinutes($round->getGame()->getSize())
            : RoundTiming::standardAnswerWindowMinutes();

        return new \DateTimeImmutable()->modify(sprintf('+%d minutes', $windowMinutes));
    }

    private function postAskMessage(AskedQuestion $question, Player $asker): void
    {
        $game = $question->getRound()->getGame();
        if ($question->getCategory() === QuestionCategory::Thermometer) {
            if ($question->getEndPoint() !== null) {
                $payload = $this->formatter->thermometerCompleteBody($question);
                $this->chatService->postQuestion(
                    game: $game,
                    sender: $asker,
                    body: $payload->body,
                    bodyKey: $payload->bodyKey,
                    bodyArgs: $payload->bodyArgs,
                    questionUuid: $question->getUuid(),
                );

                return;
            }

            $payload = $this->formatter->thermometerStartBody($question);
            $this->chatService->postQuestionInfo(
                game: $game,
                sender: $asker,
                body: $payload->body,
                bodyKey: $payload->bodyKey,
                bodyArgs: $payload->bodyArgs,
                questionUuid: $question->getUuid(),
            );

            return;
        }

        $payload = $this->formatter->askBody($question);
        $this->chatService->postQuestion(
            game: $game,
            sender: $asker,
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
            questionUuid: $question->getUuid(),
        );
    }

    private function assertCompletableThermometer(AskedQuestion $question, Player $asker): void
    {
        $this->assertSeeker($question->getRound(), $asker);
        if ($question->getStatus() !== QuestionStatus::Open) {
            throw new FunctionalException(
                message: 'Only open questions can be completed.',
                errorKey: 'question.complete_only_open',
            );
        }
        if ($question->getAskerPlayer()->getUuid() !== $asker->getUuid()) {
            throw new FunctionalException(
                message: 'Only the seeker who asked may complete the thermometer.',
                errorKey: 'question.complete_wrong_seeker',
            );
        }
        if ($question->getCategory() !== QuestionCategory::Thermometer) {
            throw new FunctionalException(
                message: 'Only thermometer questions can be completed.',
                errorKey: 'question.complete_only_thermometer',
            );
        }
        if ($question->getRevealedAt() !== null || $question->getEndPoint() !== null) {
            throw new FunctionalException(
                message: 'This thermometer has already been completed.',
                errorKey: 'question.thermometer_already_complete',
            );
        }
    }

    private function shouldAutoReveal(AskedQuestion $question): bool
    {
        $deadline = $question->getRevealDeadlineAt();

        return $question->getRevealedAt() === null
            && $deadline !== null
            && $question->getStatus() === QuestionStatus::Open
            && new \DateTimeImmutable() >= $deadline;
    }

    private function effectiveHiderPoint(AskedQuestion $question, Point $live): Point
    {
        if ($question->getCategory() === QuestionCategory::Matching && $question->getTransitLineUuid() !== null) {
            return $this->hidingZones->findOneByRound($question->getRound())?->getStationPoint() ?? $live;
        }

        return $live;
    }

    /**
     * Returns false when someone else answered first, so the race loser computes, writes and posts
     * nothing.
     */
    private function applyAnswerAndReveal(
        AskedQuestion $question,
        Point $hiderPoint,
        Player $hiderPlayer,
        ?float $hiderAltitude = null,
    ): bool {
        $revealedAt = new \DateTimeImmutable();
        if (!$this->askedQuestions->claimUnrevealed($question, $revealedAt)) {
            return false;
        }

        $point = $this->effectiveHiderPoint($question, $hiderPoint);
        match ($question->getCategory()) {
            QuestionCategory::Radar => $this->revealRadar($question, $point),
            QuestionCategory::Thermometer => $this->revealThermometer($question, $point),
            QuestionCategory::Matching => $this->revealMatching($question, $point),
            QuestionCategory::Measuring => $this->revealMeasuring($question, $point, $hiderAltitude),
            QuestionCategory::Tentacles => $this->revealTentacles($question, $point),
            QuestionCategory::Photos => null,
        };

        $question->setRevealedAt($revealedAt);
        $this->askedQuestions->save($question);
        $this->possibleAreaService->computeAfterReveal($question, $point);
        $payload = $this->formatter->answerBody($question);
        $this->chatService->postAnswer(
            game: $question->getRound()->getGame(),
            sender: $hiderPlayer,
            body: $payload->body,
            bodyKey: $payload->bodyKey,
            bodyArgs: $payload->bodyArgs,
            questionUuid: $question->getUuid(),
        );
        return true;
    }

    private function revealRadar(AskedQuestion $question, Point $hiderPoint): void
    {
        $seekerPoint = $question->getSeekerPoint();
        $radiusMeters = $question->getRadiusMeters();
        if ($seekerPoint === null || $radiusMeters === null) {
            throw new \LogicException('Radar question is missing its seeker point or radius.');
        }

        $question->setRadarAnswer($this->computeRadarAnswer($hiderPoint, $seekerPoint, $radiusMeters));
    }

    private function revealThermometer(AskedQuestion $question, Point $hiderPoint): void
    {
        $startPoint = $question->getStartPoint();
        $endPoint = $question->getEndPoint();
        if ($startPoint === null || $endPoint === null) {
            throw new \LogicException('Thermometer question is missing its start or end point.');
        }

        $question->setThermometerAnswer($this->computeThermometerAnswer($hiderPoint, $startPoint, $endPoint));
    }

    private function revealMatching(AskedQuestion $question, Point $hiderPoint): void
    {
        if ($question->getTransitLineUuid() !== null) {
            $this->revealTransitLine($question, $hiderPoint);

            return;
        }
        if ($question->isStationNameLength()) {
            $this->revealStationNameLength($question, $hiderPoint);

            return;
        }

        [$type, $seekerPoint, $game] = $this->featureContext($question);
        $hiderNearest = $this->features->findNearestWithin($game, $type, $hiderPoint, 1)[0] ?? null;
        $seekerNearest = $this->features->findNearestWithin($game, $type, $seekerPoint, 1)[0] ?? null;

        if ($hiderNearest === null || $seekerNearest === null) {
            return;
        }

        $question->setMatchingAnswer($hiderNearest->getUuid() === $seekerNearest->getUuid());
    }

    private function revealStationNameLength(AskedQuestion $question, Point $hiderPoint): void
    {
        [$type, $seekerPoint, $game] = $this->featureContext($question);
        $hiderName = ($this->features->findNearestWithin($game, $type, $hiderPoint, 1)[0] ?? null)?->getName();
        $seekerName = ($this->features->findNearestWithin($game, $type, $seekerPoint, 1)[0] ?? null)?->getName();

        if ($hiderName === null || $seekerName === null) {
            return;
        }

        $question->setMatchingAnswer(mb_strlen(trim($hiderName)) === mb_strlen(trim($seekerName)));
    }

    private function revealTransitLine(AskedQuestion $question, Point $hiderPoint): void
    {
        $game = $question->getRound()->getGame();
        $lineUuid = $question->getTransitLineUuid();
        $line = $lineUuid !== null ? $this->transitLines->findOneByGameAndUuid($game, $lineUuid) : null;
        $refs = $this->transitStations->findNearestServingRefs($game, $hiderPoint);
        if ($line === null || $refs === null) {
            return;
        }

        $question->setMatchingAnswer(in_array($line->getRef(), $refs, true));
    }

    private function revealMeasuring(AskedQuestion $question, Point $hiderPoint, ?float $hiderAltitude = null): void
    {
        if ($question->isSeaLevel()) {
            $this->revealSeaLevel($question, $hiderAltitude);

            return;
        }

        [$type, $seekerPoint, $game] = $this->featureContext($question);
        $hiderNearest = $this->features->findNearestWithin($game, $type, $hiderPoint, 1)[0] ?? null;
        $seekerNearest = $this->features->findNearestWithin($game, $type, $seekerPoint, 1)[0] ?? null;

        if ($hiderNearest === null || $seekerNearest === null) {
            return;
        }

        $hiderDistance = $this->features->distanceToFeature($hiderNearest, $hiderPoint);
        $seekerDistance = $this->features->distanceToFeature($seekerNearest, $seekerPoint);
        $question->setMeasuringAnswer(
            $hiderDistance < $seekerDistance ? MeasuringResult::Closer : MeasuringResult::Further,
        );
    }

    private function revealSeaLevel(AskedQuestion $question, ?float $hiderAltitude): void
    {
        $seekerAltitude = $question->getSeekerAltitude();
        if ($hiderAltitude === null || $seekerAltitude === null) {
            return;
        }

        $question->setMeasuringAnswer(
            abs($hiderAltitude) < abs($seekerAltitude) ? MeasuringResult::Closer : MeasuringResult::Further,
        );
    }

    private function revealTentacles(AskedQuestion $question, Point $hiderPoint): void
    {
        [$type, $seekerPoint, $game] = $this->featureContext($question);
        $range = $question->getWithinMeters();
        if ($range === null) {
            throw new \LogicException('Tentacles question is missing its range.');
        }

        if (GeoDistance::metersBetween($hiderPoint, $seekerPoint) > $range) {
            $question->setTentaclesAnswer(AskedQuestion::TENTACLES_NOT_WITHIN_REACH);

            return;
        }

        $nearest = $this->features->findNearestWithin($game, $type, $hiderPoint, 1)[0] ?? null;
        if ($nearest !== null) {
            $question->setTentaclesAnswer($nearest->getName() ?? sprintf('An unnamed %s', $type->label()));
        }
    }

    /**
     * @return array{0: \App\Enum\FeatureType, 1: Point, 2: \App\Entity\Game}
     */
    private function featureContext(AskedQuestion $question): array
    {
        $type = $question->getFeatureType();
        $seekerPoint = $question->getSeekerPoint();
        if ($type === null || $seekerPoint === null) {
            throw new \LogicException('Feature question is missing its type or seeker point.');
        }

        return [$type, $seekerPoint, $question->getRound()->getGame()];
    }

    /**
     * A powerup is a physical card, and the photo of it is the whole proof the hider held one, so it
     * is stored only once the claim on the question has been won and the powerup really happened.
     */
    private function storeCardPhoto(AskedQuestion $question, UploadedFile $cardPhoto): string
    {
        return $this->imageStorage->store($question->getRound()->getGame()->getUuid(), $cardPhoto);
    }

    /**
     * assertPendingQuestion rejects the common case early, but two hiders can clear it in the same
     * instant; this is the check that actually decides between them.
     */
    private function claimOrFail(AskedQuestion $question, QuestionStatus $to): void
    {
        if (!$this->askedQuestions->claimOpen($question, $to)) {
            throw new FunctionalException(
                message: 'Question is no longer open.',
                errorKey: 'question.no_longer_open',
            );
        }

        $question->setStatus($to);
    }

    private function assertPendingQuestion(AskedQuestion $question): void
    {
        if ($question->getRevealedAt() !== null) {
            throw new FunctionalException(
                message: 'Question has already been revealed.',
                errorKey: 'question.already_revealed',
            );
        }
        if ($question->getStatus() !== QuestionStatus::Open) {
            throw new FunctionalException(
                message: 'Question is no longer open.',
                errorKey: 'question.no_longer_open',
            );
        }
    }

    private function assertThermometerCompleted(AskedQuestion $question): void
    {
        if ($question->getCategory() === QuestionCategory::Thermometer && $question->getEndPoint() === null) {
            throw new FunctionalException(
                message: 'Thermometer is still traveling; complete it before using a powerup.',
                errorKey: 'question.thermometer_still_traveling_powerup',
            );
        }
    }

    /**
     * @param list<AskedQuestion> $askedInRound
     */
    private function optionNotYetAsked(
        CatalogOption $option,
        array $askedInRound,
        AskedQuestion $currentQuestion,
    ): bool {
        if ($option->custom) {
            return false;
        }
        foreach ($askedInRound as $asked) {
            if ($asked->getUuid() === $currentQuestion->getUuid()) {
                continue;
            }
            if ($asked->getStatus() === QuestionStatus::Randomized) {
                continue;
            }
            if ($this->optionMatchesQuestion($option, $asked)) {
                return false;
            }
        }

        return true;
    }

    private function optionMatchesQuestion(CatalogOption $option, AskedQuestion $question): bool
    {
        if ($option->photoTarget !== null && $option->photoTarget !== $question->getPhotoTarget()) {
            return false;
        }
        if ($option->featureType !== null && $option->featureType !== $question->getFeatureType()) {
            return false;
        }
        if ($option->meters !== null) {
            $qMeters = match ($question->getCategory()) {
                QuestionCategory::Radar => $question->getRadiusMeters(),
                QuestionCategory::Thermometer => $question->getDistanceMeters(),
                QuestionCategory::Tentacles => $question->getWithinMeters(),
                default => null,
            };
            if ($qMeters === null || abs($option->meters - $qMeters) > 0.01) {
                return false;
            }
        }

        return true;
    }

    private function questionMatchesQuestion(AskedQuestion $a, AskedQuestion $b): bool
    {
        if ($a->getCategory() !== $b->getCategory()) {
            return false;
        }

        if ($a->getCategory() === QuestionCategory::Radar && $a->isCustomRadius() && $b->isCustomRadius()) {
            return true;
        }

        $aMeters = match ($a->getCategory()) {
            QuestionCategory::Radar => $a->getRadiusMeters(),
            QuestionCategory::Thermometer => $a->getDistanceMeters(),
            QuestionCategory::Tentacles => $a->getWithinMeters(),
            default => null,
        };
        $bMeters = match ($b->getCategory()) {
            QuestionCategory::Radar => $b->getRadiusMeters(),
            QuestionCategory::Thermometer => $b->getDistanceMeters(),
            QuestionCategory::Tentacles => $b->getWithinMeters(),
            default => null,
        };

        if ($aMeters !== null && $bMeters !== null) {
            if (abs($aMeters - $bMeters) > 0.01) {
                return false;
            }
        } elseif ($aMeters !== null || $bMeters !== null) {
            return false;
        }

        $aFeature = $a->getFeatureType();
        $bFeature = $b->getFeatureType();
        if ($aFeature !== null && $bFeature !== null) {
            if ($aFeature !== $bFeature) {
                return false;
            }
        } elseif ($aFeature !== null || $bFeature !== null) {
            return false;
        }

        $aPhoto = $a->getPhotoTarget();
        $bPhoto = $b->getPhotoTarget();
        if ($aPhoto !== null && $bPhoto !== null) {
            if ($aPhoto !== $bPhoto) {
                return false;
            }
        } elseif ($aPhoto !== null || $bPhoto !== null) {
            return false;
        }

        return true;
    }

    private function buildQuestionFromCatalog(
        Round $round,
        Player $asker,
        QuestionCategory $category,
        CatalogOption $option,
        AskedQuestion $original,
    ): AskedQuestion {
        $deadline = new \DateTimeImmutable()
            ->modify(sprintf('+%d minutes', RoundTiming::standardAnswerWindowMinutes()));
        $question = new AskedQuestion($round, $asker, $category, $deadline);

        match ($category) {
            QuestionCategory::Radar => $question
                ->setRadiusMeters($option->meters)
                ->setSeekerPoint($original->getSeekerPoint())
                ->setIsCustomRadius($option->custom),
            QuestionCategory::Thermometer => $this->applyThermometerReplacement($question, $option, $original),
            QuestionCategory::Matching => $question
                ->setFeatureType($option->featureType)
                ->setSeekerPoint($original->getSeekerPoint())
                ->setStationNameLength($option->stationNameLength),
            QuestionCategory::Measuring => $question
                ->setFeatureType($option->seaLevel ? null : $option->featureType)
                ->setSeekerPoint($original->getSeekerPoint())
                ->setSeekerAltitude($original->getSeekerAltitude())
                ->setSeaLevel($option->seaLevel),
            QuestionCategory::Tentacles => $question
                ->setFeatureType($option->featureType)
                ->setWithinMeters($option->meters)
                ->setSeekerPoint($original->getSeekerPoint()),
            QuestionCategory::Photos => $question
                ->setPhotoTarget($option->photoTarget),
        };

        return $question;
    }

    private function applyThermometerReplacement(
        AskedQuestion $question,
        CatalogOption $option,
        AskedQuestion $original,
    ): void {
        $start = $original->getStartPoint();
        $question->setDistanceMeters($option->meters)->setStartPoint($start);

        // Distance is a minimum-travel requirement: an arrival already past the new distance still counts.
        $end = $original->getEndPoint();
        if (
            $start !== null && $end !== null && $option->meters !== null
            && GeoDistance::metersBetween($start, $end) >= $option->meters
        ) {
            $question->setEndPoint($end);

            return;
        }

        $question->setRevealDeadlineAt(null);
    }

    private function validateAgainstCatalog(GameSize $size, Edition $edition, AskQuestionInput $data): void
    {
        $categories = CatalogDefinition::forGame($size, $edition);
        foreach ($categories as $category) {
            if ($category->key !== $data->category) {
                continue;
            }

            match ($data->category) {
                QuestionCategory::Radar, QuestionCategory::Thermometer => $this->validateNumericOption(
                    $category,
                    $data, $data->category === QuestionCategory::Radar ? $data->radiusMeters : $data->distanceMeters
                ),
                QuestionCategory::Matching => $this->validateMatchingOption($category, $data),
                QuestionCategory::Measuring => $this->validateMeasuringOption($category, $data),
                QuestionCategory::Tentacles => $this->validateTentaclesOption($category, $data),
                QuestionCategory::Photos => null,
            };

            return;
        }

        throw new FunctionalException(
            message: 'This question category is not available for this game size.',
            errorKey: 'asked_question.category_unavailable',
        );
    }

    private function validateNumericOption(CatalogCategory $category, AskQuestionInput $data, ?float $value): void
    {
        if ($value === null) {
            throw new FunctionalException(
                message: 'A numeric parameter is required for this question category.',
                errorKey: 'asked_question.numeric_required',
            );
        }

        foreach ($category->options as $option) {
            if ($option->meters !== null && abs($option->meters - $value) < 0.01) {
                return;
            }
        }

        if ($data->isCustomRadius && $data->category === QuestionCategory::Radar) {
            foreach ($category->options as $option) {
                if ($option->custom) {
                    if ($value > 0 && $value <= 1_000_000.0) {
                        return;
                    }
                    throw new FunctionalException(
                        message: 'Custom radius must be between 1 m and 1,000 km.',
                        errorKey: 'asked_question.custom_radius_range',
                    );
                }
            }
        }

        throw new FunctionalException(
            message: 'The provided distance/radius is not a valid preset for this game.',
            errorKey: 'asked_question.invalid_preset',
        );
    }

    private function validateMatchingOption(CatalogCategory $category, AskQuestionInput $data): void
    {
        if ($data->stationNameLength === true) {
            foreach ($category->options as $option) {
                if ($option->stationNameLength) {
                    return;
                }
            }

            throw new FunctionalException(
                message: 'The station name length matching option is not available for this game.',
                errorKey: 'asked_question.station_name_length_unavailable',
            );
        }

        if ($data->featureType !== null) {
            $this->validateFeatureOption($category, $data);

            return;
        }

        if ($data->transitLineOsmId === null || $data->transitLineOsmType === null) {
            throw new FunctionalException(
                message: 'A feature type or a transit line is required for this question category.',
                errorKey: 'asked_question.matching_target_required',
            );
        }

        foreach ($category->options as $option) {
            if ($option->transitLine) {
                return;
            }
        }

        throw new FunctionalException(
            message: 'The transit line matching option is not available for this game.',
            errorKey: 'asked_question.transit_line_unavailable',
        );
    }

    private function validateMeasuringOption(CatalogCategory $category, AskQuestionInput $data): void
    {
        if ($data->seaLevel === true) {
            foreach ($category->options as $option) {
                if ($option->seaLevel) {
                    return;
                }
            }

            throw new FunctionalException(
                message: 'The sea level measuring option is not available for this game.',
                errorKey: 'asked_question.sea_level_unavailable',
            );
        }

        $this->validateFeatureOption($category, $data);
    }

    private function validateFeatureOption(CatalogCategory $category, AskQuestionInput $data): void
    {
        if ($data->featureType === null) {
            throw new FunctionalException(
                message: 'A feature type is required for this question category.',
                errorKey: 'asked_question.feature_type_required',
            );
        }
        foreach ($category->options as $option) {
            if ($option->featureType === $data->featureType) {
                return;
            }
        }
        throw new FunctionalException(
            message: 'The requested feature type is not available for this game.',
            errorKey: 'asked_question.feature_type_unavailable',
        );
    }

    private function validateTentaclesOption(CatalogCategory $category, AskQuestionInput $data): void
    {
        if ($data->featureType === null) {
            throw new FunctionalException(
                message: 'A feature type is required for Tentacles questions.',
                errorKey: 'asked_question.tentacles_feature_required',
            );
        }
        foreach ($category->options as $option) {
            if (
                $option->featureType === $data->featureType
                && (
                    $option->meters === null
                    || ($data->withinMeters !== null && abs($option->meters - $data->withinMeters) < 0.01)
                )
            ) {
                return;
            }
        }
        throw new FunctionalException(
            message: 'The requested Tentacles option is not available for this game size.',
            errorKey: 'asked_question.tentacles_unavailable',
        );
    }
}

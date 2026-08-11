<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AskQuestionInput;
use App\Entity\AskedQuestion;
use App\Entity\ChatMessage;
use App\Entity\Feature;
use App\Entity\Game;
use App\Entity\GameTransitLine;
use App\Entity\HidingZone;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\MeasuringResult;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\Enum\QuestionStatus;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Enum\ThermometerResult;
use App\Exception\FunctionalException;
use App\GeoDistance;
use App\Repository\AskedQuestionRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\FeatureRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\RoundMembershipRepository;
use App\Service\ChatService;
use App\Service\MercureJwtService;
use App\Service\OverpassService;
use App\Service\PossibleAreaService;
use App\Service\QuestionMessageFormatter;
use App\Service\QuestionService;
use App\Service\RoundClock;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(QuestionService::class)]
final class QuestionServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function radarIsWithinAtOrInsideTheRadiusAndAMissBeyondIt(): void
    {
        $service = $this->serviceWithChatThatNeverPosts();
        $hider = new Point(0.0, 0.0);
        $seeker = new Point(0.0, 1.0);
        $exact = GeoDistance::metersBetween($hider, $seeker);

        self::assertTrue($service->computeRadarAnswer($hider, $seeker, $exact));
        self::assertTrue($service->computeRadarAnswer($hider, $seeker, $exact + 0.001));
        self::assertFalse($service->computeRadarAnswer($hider, $seeker, $exact - 0.001));
        self::assertTrue($service->computeRadarAnswer($hider, $seeker, 112_000.0));
        self::assertFalse($service->computeRadarAnswer($hider, $seeker, 111_000.0));
    }

    #[Test]
    public function thermometerIsHotterWhenTheEndPointIsCloserAndColderWhenFurther(): void
    {
        $service = $this->serviceWithChatThatNeverPosts();
        $hider = new Point(0.0, 0.0);
        $start = new Point(0.0, 2.0);

        self::assertSame(
            ThermometerResult::Hotter,
            $service->computeThermometerAnswer($hider, $start, new Point(0.0, 1.0)),
        );
        self::assertSame(
            ThermometerResult::Colder,
            $service->computeThermometerAnswer($hider, $start, new Point(0.0, 3.0)),
        );
    }

    #[Test]
    public function aSeekerCanAskARadarQuestionAndItIsPostedAsAQuestionMessage(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::once())->method('save');

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $question = $service->ask($round, $asker, $this->radarInput($asker->getUuid()));

        self::assertSame(QuestionCategory::Radar, $question->getCategory());
        self::assertSame(500.0, $question->getRadiusMeters());
        self::assertNull($question->getRevealedAt());
        $deadline = $question->getRevealDeadlineAt();
        self::assertNotNull($deadline);
        self::assertSame(
            5,
            intdiv($deadline->getTimestamp() - $question->getAskedAt()->getTimestamp(), 60),
        );
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Question, $messages[0]->getType());
        self::assertSame($asker->getUuid(), $messages[0]->getSender()?->getUuid());
        self::assertSame('Are you within 500 m of me?', $messages[0]->getBody());
        self::assertSame($question->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function aHiderCannotAskAQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->ask($round, $asker, $this->radarInput($asker->getUuid()));
    }

    #[Test]
    public function aSeekerCannotAskWhileTheHidingPeriodIsStillRunning(): void
    {
        $round = $this->roundStillHiding($game = new Game('Berlin', GameSize::Small, Edition::Metric));
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $errorKey = $this->askAndCaptureErrorKey($round, $asker);

        self::assertSame('question.hiding_period', $errorKey);
    }

    #[Test]
    public function aSeekerCanAskAsSoonAsTheHidingPeriodHasElapsedEvenBeforeTheStatusFlips(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->roundStillHiding($game)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 second'));
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hiderPlayer = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $locations = $this->hiderPingRepository($round, $hiderPlayer, new Point(0.0, 0.001));

        $messages = [];
        $service = $this->service($memberships, $askedQuestions, $locations, $this->chatCapturing($messages));

        $question = $service->ask($round, $asker, $this->radarInput($asker->getUuid()));

        self::assertSame(QuestionCategory::Radar, $question->getCategory());
    }

    #[Test]
    public function aSeekerCannotAskWhileAMovePeriodFreezesThem(): void
    {
        $round = $this->roundStillHiding($game = new Game('Berlin', GameSize::Small, Edition::Metric))
            ->setInMovePeriod(true);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $errorKey = $this->askAndCaptureErrorKey($round, $asker);

        self::assertSame('question.seekers_frozen', $errorKey);
    }

    #[Test]
    public function aSeekerCannotAskBeforeTheRoundHasStarted(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $errorKey = $this->askAndCaptureErrorKey($round, $asker);

        self::assertSame('question.round_not_seeking', $errorKey);
    }

    #[Test]
    public function aSeekerCannotAskOnceTheRoundHasEnded(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game)->setStatus(RoundStatus::Ended);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $errorKey = $this->askAndCaptureErrorKey($round, $asker);

        self::assertSame('question.round_not_seeking', $errorKey);
    }

    #[Test]
    public function askingIsRejectedWhileAnUnexpiredQuestionIsStillOutstanding(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $outstanding = $this->radarQuestion($round, $asker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn($outstanding);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->ask($round, $asker, $this->radarInput($asker->getUuid()));
    }

    #[Test]
    public function askingIsUnblockedOnceTheOutstandingQuestionDeadlineHasLazilyPassed(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hiderPlayer = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $outstanding = $this->radarQuestion($round, $asker, new \DateTimeImmutable('-1 minute'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn($outstanding);
        $locations = $this->hiderPingRepository($round, $hiderPlayer, new Point(0.0, 0.001));

        $messages = [];
        $service = $this->service($memberships, $askedQuestions, $locations, $this->chatCapturing($messages));

        $question = $service->ask($round, $asker, $this->radarInput($asker->getUuid()));

        self::assertNotNull($outstanding->getRevealedAt());
        self::assertNull($question->getRevealedAt());
        self::assertCount(2, $messages);
        self::assertSame(ChatMessageType::Answer, $messages[0]->getType());
        self::assertSame('Yes, within range', $messages[0]->getBody());
        self::assertSame(ChatMessageType::Question, $messages[1]->getType());
        self::assertSame('Are you within 500 m of me?', $messages[1]->getBody());
    }

    #[Test]
    public function aSeekerCannotRevealAQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->radarQuestion($round, $seeker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $seeker, Side::Seeker));

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->reveal($question, $seeker);
    }

    #[Test]
    public function anAlreadyRevealedQuestionCannotBeRevealedAgain(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('+5 minutes'));
        $question->setRevealedAt(new \DateTimeImmutable());

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->reveal($question, $hider);
    }

    #[Test]
    public function revealingWithoutAnyRecordedHiderLocationIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')->willReturn(null);

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatThatNeverPosts(),
        )->reveal($question, $hider);
    }

    #[Test]
    public function aHiderManuallyRevealsARadarHitAndTheAnswerIsPostedToChat(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, new Point(0.0, 0.001)));

        $messages = [];
        $service = $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
        );

        $revealed = $service->reveal($question, $hider);

        self::assertNotNull($revealed->getRevealedAt());
        self::assertTrue($revealed->getRadarAnswer());
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Answer, $messages[0]->getType());
        self::assertSame($hider->getUuid(), $messages[0]->getSender()?->getUuid());
        self::assertSame('Yes, within range', $messages[0]->getBody());
        self::assertSame($question->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function revealPastDeadlineAnswersEveryOverdueQuestionWithoutAClientAsking(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $overdue = $this->radarQuestion($round, $hider, new \DateTimeImmutable('-1 minute'));

        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findPastRevealDeadline')->willReturn([$overdue]);

        $messages = [];
        $this->service(
            $this->createStub(RoundMembershipRepository::class),
            $askedQuestions,
            $this->hiderPingRepository($round, $hider, new Point(0.0, 5.0)),
            $this->chatCapturing($messages),
        )->revealPastDeadline();

        self::assertNotNull($overdue->getRevealedAt());
        self::assertCount(1, $messages);
        self::assertSame($overdue->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function currentStateAutoRevealsAQuestionPastItsDeadline(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('-1 minute'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $locations = $this->hiderPingRepository($round, $hider, new Point(0.0, 5.0));

        $messages = [];
        $service = $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
        );

        $state = $service->currentState($question);

        self::assertNotNull($state->getRevealedAt());
        self::assertFalse($state->getRadarAnswer());
        self::assertCount(1, $messages);
        self::assertSame('No, not within range', $messages[0]->getBody());
    }

    #[Test]
    public function anAutoRevealThatLosesTheClaimPostsNothingAndRaisesNothing(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('-1 minute'));

        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        // Another request past the same deadline already answered it.
        $askedQuestions->method('claimUnrevealed')->willReturn(false);
        $askedQuestions->expects(self::never())->method('save');

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $state = $this->service(
            $this->createStub(RoundMembershipRepository::class),
            $askedQuestions,
            $this->hiderPingRepository($round, $hider, new Point(0.0, 5.0)),
            $this->chatCapturing($messages),
        )->currentState($question);

        self::assertNull($state->getRevealedAt());
        self::assertNull($state->getRadarAnswer());
        self::assertSame([], $messages, 'Every client refresh trips the deadline: one answer, not one each.');
    }

    #[Test]
    public function aPhotoAnswerThatLosesTheClaimIsRejectedBeforeTheUploadIsStored(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = new AskedQuestion($round, $hider, QuestionCategory::Photos, new \DateTimeImmutable('+10 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('claimUnrevealed')->willReturn(false);
        $askedQuestions->expects(self::never())->method('save');
        $imageStorage = $this->createMock(ImageStorageInterface::class);
        $imageStorage->expects(self::never())->method('store');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Question has already been revealed.');

        $this->service(
            $memberships,
            $askedQuestions,
            $this->hiderPingRepository($round, $hider, new Point(0.0, 5.0)),
            $this->chatThatNeverPosts(),
            null,
            null,
            null,
            null,
            $imageStorage,
        )->revealWithPhoto($question, $hider, $this->uploadedPhoto());
    }

    #[Test]
    public function aManualRevealThatLosesTheClaimTellsTheHiderItWasAlreadyAnswered(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('claimUnrevealed')->willReturn(false);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Question has already been revealed.');

        $this->service(
            $memberships,
            $askedQuestions,
            $this->hiderPingRepository($round, $hider, new Point(0.0, 5.0)),
            $this->chatThatNeverPosts(),
        )->reveal($question, $hider);
    }

    #[Test]
    public function currentStateLeavesAQuestionStillWithinItsWindowUntouched(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $hider, new \DateTimeImmutable('+5 minutes'));

        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::never())->method('save');

        $state = $this->service(
            $this->createStub(RoundMembershipRepository::class),
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->currentState($question);

        self::assertNull($state->getRevealedAt());
    }

    #[Test]
    public function aThermometerAskStartsTravelingAndPostsAStartNotice(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::once())->method('save');

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $question = $service->ask($round, $asker, $this->thermometerInput($asker->getUuid()));

        self::assertNull($question->getRevealDeadlineAt());
        self::assertNull($question->getEndPoint());
        self::assertNotNull($question->getStartPoint());
        self::assertSame(1000.0, $question->getDistanceMeters());
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::QuestionInfo, $messages[0]->getType());
        self::assertSame($asker->getUuid(), $messages[0]->getSender()?->getUuid());
        self::assertSame("I'm starting a 1 km thermometer...", $messages[0]->getBody());
        self::assertSame($question->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function aThermometerAskWithoutADistanceIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::never())->method('save');

        $input = $this->thermometerInput($asker->getUuid());
        $input->distanceMeters = null;

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->ask($round, $asker, $input);
    }

    #[Test]
    public function completingAThermometerPostsTheQuestionAndStartsTheAnswerWindow(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->travelingThermometer($round, $asker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::once())->method('save');

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $completed = $service->completeThermometer($question, $asker, 0.009, 0.0);

        self::assertNotNull($completed->getEndPoint());
        self::assertSame(1000.0, $completed->getDistanceMeters());
        $deadline = $completed->getRevealDeadlineAt();
        self::assertNotNull($deadline);
        self::assertEqualsWithDelta(300, $deadline->getTimestamp() - time(), 5);
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Question, $messages[0]->getType());
        self::assertSame($asker->getUuid(), $messages[0]->getSender()?->getUuid());
        self::assertSame("I've just traveled 1 km. Am I hotter or colder now?", $messages[0]->getBody());
        self::assertSame($question->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function completingAnotherSeekersThermometerIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $other = new Player($game, AccountFactory::create('Carol', 'test-password'));
        $question = $this->travelingThermometer($round, $asker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $other, Side::Seeker));

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->completeThermometer($question, $other, 0.009, 0.0);
    }

    #[Test]
    public function completingANonThermometerQuestionIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->radarQuestion($round, $asker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->completeThermometer($question, $asker, 0.009, 0.0);
    }

    #[Test]
    public function revealingAThermometerStillTravelingIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->travelingThermometer($round, $asker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->reveal($question, $hider);
    }

    #[Test]
    public function autoRevealIsSkippedWhileAThermometerIsTraveling(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->travelingThermometer($round, $asker);

        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::never())->method('save');

        $state = $this->service(
            $this->createStub(RoundMembershipRepository::class),
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->currentState($question);

        self::assertNull($state->getRevealedAt());
    }

    #[Test]
    public function aPhotoQuestionRequiresATarget(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->ask($round, $asker, $this->photoInput($asker->getUuid(), null));
    }

    #[Test]
    public function aPhotoTargetAboveTheGameSizeIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->ask($round, $asker, $this->photoInput($asker->getUuid(), PhotoTarget::StreetsTraced));
    }

    #[Test]
    public function aSeekerCanAskAPhotoQuestionWithAValidTarget(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::once())->method('save');

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $question = $service->ask($round, $asker, $this->photoInput($asker->getUuid(), PhotoTarget::Tree));

        self::assertSame(PhotoTarget::Tree, $question->getPhotoTarget());
        $deadline = $question->getRevealDeadlineAt();
        self::assertNotNull($deadline);
        self::assertSame(
            10,
            intdiv($deadline->getTimestamp() - $question->getAskedAt()->getTimestamp(), 60),
        );
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Question, $messages[0]->getType());
        self::assertSame('Send me a photo of a tree.', $messages[0]->getBody());
    }

    #[Test]
    public function anAskForACategoryMissingFromTheGameSizeIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Tentacles;
        $input->featureType = FeatureType::Museum;
        $input->withinMeters = 2000.0;

        self::assertSame('asked_question.category_unavailable', $this->askAndCaptureErrorKey($round, $asker, $input));
    }

    #[Test]
    public function anAskWithARadiusThatIsNotACatalogPresetIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $input = $this->radarInput($asker->getUuid());
        $input->radiusMeters = 12345.0;

        self::assertSame('asked_question.invalid_preset', $this->askAndCaptureErrorKey($round, $asker, $input));
    }

    #[Test]
    public function aCustomRadiusOutsideTheAllowedRangeIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $input = $this->radarInput($asker->getUuid());
        $input->radiusMeters = 2_000_000.0;
        $input->isCustomRadius = true;

        self::assertSame('asked_question.custom_radius_range', $this->askAndCaptureErrorKey($round, $asker, $input));
    }

    #[Test]
    public function anAskForAFeatureTypeMissingFromTheCatalogIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Measuring;
        $input->featureType = FeatureType::TransitStation;

        self::assertSame(
            'asked_question.feature_type_unavailable',
            $this->askAndCaptureErrorKey($round, $asker, $input),
        );
    }

    #[Test]
    public function theRevealedAnswerRepliesToTheAskMessage(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturnCallback(
            static fn (Round $r, Player $player): RoundMembership => new RoundMembership(
                $r,
                $player,
                $player === $asker ? Side::Seeker : Side::Hider,
            ),
        );
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, new Point(0.0, 0.001)));

        $messages = [];
        $service = $this->service($memberships, $askedQuestions, $locations, $this->chatCapturing($messages));

        $question = $service->ask($round, $asker, $this->radarInput($asker->getUuid()));
        $service->reveal($question, $hider);

        self::assertCount(2, $messages);
        self::assertSame(ChatMessageType::Question, $messages[0]->getType());
        self::assertSame(ChatMessageType::Answer, $messages[1]->getType());
        self::assertSame($hider->getUuid(), $messages[1]->getSender()?->getUuid());
        self::assertSame($messages[0]->getUuid(), $messages[1]->getReplyToUuid());
        self::assertSame($question->getUuid(), $messages[1]->getQuestionUuid());
    }

    #[Test]
    public function theCancelNoticeRepliesToTheLatestMessageOfTheQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $question = $service->ask($round, $asker, $this->radarInput($asker->getUuid()));
        $service->cancel($question, $asker);

        self::assertCount(2, $messages);
        self::assertSame(ChatMessageType::System, $messages[1]->getType());
        self::assertSame($messages[0]->getUuid(), $messages[1]->getReplyToUuid());
    }

    #[Test]
    public function cancellingPostsANoticeBeforeRemovingTheQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->radarQuestion($round, $asker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::once())->method('remove')->willReturnCallback(
            static function () use (&$messages): void {
                self::assertCount(1, $messages);
            },
        );

        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $service->cancel($question, $asker);

        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::System, $messages[0]->getType());
        self::assertNull($messages[0]->getSender());
        self::assertSame('Question cancelled.', $messages[0]->getBody());
        self::assertSame($question->getUuid(), $messages[0]->getQuestionUuid());
    }

    #[Test]
    public function aHiderRevealsAMatchingSameWhenNearestFeaturesShare(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $park = new Feature($game, FeatureType::Park, 'Central Park', new Point(0.0, 0.0006));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturn([$park]);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Matching, FeatureType::Park);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertTrue($question->getMatchingAnswer());
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Answer, $messages[0]->getType());
        self::assertSame('Same', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderRevealsMeasuringCloserWhenNearerToItsFeature(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $near = new Feature($game, FeatureType::Hospital, 'Near Clinic', new Point(0.0, 0.001));
        $far = new Feature($game, FeatureType::Hospital, 'Far Hospital', new Point(0.0, 0.02));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturnOnConsecutiveCalls([$near], [$far]);
        $features->method('distanceToFeature')->willReturnOnConsecutiveCalls(55.0, 2200.0);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Measuring, FeatureType::Hospital);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertSame(MeasuringResult::Closer, $question->getMeasuringAnswer());
        self::assertCount(1, $messages);
        self::assertSame('Closer', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderRevealsSeaLevelCloserWhenNearerToSeaLevel(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->seaLevelQuestion($round, $hider, 30.0);
        $this->seaLevelRevealService($round, $hider, 10.0, $messages)->reveal($question, $hider);

        self::assertSame(MeasuringResult::Closer, $question->getMeasuringAnswer());
        self::assertCount(1, $messages);
        self::assertSame('Closer to sea level', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderRevealsSeaLevelFurtherWhenHigherAboveSeaLevel(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->seaLevelQuestion($round, $hider, 10.0);
        $this->seaLevelRevealService($round, $hider, 50.0, $messages)->reveal($question, $hider);

        self::assertSame(MeasuringResult::Further, $question->getMeasuringAnswer());
        self::assertSame('Further from sea level', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderBelowSeaLevelIsFurtherWhenItsAbsoluteAltitudeIsLarger(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->seaLevelQuestion($round, $hider, 10.0);
        $this->seaLevelRevealService($round, $hider, -20.0, $messages)->reveal($question, $hider);

        self::assertSame(MeasuringResult::Further, $question->getMeasuringAnswer());
    }

    #[Test]
    public function seaLevelSetsNoAnswerWhenTheHiderAltitudeIsMissing(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->seaLevelQuestion($round, $hider, 10.0);
        $this->seaLevelRevealService($round, $hider, null, $messages)->reveal($question, $hider);

        self::assertNull($question->getMeasuringAnswer());
    }

    #[Test]
    public function seaLevelSetsNoAnswerWhenTheSeekerAltitudeIsMissing(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->seaLevelQuestion($round, $hider, null);
        $this->seaLevelRevealService($round, $hider, 10.0, $messages)->reveal($question, $hider);

        self::assertNull($question->getMeasuringAnswer());
    }

    #[Test]
    public function cancelingAnExpiredQuestionTriggersAutoRevealBeforeCheckingRevealedAt(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = $this->radarQuestion($round, $asker, new \DateTimeImmutable('-1 minute'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $locations = $this->hiderPingRepository($round, $hider, new Point(0.0, 0.001));

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Question has already been revealed.');

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
        )->cancel($question, $asker);
    }

    #[Test]
    public function anySeekerCanCancelAQuestionNotJustTheAsker(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $bob = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $carol = new Player($game, AccountFactory::create('Carol', 'test-password'));
        $question = $this->radarQuestion($round, $bob, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $carol, Side::Seeker));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $service = $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        );

        $service->cancel($question, $carol);

        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::System, $messages[0]->getType());
        self::assertNull($messages[0]->getSender());
        self::assertSame('Question cancelled.', $messages[0]->getBody());
    }

    #[Test]
    public function completingAThermometerWithTheSameStartAndEndPointIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->travelingThermometer($round, $asker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('You have not moved from the thermometer start point yet.');

        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->completeThermometer($question, $asker, 0.0, 0.0);
    }

    #[Test]
    public function tentaclesNotWithinRangeStoresTheConstantAsTheAnswer(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Tentacles, FeatureType::Museum);
        $question->setSeekerPoint(new Point(0.0, 0.0))->setWithinMeters(1.0);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertSame('Not within reach', $question->getTentaclesAnswer());
        self::assertCount(1, $messages);
        self::assertSame('Not within reach', $messages[0]->getBody());
    }

    #[Test]
    public function tentaclesWithAnUnnamedFeatureFallsBackToTheTypeLabel(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $unnamed = new Feature($game, FeatureType::Museum, null, new Point(0.0, 0.0006));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturn([$unnamed]);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Tentacles, FeatureType::Museum);
        $question->setWithinMeters(2000.0);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertSame('An unnamed museum', $question->getTentaclesAnswer());
        self::assertCount(1, $messages);
        self::assertSame('An unnamed museum', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderRevealsTentaclesNamingTheNearestFeatureWithinRange(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $museum = new Feature($game, FeatureType::Museum, 'City Museum', new Point(0.0, 0.0006));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturn([$museum]);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Tentacles, FeatureType::Museum);
        $question->setWithinMeters(2000.0);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertSame('City Museum', $question->getTentaclesAnswer());
        self::assertCount(1, $messages);
        self::assertSame('City Museum', $messages[0]->getBody());
    }

    #[Test]
    public function aMatchingRevealWithNoFeatureInBoundaryIsNullButStillReveals(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturn([]);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, new Point(0.0, 0.0)));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Matching, FeatureType::CommercialAirport);
        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
            $features,
        )->reveal($question, $hider);

        self::assertNull($question->getMatchingAnswer());
        self::assertNotNull($question->getRevealedAt());
        self::assertCount(1, $messages);
        self::assertSame('No answer available', $messages[0]->getBody());
    }

    #[Test]
    public function randomizingATravelingThermometerYieldsATravelingReplacement(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $original = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 1000.0)]);

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $replacement = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->randomize($original, $hider, $this->uploadedPhoto());

        self::assertSame(QuestionCategory::Thermometer, $replacement->getCategory());
        self::assertSame(5000.0, $replacement->getDistanceMeters());
        self::assertNull($replacement->getEndPoint());
        self::assertNull($replacement->getRevealDeadlineAt());
        self::assertSame(QuestionStatus::Randomized, $original->getStatus());
        self::assertSame($replacement->getUuid(), $original->getReplacedByUuid());
        self::assertSame($original->getUuid(), $replacement->getReplacedQuestionUuid());
    }

    #[Test]
    public function randomizingACompletedThermometerToALowerDistanceKeepsTheArrival(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $end = new Point(0.0, 0.02);
        $original = new AskedQuestion($round, $seeker, QuestionCategory::Thermometer, new \DateTimeImmutable('+5 minutes'));
        $original->setStartPoint(new Point(0.0, 0.0))->setDistanceMeters(5000.0)->setEndPoint($end);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 5000.0)]);

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $replacement = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->randomize($original, $hider, $this->uploadedPhoto());

        self::assertSame(1000.0, $replacement->getDistanceMeters());
        self::assertSame($end, $replacement->getEndPoint());
        self::assertNotNull($replacement->getRevealDeadlineAt());
    }

    #[Test]
    public function randomizingACompletedThermometerToAHigherDistanceReturnsToTraveling(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $end = new Point(0.0, 0.005);
        $original = new AskedQuestion($round, $seeker, QuestionCategory::Thermometer, new \DateTimeImmutable('+5 minutes'));
        $original->setStartPoint(new Point(0.0, 0.0))->setDistanceMeters(1000.0)->setEndPoint($end);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 1000.0)]);

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $replacement = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->randomize($original, $hider, $this->uploadedPhoto());

        self::assertSame(5000.0, $replacement->getDistanceMeters());
        self::assertNull($replacement->getEndPoint());
        self::assertNull($replacement->getRevealDeadlineAt());
    }

    #[Test]
    public function randomizingAThermometerWithNoRemainingOptionIsRejectedAndConsumesNothing(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $original = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findByRoundAndCategory')->willReturn([
            $original,
            $this->answeredThermometer($round, $seeker, 1000.0),
            $this->answeredThermometer($round, $seeker, 5000.0),
        ]);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('All questions in this category have already been asked.');

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->randomize($original, $hider, $this->uploadedPhoto());
    }

    #[Test]
    public function randomizingAQuestionATeammateJustClaimedCreatesNoSecondReplacement(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $original = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 1000.0)]);
        // The other hider's UPDATE already moved the row off Open, so this one matches nothing.
        $askedQuestions->method('claimOpen')->willReturn(false);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Question is no longer open.');

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->randomize($original, $hider, $this->uploadedPhoto());
        self::assertSame([], $messages);
    }

    #[Test]
    public function vetoingAQuestionATeammateJustClaimedIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->radarQuestion($round, $seeker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('claimOpen')->willReturn(false);
        $askedQuestions->expects(self::never())->method('save');

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Question is no longer open.');

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        )->veto($question, $hider, $this->uploadedPhoto());
    }

    #[Test]
    public function vetoingAQuestionAttachesThePhotoOfTheCardPlayed(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = $this->radarQuestion($round, $seeker, new \DateTimeImmutable('+5 minutes'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
            imageStorage: $this->imageStorageReturning('veto-card.jpg'),
        )->veto($question, $hider, $this->uploadedPhoto());

        $posted = self::capturedWithBodyKey($messages, 'system.question_vetoed');
        self::assertSame('veto-card.jpg', $posted?->getImageRef());
    }

    #[Test]
    public function randomizingAQuestionAttachesThePhotoOfTheCardPlayed(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $original = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 1000.0)]);

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
            imageStorage: $this->imageStorageReturning('randomize-card.jpg'),
        )->randomize($original, $hider, $this->uploadedPhoto());

        $posted = self::capturedWithBodyKey($messages, 'system.question_randomized');
        self::assertSame('randomize-card.jpg', $posted?->getImageRef());
    }

    #[Test]
    public function claimingAQuestionAsksForTheOpenToRandomizedTransition(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $original = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findByRoundAndCategory')
            ->willReturn([$original, $this->answeredThermometer($round, $seeker, 1000.0)]);
        $askedQuestions->expects(self::once())
            ->method('claimOpen')
            ->with($original, QuestionStatus::Randomized)
            ->willReturn(true);

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->randomize($original, $hider, $this->uploadedPhoto());

        self::assertSame(QuestionStatus::Randomized, $original->getStatus());
    }

    #[Test]
    public function vetoingATravelingThermometerIsStillRejected(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $traveling = $this->travelingThermometer($round, $seeker);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));

        $this->expectException(FunctionalException::class);
        $this->expectExceptionMessage('Thermometer is still traveling');

        /** @var list<ChatMessage> $messages */
        $messages = [];
        $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
        )->veto($traveling, $hider, $this->uploadedPhoto());
    }

    #[Test]
    public function askingAMatchingTransitLineResolvesTheLineAndStoresItsLabel(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $line = new GameTransitLine($game, 'relation', 42, 'S1', 'Airport Line', 'subway', 'BVG', null, null);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $transitLines = $this->createStub(GameTransitLineRepository::class);
        $transitLines->method('findOneByGameAndOsm')->willReturn($line);

        $messages = [];
        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
            null,
            $transitLines,
        );

        $question = $service->ask($round, $asker, $this->transitLineInput($asker->getUuid()));

        self::assertSame(QuestionCategory::Matching, $question->getCategory());
        self::assertNull($question->getFeatureType());
        self::assertSame($line->getUuid(), $question->getTransitLineUuid());
        self::assertSame('S1: Airport Line', $question->getTransitLineLabel());
        self::assertCount(1, $messages);
        self::assertSame('I am riding S1: Airport Line. Does it stop at your station?', $messages[0]->getBody());
    }

    #[Test]
    public function askingAMatchingTransitLineWithAnUnknownLineIsRejected(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $askedQuestions->expects(self::never())->method('save');
        $transitLines = $this->createStub(GameTransitLineRepository::class);
        $transitLines->method('findOneByGameAndOsm')->willReturn(null);

        $this->expectException(FunctionalException::class);

        $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
            null,
            $transitLines,
        )->ask($round, $asker, $this->transitLineInput($asker->getUuid()));
    }

    #[Test]
    public function autoRevealingATransitLineIsYesWhenTheZoneStationServesTheLine(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $line = new GameTransitLine($game, 'relation', 42, 'S1', 'Airport Line', 'subway', 'BVG', null, null);
        $question = new AskedQuestion($round, $hider, QuestionCategory::Matching, new \DateTimeImmutable('-1 minute'));
        $question->setTransitLineUuid($line->getUuid())->setTransitLineLabel('S1: Airport Line');

        $messages = [];
        $state = $this->transitLineRevealService($round, $hider, $line, ['S1', 'S7'], $this->chatCapturing($messages))
            ->currentState($question);

        self::assertTrue($state->getMatchingAnswer());
        self::assertNotNull($state->getRevealedAt());
        self::assertCount(1, $messages);
        self::assertSame(ChatMessageType::Answer, $messages[0]->getType());
        self::assertSame('Yes, S1: Airport Line stops at my station.', $messages[0]->getBody());
    }

    #[Test]
    public function autoRevealingATransitLineIsNoWhenTheZoneStationDoesNotServeTheLine(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $line = new GameTransitLine($game, 'relation', 42, 'S1', 'Airport Line', 'subway', 'BVG', null, null);
        $question = new AskedQuestion($round, $hider, QuestionCategory::Matching, new \DateTimeImmutable('-1 minute'));
        $question->setTransitLineUuid($line->getUuid())->setTransitLineLabel('S1: Airport Line');

        $messages = [];
        $state = $this->transitLineRevealService($round, $hider, $line, ['U2'], $this->chatCapturing($messages))
            ->currentState($question);

        self::assertFalse($state->getMatchingAnswer());
        self::assertSame('No, S1: Airport Line does not stop at my station.', $messages[0]->getBody());
    }

    /** @param list<string> $servingRefs */
    private function transitLineRevealService(
        Round $round,
        Player $hider,
        GameTransitLine $line,
        array $servingRefs,
        ChatService $chat,
    ): QuestionService {
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $locations = $this->hiderPingRepository($round, $hider, new Point(1.0, 1.0));

        $hidingZones = $this->createStub(HidingZoneRepository::class);
        $hidingZones->method('findOneByRound')->willReturn(new HidingZone($round, new Point(2.0, 2.0), 800.0));
        $transitLines = $this->createStub(GameTransitLineRepository::class);
        $transitLines->method('findOneByGameAndUuid')->willReturn($line);
        $stations = $this->createStub(GameTransitStationRepository::class);
        $stations->method('findNearestServingRefs')->willReturn($servingRefs);

        return $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $chat,
            null,
            $transitLines,
            $stations,
            $hidingZones,
        );
    }

    #[Test]
    public function aHiderRevealsStationNameLengthSameWhenNamesAreEqualLength(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $hiderStation = new Feature($game, FeatureType::TransitStation, 'Alpha', new Point(0.0, 0.0006));
        $seekerStation = new Feature($game, FeatureType::TransitStation, 'Bravo', new Point(0.0, 0.0));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturnOnConsecutiveCalls([$hiderStation], [$seekerStation]);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->stationNameLengthQuestion($round, $hider);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertTrue($question->getMatchingAnswer());
        self::assertSame(ChatMessageType::Answer, $messages[0]->getType());
        self::assertSame('Same', $messages[0]->getBody());
    }

    #[Test]
    public function aHiderRevealsStationNameLengthDifferentWhenNamesDifferInLength(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $hiderStation = new Feature($game, FeatureType::TransitStation, 'Alpha', new Point(0.0, 0.0006));
        $seekerStation = new Feature($game, FeatureType::TransitStation, 'Charlie', new Point(0.0, 0.0));

        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturnOnConsecutiveCalls([$hiderStation], [$seekerStation]);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->stationNameLengthQuestion($round, $hider);
        $this->featureRevealService($round, $hider, $features, $messages)->reveal($question, $hider);

        self::assertFalse($question->getMatchingAnswer());
        self::assertSame('Different', $messages[0]->getBody());
    }

    #[Test]
    public function askingAStationNameLengthMatchingSetsTheFlagAndTransitStationFeature(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = $this->seekingRound($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->askedQuestionsStub();
        $askedQuestions->method('findOutstandingByRound')->willReturn(null);
        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(1);

        $messages = [];
        $question = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatCapturing($messages),
            $features,
        )->ask($round, $asker, $this->stationNameLengthInput($asker->getUuid()));

        self::assertSame(QuestionCategory::Matching, $question->getCategory());
        self::assertTrue($question->isStationNameLength());
        self::assertSame(FeatureType::TransitStation, $question->getFeatureType());
        self::assertNull($question->getTransitLineUuid());
    }

    private function stationNameLengthInput(string $askerUuid): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Matching;
        $input->stationNameLength = true;
        $input->seekerLat = 0.0;
        $input->seekerLng = 0.0;

        return $input;
    }

    private function stationNameLengthQuestion(Round $round, Player $hider): AskedQuestion
    {
        $question = $this->featureQuestion($round, $hider, QuestionCategory::Matching, FeatureType::TransitStation);
        $question->setStationNameLength(true);

        return $question;
    }

    private function service(
        RoundMembershipRepository $memberships,
        AskedQuestionRepository $askedQuestions,
        PlayerLocationRepository $locations,
        ChatService $chat,
        ?FeatureRepository $features = null,
        ?GameTransitLineRepository $transitLines = null,
        ?GameTransitStationRepository $transitStations = null,
        ?HidingZoneRepository $hidingZones = null,
        ?ImageStorageInterface $imageStorage = null,
    ): QuestionService {
        $imageStorage ??= $this->createStub(ImageStorageInterface::class);
        $features ??= $this->createStub(FeatureRepository::class);
        $transitLines ??= $this->createStub(GameTransitLineRepository::class);
        $transitStations ??= $this->createStub(GameTransitStationRepository::class);
        $hidingZones ??= $this->createStub(HidingZoneRepository::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        return new QuestionService(
            $memberships,
            $askedQuestions,
            $transitLines,
            $transitStations,
            $hidingZones,
            $locations,
            $chat,
            $this->possibleAreaThatDoesNothing(),
            $features,
            new QuestionMessageFormatter($features),
            $this->createStub(OverpassService::class),
            $this->createStub(LoggerInterface::class),
            $imageStorage,
            new RoundClock(),
            $entityManager,
        );
    }

    private function serviceWithChatThatNeverPosts(): QuestionService
    {
        $features = $this->createStub(FeatureRepository::class);
        $features->method('countByGameAndType')->willReturn(1);

        return $this->service(
            $this->createStub(RoundMembershipRepository::class),
            $this->askedQuestionsStub(),
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
            $features,
        );
    }

    private function uploadedPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'jetlag-photo-');
        self::assertIsString($path);
        file_put_contents($path, 'not-really-a-jpeg');

        return new UploadedFile($path, 'answer.jpg', 'image/jpeg', null, true);
    }

    /**
     * Reveal and the powerups claim the row before they act, and a bare stub refuses every claim, so
     * without this every test here would exercise the lost-race path instead of its own behaviour.
     *
     * @return AskedQuestionRepository&Stub
     */
    private function askedQuestionsStub(): AskedQuestionRepository
    {
        $stub = $this->createStub(AskedQuestionRepository::class);
        $stub->method('claimUnrevealed')->willReturn(true);
        $stub->method('claimOpen')->willReturn(true);

        return $stub;
    }

    private function hiderPingRepository(Round $round, Player $hider, Point $at): PlayerLocationRepository
    {
        $ping = new PlayerLocation($round, $hider, $at);
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')->willReturn($ping);
        $locations->method('findFreshestHiderLocationByRound')->willReturn($ping);

        return $locations;
    }

    /** Asking is a seeking-phase action, so every question test needs a round past its hiding period. */
    private function seekingRound(Game $game): Round
    {
        return new Round($game)->setStatus(RoundStatus::Seeking);
    }

    private function roundStillHiding(Game $game): Round
    {
        return new Round($game)
            ->setStatus(RoundStatus::Hiding)
            ->setHidingPeriodEndsAt(new \DateTimeImmutable('+20 minutes'));
    }

    private function askAndCaptureErrorKey(Round $round, Player $asker, ?AskQuestionInput $input = null): ?string
    {
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $asker, Side::Seeker));
        $askedQuestions = $this->createMock(AskedQuestionRepository::class);
        $askedQuestions->expects(self::never())->method('save');

        $service = $this->service(
            $memberships,
            $askedQuestions,
            $this->createStub(PlayerLocationRepository::class),
            $this->chatThatNeverPosts(),
        );

        try {
            $service->ask($round, $asker, $input ?? $this->radarInput($asker->getUuid()));
        } catch (FunctionalException $e) {
            return $e->getErrorKey();
        }

        self::fail('Asking should have been rejected.');
    }

    private function radarInput(string $askerUuid): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Radar;
        $input->radiusMeters = 500.0;
        $input->seekerLat = 0.0;
        $input->seekerLng = 0.0;

        return $input;
    }

    private function thermometerInput(string $askerUuid): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Thermometer;
        $input->startLat = 0.0;
        $input->startLng = 0.0;
        $input->distanceMeters = 1000.0;

        return $input;
    }

    private function photoInput(string $askerUuid, ?PhotoTarget $target): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Photos;
        $input->photoTarget = $target;

        return $input;
    }

    private function transitLineInput(string $askerUuid): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Matching;
        $input->transitLineOsmId = '42';
        $input->transitLineOsmType = 'relation';

        return $input;
    }

    private function radarQuestion(Round $round, Player $asker, \DateTimeImmutable $deadline): AskedQuestion
    {
        $question = new AskedQuestion($round, $asker, QuestionCategory::Radar, $deadline);
        $question->setRadiusMeters(500.0)->setSeekerPoint(new Point(0.0, 0.0));

        return $question;
    }

    private function travelingThermometer(Round $round, Player $asker): AskedQuestion
    {
        $question = new AskedQuestion($round, $asker, QuestionCategory::Thermometer, null);
        $question->setStartPoint(new Point(0.0, 0.0))->setDistanceMeters(1000.0);

        return $question;
    }

    private function answeredThermometer(Round $round, Player $asker, float $meters): AskedQuestion
    {
        $question = new AskedQuestion($round, $asker, QuestionCategory::Thermometer, null);
        $question->setStartPoint(new Point(0.0, 0.0))->setDistanceMeters($meters);

        return $question;
    }

    private function possibleAreaThatDoesNothing(): PossibleAreaService
    {
        return $this->createStub(PossibleAreaService::class);
    }

    private function chatThatNeverPosts(): ChatService
    {
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::never())->method('save');

        return $this->chatWith($messages);
    }

    private function chatWith(ChatMessageRepository $messages): ChatService
    {
        return new ChatService(
            $messages,
            new MercureJwtService(self::SECRET),
            new FakeMercureHub(),
            $this->createStub(ImageStorageInterface::class),
        );
    }

    private function imageStorageReturning(string $ref): ImageStorageInterface
    {
        $storage = $this->createStub(ImageStorageInterface::class);
        $storage->method('store')->willReturn($ref);

        return $storage;
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private static function capturedWithBodyKey(array $messages, string $bodyKey): ?ChatMessage
    {
        foreach ($messages as $message) {
            if ($message->getBodyKey() === $bodyKey) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function chatCapturing(array &$messages): ChatService
    {
        $repository = $this->createStub(ChatMessageRepository::class);
        $repository->method('save')->willReturnCallback(
            static function (ChatMessage $message) use (&$messages): void {
                $messages[] = $message;
            },
        );
        $repository->method('findOneByQuestionUuidAndType')->willReturnCallback(
            static function (string $questionUuid, ChatMessageType $type) use (&$messages): ?ChatMessage {
                return self::latestCaptured($messages, $questionUuid, $type);
            },
        );
        $repository->method('findLatestByQuestionUuid')->willReturnCallback(
            static function (string $questionUuid) use (&$messages): ?ChatMessage {
                return self::latestCaptured($messages, $questionUuid, null);
            },
        );

        return $this->chatWith($repository);
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private static function latestCaptured(
        array $messages,
        string $questionUuid,
        ?ChatMessageType $type,
    ): ?ChatMessage {
        foreach (array_reverse($messages) as $message) {
            if ($message->getQuestionUuid() !== $questionUuid) {
                continue;
            }
            if ($type === null || $message->getType() === $type) {
                return $message;
            }
        }

        return null;
    }

    private function featureQuestion(
        Round $round,
        Player $asker,
        QuestionCategory $category,
        FeatureType $type,
    ): AskedQuestion {
        $question = new AskedQuestion($round, $asker, $category, new \DateTimeImmutable('+5 minutes'));
        $question->setFeatureType($type)->setSeekerPoint(new Point(0.0, 0.0));

        return $question;
    }

    private function seaLevelQuestion(Round $round, Player $asker, ?float $seekerAltitude): AskedQuestion
    {
        $question = new AskedQuestion($round, $asker, QuestionCategory::Measuring, new \DateTimeImmutable('+5 minutes'));
        $question->setSeaLevel(true)->setSeekerPoint(new Point(0.0, 0.0))->setSeekerAltitude($seekerAltitude);

        return $question;
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function seaLevelRevealService(
        Round $round,
        Player $hider,
        ?float $hiderAltitude,
        array &$messages,
    ): QuestionService {
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, new Point(0.0, 0.0005), $hiderAltitude));

        return $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
        );
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function featureRevealService(
        Round $round,
        Player $hider,
        FeatureRepository $features,
        array &$messages,
    ): QuestionService {
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn(new RoundMembership($round, $hider, Side::Hider));
        $locations = $this->createStub(PlayerLocationRepository::class);
        $locations->method('findLatestByRoundAndPlayer')
            ->willReturn(new PlayerLocation($round, $hider, new Point(0.0, 0.0005)));

        return $this->service(
            $memberships,
            $this->askedQuestionsStub(),
            $locations,
            $this->chatCapturing($messages),
            $features,
        );
    }
}

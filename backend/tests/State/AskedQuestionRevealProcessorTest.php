<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Entity\AskedQuestion;
use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\AskedQuestionRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\FeatureRepository;
use App\Repository\GameTransitLineRepository;
use App\Repository\GameTransitStationRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Service\ChatService;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\OverpassHttpClient;
use App\Service\OverpassService;
use App\Service\PossibleAreaService;
use App\Service\QuestionMessageFormatter;
use App\Service\QuestionService;
use App\Service\RoundClock;
use App\State\AskedQuestionRevealProcessor;
use App\Storage\ImageStorageInterface;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(AskedQuestionRevealProcessor::class)]
final class AskedQuestionRevealProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    #[Test]
    public function theHiderOfTheRoundRevealsWithTheirToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);
        $question = new AskedQuestion($round, new Player($game, AccountFactory::create('Bob', 'test-password')), QuestionCategory::Radar, null);
        $question->setSeekerPoint(new Point(13.405, 52.52))->setRadiusMeters(500.0);

        $asked = $this->createMock(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn($question);
        $asked->method('claimUnrevealed')->willReturn(true);
        $asked->expects(self::once())->method('save');

        $processor = $this->processor($round, $hider, $membership, $asked);
        $processor->process(null, new Post(), ['questionUuid' => $question->getUuid()]);

        self::assertNotNull($question->getRevealedAt());
        self::assertTrue($question->getRadarAnswer());
    }

    #[Test]
    public function itNeverReadsTheIdentityFromTheBody(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $hider, Side::Hider);
        $question = new AskedQuestion($round, new Player($game, AccountFactory::create('Bob', 'test-password')), QuestionCategory::Radar, null);
        $question->setSeekerPoint(new Point(13.405, 52.52))->setRadiusMeters(500.0);

        $asked = $this->createMock(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn($question);
        $asked->method('claimUnrevealed')->willReturn(true);
        $asked->expects(self::once())->method('save');
        $messages = $this->createMock(ChatMessageRepository::class);
        $messages->expects(self::once())->method('save')->with(self::callback(
            static fn (ChatMessage $message): bool => $message->getSenderUuid() === $hider->getUuid(),
        ));

        $this->processor($round, $hider, $membership, $asked, messages: $messages)
            ->process(null, new Post(), ['questionUuid' => $question->getUuid()]);

        self::assertNotNull($question->getRevealedAt());
    }

    #[Test]
    public function itRejectsASeekerToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $seeker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $question = new AskedQuestion($round, $seeker, QuestionCategory::Radar, null);

        $asked = $this->createMock(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn($question);
        $asked->expects(self::never())->method('save');

        try {
            $this->processor($round, $seeker, new RoundMembership($round, $seeker, Side::Seeker), $asked)
                ->process(null, new Post(), ['questionUuid' => $question->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('question.hider_only', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnAlreadyRevealedQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $question = new AskedQuestion($round, new Player($game, AccountFactory::create('Bob', 'test-password')), QuestionCategory::Radar, null);
        $question->setRevealedAt(new \DateTimeImmutable('-1 minute'));

        $asked = $this->createStub(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn($question);

        try {
            $this->processor($round, $hider, new RoundMembership($round, $hider, Side::Hider), $asked)
                ->process(null, new Post(), ['questionUuid' => $question->getUuid()]);
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('question.already_revealed', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAnUnknownQuestion(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $asked = $this->createStub(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);

        $this->processor($round, $hider, null, $asked)
            ->process(null, new Post(), ['questionUuid' => '00000000-0000-0000-0000-000000000000']);
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $question = new AskedQuestion($round, new Player($game, AccountFactory::create('Bob', 'test-password')), QuestionCategory::Radar, null);

        $asked = $this->createStub(AskedQuestionRepository::class);
        $asked->method('findOneByUuid')->willReturn($question);

        $this->expectException(IdentityRequiredException::class);

        $this->processor($round, null, null, $asked, withHeader: false)
            ->process(null, new Post(), ['questionUuid' => $question->getUuid()]);
    }

    private function processor(
        Round $round,
        ?Player $player,
        ?RoundMembership $membership,
        AskedQuestionRepository $asked,
        bool $withHeader = true,
        ?ChatMessageRepository $messages = null,
    ): AskedQuestionRevealProcessor {
        $mercure = new MercureJwtService(self::SECRET);
        $hub = new FakeMercureHub();
        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $stack = new RequestStack();
        $request = new Request();
        if ($withHeader && $player !== null) {
            $request->headers->set(IdentityResolver::HEADER, $mercure->issueSubscriberToken([], $player->getUuid()));
        }
        $stack->push($request);
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);

        $chat = new ChatService(
            $messages ?? $this->createStub(ChatMessageRepository::class),
            $mercure,
            $hub,
            $this->createStub(ImageStorageInterface::class),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $work): mixed => $work());

        $questionService = new QuestionService(
            $memberships,
            $asked,
            $this->createStub(GameTransitLineRepository::class),
            $this->createStub(GameTransitStationRepository::class),
            $this->createStub(HidingZoneRepository::class),
            $this->locations($round, $player),
            $chat,
            $this->createStub(PossibleAreaService::class),
            $this->createStub(FeatureRepository::class),
            new QuestionMessageFormatter($this->createStub(FeatureRepository::class)),
            new OverpassService(
                new OverpassHttpClient(new MockHttpClient(), 'http://mirror/api', false),
                $this->createStub(FeatureRepository::class),
                $this->createStub(EntityManagerInterface::class),
            ),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ImageStorageInterface::class),
            new RoundClock(),
            $entityManager,
        );

        return new AskedQuestionRevealProcessor(
            $asked,
            $questionService,
            new IdentityResolver($mercure, $players, $stack),
        );
    }

    private function locations(Round $round, ?Player $player): PlayerLocationRepository
    {
        $locations = $this->createStub(PlayerLocationRepository::class);
        $point = new Point(13.405, 52.52);
        $locations->method('findLatestByRoundAndPlayer')->willReturn(
            $player === null ? null : new PlayerLocation($round, $player, $point),
        );

        return $locations;
    }
}

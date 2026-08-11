<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\AskQuestionInput;
use App\Dto\ZonePlacement;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\ChatMessageType;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\RoundStatus;
use App\Enum\Side;
use App\Repository\AskedQuestionRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\HidingZoneRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundRepository;
use App\Service\HidingZoneService;
use App\Service\QuestionService;
use App\Tests\Fake\FailingMercureHub;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The wrapped question and zone flows publish to Mercure inside the transaction, before commit:
 * when the hub fails after the flush, the rows must roll back and the round stays clean for a retry.
 */
final class QuestionRollbackTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AskedQuestionRepository $questions;
    private ChatMessageRepository $messages;
    private HidingZoneRepository $zones;
    private RoundRepository $rounds;
    private PlayerRepository $players;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fetchServices();

        // Fail the hub's first publish: the flush that precedes it must be proven rolled back.
        static::getContainer()->set(FakeMercureHub::class, new FailingMercureHub(1));
    }

    #[Test]
    public function aPublishFailureRollsBackTheAskedQuestionAndItsChatMessage(): void
    {
        [$round, $seeker] = $this->persistSeekingRoundWithSides();
        $input = $this->radarInput();

        $service = self::getContainer()->get(QuestionService::class);
        self::assertInstanceOf(QuestionService::class, $service);

        try {
            $service->ask($round, $seeker, $input);
            self::fail('ask() must fail when the Mercure publish fails.');
        } catch (\RuntimeException $e) {
            self::assertSame('Mercure hub unavailable', $e->getMessage());
        }

        $this->em->clear();

        $reloadedRound = $this->rounds->findOneByUuid($round->getUuid());
        self::assertNotNull($reloadedRound);
        self::assertSame([], $this->questions->findByRound($reloadedRound));
        self::assertSame([], $this->messages->findByGame($reloadedRound->getGame()));

        $this->rebootKernel();
        $service = self::getContainer()->get(QuestionService::class);
        self::assertInstanceOf(QuestionService::class, $service);
        $reloadedRound = $this->rounds->findOneByUuid($round->getUuid());
        $reloadedSeeker = $this->players->findOneByUuid($seeker->getUuid());
        self::assertNotNull($reloadedRound);
        self::assertNotNull($reloadedSeeker);

        $question = $service->ask($reloadedRound, $reloadedSeeker, $input);

        self::assertNotNull($this->questions->findOneByUuid($question->getUuid()));
        self::assertNotNull(
            $this->messages->findOneByQuestionUuidAndType($question->getUuid(), ChatMessageType::Question),
        );
    }

    #[Test]
    public function aPublishFailureRollsBackThePlacedHidingZone(): void
    {
        [$round, , $hider] = $this->persistSeekingRoundWithSides();
        $placement = new ZonePlacement(new Point(13.405, 52.52), 500.0, 'Alexanderplatz');

        $service = self::getContainer()->get(HidingZoneService::class);
        self::assertInstanceOf(HidingZoneService::class, $service);

        try {
            $service->setZone($round->getUuid(), $hider->getUuid(), $placement);
            self::fail('setZone() must fail when the Mercure publish fails.');
        } catch (\RuntimeException $e) {
            self::assertSame('Mercure hub unavailable', $e->getMessage());
        }

        $this->em->clear();

        $reloadedRound = $this->rounds->findOneByUuid($round->getUuid());
        self::assertNotNull($reloadedRound);
        self::assertNull($this->zones->findOneByRound($reloadedRound));

        $this->rebootKernel();
        $service = self::getContainer()->get(HidingZoneService::class);
        self::assertInstanceOf(HidingZoneService::class, $service);

        $zone = $service->setZone($reloadedRound->getUuid(), $hider->getUuid(), $placement);

        self::assertSame($reloadedRound->getUuid(), $zone->getRound()->getUuid());
        self::assertNotNull($this->zones->findOneByRound($reloadedRound));
    }

    /**
     * A failed wrapInTransaction closes the EntityManager; a fresh kernel stands in for the next request.
     */
    private function rebootKernel(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->fetchServices();
    }

    private function fetchServices(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $questions = $container->get(AskedQuestionRepository::class);
        self::assertInstanceOf(AskedQuestionRepository::class, $questions);
        $this->questions = $questions;
        $messages = $container->get(ChatMessageRepository::class);
        self::assertInstanceOf(ChatMessageRepository::class, $messages);
        $this->messages = $messages;
        $zones = $container->get(HidingZoneRepository::class);
        self::assertInstanceOf(HidingZoneRepository::class, $zones);
        $this->zones = $zones;
        $rounds = $container->get(RoundRepository::class);
        self::assertInstanceOf(RoundRepository::class, $rounds);
        $this->rounds = $rounds;
        $players = $container->get(PlayerRepository::class);
        self::assertInstanceOf(PlayerRepository::class, $players);
        $this->players = $players;
    }

    /**
     * @return array{Round, Player, Player}
     */
    private function persistSeekingRoundWithSides(): array
    {
        $game = new Game('Berlin ' . uniqid(), GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $seekerAccount = AccountFactory::create('Seeker ' . uniqid(), 'test-password');
        $hiderAccount = AccountFactory::create('Hider ' . uniqid(), 'test-password');
        $seeker = new Player($game, $seekerAccount);
        $hider = new Player($game, $hiderAccount);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->persist($seekerAccount);
        $this->em->persist($hiderAccount);
        $this->em->persist($seeker);
        $this->em->persist($hider);
        $this->em->persist(new RoundMembership($round, $seeker, Side::Seeker));
        $this->em->persist(new RoundMembership($round, $hider, Side::Hider));
        $this->em->flush();

        return [$round, $seeker, $hider];
    }

    private function radarInput(): AskQuestionInput
    {
        $input = new AskQuestionInput();
        $input->category = QuestionCategory::Radar;
        $input->radiusMeters = 500.0;
        $input->seekerLat = 52.52;
        $input->seekerLng = 13.405;

        return $input;
    }
}

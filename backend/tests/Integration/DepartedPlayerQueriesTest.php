<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AskedQuestion;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\PlayerLocation;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\Side;
use App\Repository\AskedQuestionRepository;
use App\Repository\PlayerLocationRepository;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A player who leaves is marked rather than deleted, so every query that used to rely on the row
 * disappearing has to exclude them explicitly. These are the ones a departed player could hijack.
 */
final class DepartedPlayerQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RoundMembershipRepository $memberships;
    private AskedQuestionRepository $questions;
    private PlayerRepository $players;
    private PlayerLocationRepository $locations;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $memberships = $container->get(RoundMembershipRepository::class);
        $questions = $container->get(AskedQuestionRepository::class);
        $players = $container->get(PlayerRepository::class);
        $locations = $container->get(PlayerLocationRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(RoundMembershipRepository::class, $memberships);
        self::assertInstanceOf(AskedQuestionRepository::class, $questions);
        self::assertInstanceOf(PlayerRepository::class, $players);
        self::assertInstanceOf(PlayerLocationRepository::class, $locations);
        $this->em = $em;
        $this->memberships = $memberships;
        $this->questions = $questions;
        $this->players = $players;
        $this->locations = $locations;
    }

    #[Test]
    public function aDepartedHiderIsNoLongerOneOfTheRoundsHiders(): void
    {
        [$game, $round] = $this->persistGameWithRound();
        $hiderAccount = AccountFactory::create('Hider ' . uniqid(), 'test-password');
        $hider = new Player($game, $hiderAccount);
        $this->em->persist($hiderAccount);
        $this->em->persist($hider);
        $this->em->persist(new RoundMembership($round, $hider, Side::Hider));
        $this->em->flush();

        self::assertCount(1, $this->memberships->findHidersByRound($round));

        $hider->markLeft(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            [],
            $this->memberships->findHidersByRound($this->reload($round)),
            'A departed hider would keep absorbing the endgame notification.',
        );
    }

    #[Test]
    public function theFreshestHiderPingAnswersTheQuestion(): void
    {
        [$round, $stale, $fresh] = $this->persistRoundWithTwoPingingHiders();
        $this->backdatePingsOf($stale);

        $answering = $this->locations->findFreshestHiderLocationByRound($this->reload($round));

        self::assertNotNull($answering);
        self::assertSame(
            $fresh->getUuid(),
            $answering->getPlayer()->getUuid(),
            'A phone that fell asleep would answer from where its owner used to be.',
        );
    }

    #[Test]
    public function hiderPingsInTheSameSecondFallBackToWhicheverLandedLast(): void
    {
        [$round, , $fresh] = $this->persistRoundWithTwoPingingHiders();

        $answering = $this->locations->findFreshestHiderLocationByRound($this->reload($round));

        self::assertNotNull($answering);
        self::assertSame(
            $fresh->getUuid(),
            $answering->getPlayer()->getUuid(),
            'recordedAt is second-precision, so a shared ping interval makes this the routine case.',
        );
    }

    #[Test]
    public function aDepartedHiderNeverAnswersFromWhereTheyWalkedOff(): void
    {
        [$round, $stale, $fresh] = $this->persistRoundWithTwoPingingHiders();
        $this->backdatePingsOf($stale);

        $fresh->markLeft(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $answering = $this->locations->findFreshestHiderLocationByRound($this->reload($round));

        self::assertNotNull($answering);
        self::assertSame($stale->getUuid(), $answering->getPlayer()->getUuid());
    }

    #[Test]
    public function aRoundWhereNoHiderEverPingedHasNothingToAnswerFrom(): void
    {
        [, $round] = $this->persistGameWithRound();
        $hiderAccount = AccountFactory::create('Silent ' . uniqid(), 'test-password');
        $hider = new Player($round->getGame(), $hiderAccount);
        $this->em->persist($hiderAccount);
        $this->em->persist($hider);
        $this->em->persist(new RoundMembership($round, $hider, Side::Hider));
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->locations->findFreshestHiderLocationByRound($this->reload($round)));
    }

    /**
     * Both hiders ping in the same second, so the returned one is decided by the id tie-break until
     * a caller backdates one of them.
     *
     * @return array{Round, Player, Player}
     */
    private function persistRoundWithTwoPingingHiders(): array
    {
        [$game, $round] = $this->persistGameWithRound();
        $staleAccount = AccountFactory::create('Stale ' . uniqid(), 'test-password');
        $freshAccount = AccountFactory::create('Fresh ' . uniqid(), 'test-password');
        $stale = new Player($game, $staleAccount);
        $fresh = new Player($game, $freshAccount);
        $this->em->persist($staleAccount);
        $this->em->persist($freshAccount);
        foreach ([$stale, $fresh] as $index => $hider) {
            $this->em->persist($hider);
            $this->em->persist(new RoundMembership($round, $hider, Side::Hider));
            $this->em->persist(new PlayerLocation($round, $hider, new Point(1.0 + $index, 1.0)));
            $this->em->flush();
        }

        return [$round, $stale, $fresh];
    }

    /**
     * PlayerLocation stamps recordedAt in its constructor and exposes no setter, so a stale ping can
     * only be simulated in SQL.
     */
    private function backdatePingsOf(Player $player): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE player_locations SET recorded_at = recorded_at - INTERVAL '10 minutes' WHERE player_id = :id",
            ['id' => $player->getId()],
        );
    }

    #[Test]
    public function leavingDropsEverySideThePlayerHeld(): void
    {
        [$game, $round] = $this->persistGameWithRound();
        $seekerAccount = AccountFactory::create('Seeker ' . uniqid(), 'test-password');
        $seeker = new Player($game, $seekerAccount);
        $this->em->persist($seekerAccount);
        $this->em->persist($seeker);
        $this->em->persist(new RoundMembership($round, $seeker, Side::Seeker));
        $this->em->flush();

        self::assertNotNull($this->memberships->findOneByRoundAndPlayer($round, $seeker));

        $this->memberships->removeByPlayer($seeker);
        $this->em->clear();

        $reloadedPlayer = $this->players->findOneByUuid($seeker->getUuid());
        self::assertNotNull($reloadedPlayer);
        self::assertNull($this->memberships->findOneByRoundAndPlayer($this->reload($round), $reloadedPlayer));
    }

    #[Test]
    public function aDepartedAskersOpenQuestionStopsBlockingTheRound(): void
    {
        [$game, $round] = $this->persistGameWithRound();
        $askerAccount = AccountFactory::create('Asker ' . uniqid(), 'test-password');
        $asker = new Player($game, $askerAccount);
        $this->em->persist($askerAccount);
        $this->em->persist($asker);
        $this->em->persist(new AskedQuestion($round, $asker, QuestionCategory::Thermometer, null));
        $this->em->flush();

        self::assertNotNull($this->questions->findOutstandingByRound($round));

        $asker->markLeft(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        self::assertNull(
            $this->questions->findOutstandingByRound($this->reload($round)),
            'An unanswerable question from a departed asker would deadlock every later question.',
        );
    }

    private function reload(Round $round): Round
    {
        $reloaded = $this->em->getRepository(Round::class)->find($round->getId());
        self::assertInstanceOf(Round::class, $reloaded);

        return $reloaded;
    }

    /**
     * @return array{Game, Round}
     */
    private function persistGameWithRound(): array
    {
        $game = new Game('Berlin ' . uniqid(), GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->flush();

        return [$game, $round];
    }
}

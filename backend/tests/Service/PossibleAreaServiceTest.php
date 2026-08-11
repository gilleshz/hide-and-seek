<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AskedQuestion;
use App\Entity\Game;
use App\Entity\GameTransitLine;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\ThermometerResult;
use App\Repository\GameTransitLineRepository;
use App\Repository\PossibleAreaConstraintRepository;
use App\Service\MercureJwtService;
use App\Service\PossibleAreaService;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PossibleAreaService::class)]
final class PossibleAreaServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    #[Test]
    public function itCreatesARadarDiskConstraintForAYesAnswer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Alice', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Radar, new \DateTimeImmutable('+5 minutes'));
        $question
            ->setSeekerPoint(new Point(13.405, 52.52))
            ->setRadiusMeters(500.0)
            ->setRadarAnswer(true);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::once())
            ->method('insertDiskConstraint')
            ->with(
                $round,
                self::callback(fn(Point $p): bool => $p->getLongitude() == 13.405 && $p->getLatitude() == 52.52),
                500.0,
                true,
                self::stringContains('Radar'),
            );

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function itCreatesARadarComplementConstraintForANoAnswer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Radar, new \DateTimeImmutable('+5 minutes'));
        $question
            ->setSeekerPoint(new Point(13.405, 52.52))
            ->setRadiusMeters(1000.0)
            ->setRadarAnswer(false);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::once())
            ->method('insertDiskConstraint')
            ->with(
                $round,
                self::callback(fn(Point $p): bool => $p->getLongitude() == 13.405 && $p->getLatitude() == 52.52),
                1000.0,
                false,
                self::stringContains('no'),
            );

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function itCreatesAThermometerHalfPlaneConstraintForHotter(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Imperial);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Charlie', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Thermometer, new \DateTimeImmutable('+5 minutes'));
        $question
            ->setStartPoint(new Point(0.0, 0.0))
            ->setEndPoint(new Point(1.0, 0.0))
            ->setThermometerAnswer(ThermometerResult::Hotter);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::once())
            ->method('insertHalfPlaneConstraint')
            ->with(
                $round,
                self::callback(fn(Point $p): bool => $p->getLongitude() == 0.0 && $p->getLatitude() == 0.0),
                self::callback(fn(Point $p): bool => $p->getLongitude() == 1.0 && $p->getLatitude() == 0.0),
                true,
                self::stringContains('hotter'),
            );

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function itCreatesAThermometerHalfPlaneConstraintForColder(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Imperial);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Diana', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Thermometer, new \DateTimeImmutable('+5 minutes'));
        $question
            ->setStartPoint(new Point(0.0, 0.0))
            ->setEndPoint(new Point(1.0, 0.0))
            ->setThermometerAnswer(ThermometerResult::Colder);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::once())
            ->method('insertHalfPlaneConstraint')
            ->with(
                $round,
                self::callback(fn(Point $p): bool => $p->getLongitude() == 0.0 && $p->getLatitude() == 0.0),
                self::callback(fn(Point $p): bool => $p->getLongitude() == 1.0 && $p->getLatitude() == 0.0),
                false,
                self::stringContains('colder'),
            );

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function itAddsNoConstraintForATransitLineMatching(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Frank', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Matching, new \DateTimeImmutable('+5 minutes'));
        $question->setTransitLineUuid('line-uuid')->setTransitLineLabel('S1: Airport Line')->setMatchingAnswer(true);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::never())->method('insertMatchingConstraint');
        $constraints->expects(self::never())->method('insertMatchingArealConstraint');

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function itAddsAStationNameLengthConstraintAndNotAnIdentityMatchingOne(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Frank', 'test-password'));

        $question = new AskedQuestion($round, $hider, QuestionCategory::Matching, new \DateTimeImmutable('+5 minutes'));
        $question->setFeatureType(FeatureType::TransitStation)
            ->setSeekerPoint(new Point(13.405, 52.52))
            ->setStationNameLength(true)
            ->setMatchingAnswer(true);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::never())->method('insertMatchingConstraint');
        $constraints->expects(self::never())->method('insertMatchingArealConstraint');
        $constraints->expects(self::once())
            ->method('insertStationNameLengthConstraint')
            ->with(
                $round,
                FeatureType::TransitStation,
                self::callback(fn(Point $p): bool => $p->getLongitude() == 13.405 && $p->getLatitude() == 52.52),
                true,
                self::stringContains('Station name length'),
                'constraint.station_name_length',
                ['result' => 'same'],
            );

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    #[Test]
    public function computeCurrentReturnsNullWhenNoConstraintsExist(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);

        $constraints = $this->createStub(PossibleAreaConstraintRepository::class);
        $constraints->method('computePossibleArea')->willReturn(null);

        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());

        self::assertNull($service->computeCurrent($round));
    }

    #[Test]
    public function itCanComputePossibleAreaForBothCategories(): void
    {
        $constraints = $this->createStub(PossibleAreaConstraintRepository::class);
        $service = new PossibleAreaService($constraints, $this->transitLineStub(), new MercureJwtService(self::SECRET), new FakeMercureHub());

        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Eve', 'test-password'));

        $radarQuestion = new AskedQuestion($round, $player, QuestionCategory::Radar, new \DateTimeImmutable('+5 minutes'));
        $radarQuestion
            ->setSeekerPoint(new Point(0.0, 0.0))
            ->setRadiusMeters(500.0)
            ->setRadarAnswer(true);
        $service->computeAfterReveal($radarQuestion, new Point(0.0, 0.0));

        $thermometerQuestion = new AskedQuestion($round, $player, QuestionCategory::Thermometer, new \DateTimeImmutable('+5 minutes'));
        $thermometerQuestion
            ->setStartPoint(new Point(0.0, 0.0))
            ->setEndPoint(new Point(1.0, 0.0))
            ->setThermometerAnswer(ThermometerResult::Hotter);
        $service->computeAfterReveal($thermometerQuestion, new Point(0.0, 0.0));

        self::assertNull($service->computeCurrent($round));
    }

    #[Test]
    public function itAddsATransitLineConstraintFromTheRiddenLineRef(): void
    {
        $game = new Game('Berlin', GameSize::Large, Edition::Metric);
        $round = new Round($game);
        $hider = new Player($game, AccountFactory::create('Grace', 'test-password'));
        $line = new GameTransitLine($game, 'relation', 42, 'S1', 'Airport Line', 'subway', 'BVG', null, null);

        $question = new AskedQuestion($round, $hider, QuestionCategory::Matching, new \DateTimeImmutable('+5 minutes'));
        $question->setTransitLineUuid($line->getUuid())
            ->setTransitLineLabel('S1: Airport Line')
            ->setMatchingAnswer(true);

        $constraints = $this->createMock(PossibleAreaConstraintRepository::class);
        $constraints->expects(self::never())->method('insertMatchingConstraint');
        $constraints->expects(self::never())->method('insertStationNameLengthConstraint');
        $constraints->expects(self::once())
            ->method('insertTransitLineConstraint')
            ->with($round, 'S1', true, self::stringContains('Transit line'), 'constraint.transit_line', ['result' => 'serves']);

        $transitLines = $this->createStub(GameTransitLineRepository::class);
        $transitLines->method('findOneByGameAndUuid')->willReturn($line);

        $service = new PossibleAreaService($constraints, $transitLines, new MercureJwtService(self::SECRET), new FakeMercureHub());
        $service->computeAfterReveal($question, new Point(0.0, 0.0));
    }

    private function transitLineStub(): GameTransitLineRepository
    {
        return $this->createStub(GameTransitLineRepository::class);
    }
}

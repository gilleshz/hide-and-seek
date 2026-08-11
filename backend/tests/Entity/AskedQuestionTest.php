<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AskedQuestion;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\ThermometerResult;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AskedQuestion::class)]
final class AskedQuestionTest extends TestCase
{
    #[Test]
    public function itStoresRadarInputsAndAnswer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $deadline = new \DateTimeImmutable('+5 minutes');

        $question = new AskedQuestion($round, $asker, QuestionCategory::Radar, $deadline);
        $question
            ->setRadiusMeters(500.0)
            ->setSeekerPoint(new Point(13.405, 52.52))
            ->setRadarAnswer(true)
            ->setRevealedAt($deadline);

        self::assertSame($round, $question->getRound());
        self::assertSame($asker, $question->getAskerPlayer());
        self::assertSame(QuestionCategory::Radar, $question->getCategory());
        self::assertSame(36, \strlen($question->getUuid()));
        self::assertSame(500.0, $question->getRadiusMeters());

        $seekerPoint = $question->getSeekerPoint();
        self::assertNotNull($seekerPoint);
        self::assertSame(13.405, $seekerPoint->getLongitude());
        self::assertSame(52.52, $seekerPoint->getLatitude());
        self::assertTrue($question->getRadarAnswer());
        self::assertSame($deadline, $question->getRevealDeadlineAt());
        self::assertSame($deadline, $question->getRevealedAt());
    }

    #[Test]
    public function itStoresThermometerInputsAndAnswer(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $question = new AskedQuestion($round, $asker, QuestionCategory::Thermometer, new \DateTimeImmutable());
        $question
            ->setStartPoint(new Point(13.38, 52.51))
            ->setEndPoint(new Point(13.41, 52.53))
            ->setDistanceMeters(1000.0)
            ->setThermometerAnswer(ThermometerResult::Hotter);

        $startPoint = $question->getStartPoint();
        $endPoint = $question->getEndPoint();
        self::assertNotNull($startPoint);
        self::assertNotNull($endPoint);
        self::assertSame(13.38, $startPoint->getLongitude());
        self::assertSame(13.41, $endPoint->getLongitude());
        self::assertSame(1000.0, $question->getDistanceMeters());
        self::assertSame(ThermometerResult::Hotter, $question->getThermometerAnswer());
        self::assertNull($question->getRevealedAt());
    }

    #[Test]
    public function itStoresTheSeaLevelFlagAndSeekerAltitude(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $question = new AskedQuestion($round, $asker, QuestionCategory::Measuring, new \DateTimeImmutable());

        self::assertFalse($question->isSeaLevel());
        self::assertNull($question->getSeekerAltitude());

        $question->setSeaLevel(true)->setSeekerAltitude(-8.0);

        self::assertTrue($question->isSeaLevel());
        self::assertSame(-8.0, $question->getSeekerAltitude());
    }
}

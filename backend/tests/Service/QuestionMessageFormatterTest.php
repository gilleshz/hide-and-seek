<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\MessagePayload;
use App\Entity\AskedQuestion;
use App\Entity\Feature;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\FeatureType;
use App\Enum\GameSize;
use App\Enum\MeasuringResult;
use App\Enum\PhotoTarget;
use App\Enum\QuestionCategory;
use App\Enum\ThermometerResult;
use App\Repository\FeatureRepository;
use App\Service\QuestionMessageFormatter;
use App\Tests\Support\AccountFactory;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionMessageFormatter::class)]
final class QuestionMessageFormatterTest extends TestCase
{
    /**
     * @return array<string, array{float, Edition, string}>
     */
    public static function distanceCases(): array
    {
        return [
            'metric below a kilometer' => [500.0, Edition::Metric, '500 m'],
            'metric rounded meters' => [999.4, Edition::Metric, '999 m'],
            'metric whole kilometers' => [1000.0, Edition::Metric, '1 km'],
            'metric trimmed decimal kilometers' => [2500.0, Edition::Metric, '2.5 km'],
            'metric large kilometers' => [15000.0, Edition::Metric, '15 km'],
            'metric two decimal kilometers' => [1234.0, Edition::Metric, '1.23 km'],
            'imperial quarter mile' => [402.336, Edition::Imperial, '0.25 mi'],
            'imperial half mile' => [804.672, Edition::Imperial, '0.5 mi'],
            'imperial whole mile' => [1609.344, Edition::Imperial, '1 mi'],
            'imperial fifty miles' => [80467.2, Edition::Imperial, '50 mi'],
        ];
    }

    #[DataProvider('distanceCases')]
    #[Test]
    public function itFormatsDistancesPerEdition(float $meters, Edition $edition, string $expected): void
    {
        self::assertSame($expected, $this->formatter()->formatDistance($meters, $edition));
    }

    /**
     * @return array<string, array{float, Edition, MessagePayload}>
     */
    public static function radarCases(): array
    {
        return [
            'metric' => [
                500.0,
                Edition::Metric,
                self::payload('question.radar.ask', 'Are you within 500 m of me?', ['distance' => '500 m']),
            ],
            'imperial' => [
                804.672,
                Edition::Imperial,
                self::payload('question.radar.ask', 'Are you within 0.5 mi of me?', ['distance' => '0.5 mi']),
            ],
        ];
    }

    #[DataProvider('radarCases')]
    #[Test]
    public function itPhrasesARadarAsk(float $radiusMeters, Edition $edition, MessagePayload $expected): void
    {
        $question = self::question(QuestionCategory::Radar, $edition);
        $question->setRadiusMeters($radiusMeters)->setSeekerPoint(new Point(0.0, 0.0));

        self::assertEquals($expected, $this->formatter()->askBody($question));
    }

    #[Test]
    public function itNamesTheSeekersNearestFeatureInAMatchingAsk(): void
    {
        $question = self::featureQuestion(QuestionCategory::Matching, FeatureType::Museum, Edition::Metric);
        $museum = new Feature(
            $question->getRound()->getGame(),
            FeatureType::Museum,
            'City Museum',
            new Point(0.0, 0.001),
        );

        self::assertEquals(
            self::payload(
                'question.matching.ask_with_name.museum',
                'My nearest museum is City Museum. Is your nearest museum the same as mine?',
                ['name' => 'City Museum'],
            ),
            $this->formatter($museum)->askBody($question),
        );
    }

    #[Test]
    public function itPhrasesAStationNameLengthMatchingAskWithACharacterCount(): void
    {
        $question = self::featureQuestion(QuestionCategory::Matching, FeatureType::TransitStation, Edition::Metric);
        $question->setStationNameLength(true);
        $station = new Feature(
            $question->getRound()->getGame(),
            FeatureType::TransitStation,
            'Alexanderplatz',
            new Point(0.0, 0.001),
        );

        self::assertEquals(
            self::payload(
                'question.matching.ask_station_name_length',
                'My nearest station is Alexanderplatz (14 characters). '
                    . "Is your nearest station's name the same length?",
                ['name' => 'Alexanderplatz', 'count' => '14'],
            ),
            $this->formatter($station)->askBody($question),
        );
    }

    #[Test]
    public function itFallsBackToANamelessMatchingAskWhenNoFeatureIsFound(): void
    {
        $question = self::featureQuestion(QuestionCategory::Matching, FeatureType::Museum, Edition::Metric);

        self::assertEquals(
            self::payload(
                'question.matching.ask_no_name.museum',
                'Is your nearest museum the same as mine?',
                [],
            ),
            $this->formatter()->askBody($question),
        );
    }

    #[Test]
    public function itFallsBackToANamelessMatchingAskWhenTheNearestFeatureHasNoName(): void
    {
        $question = self::featureQuestion(QuestionCategory::Matching, FeatureType::Coastline, Edition::Metric);
        $coastline = new Feature($question->getRound()->getGame(), FeatureType::Coastline, null, new Point(0.0, 0.001));

        self::assertEquals(
            self::payload(
                'question.matching.ask_no_name.coastline',
                'Is your nearest coastline the same as mine?',
                [],
            ),
            $this->formatter($coastline)->askBody($question),
        );
    }

    #[Test]
    public function itEmbedsTheSeekersDistanceAndFeatureNameInAMeasuringAsk(): void
    {
        $question = self::featureQuestion(QuestionCategory::Measuring, FeatureType::MovieTheater, Edition::Metric);
        $theater = new Feature(
            $question->getRound()->getGame(),
            FeatureType::MovieTheater,
            'UGC Cine Cite',
            new Point(0.0, 0.001),
        );

        self::assertEquals(
            self::payload(
                'question.measuring.ask_with_name.movie_theater',
                "I'm 120 m from my nearest movie theater (UGC Cine Cite). "
                    . 'Are you closer to or further from your nearest movie theater?',
                ['distance' => '120 m', 'name' => 'UGC Cine Cite'],
            ),
            $this->formatter($theater, 120.0)->askBody($question),
        );
    }

    #[Test]
    public function itFormatsTheMeasuringDistanceInMilesForImperialGames(): void
    {
        $question = self::featureQuestion(QuestionCategory::Measuring, FeatureType::MovieTheater, Edition::Imperial);
        $theater = new Feature(
            $question->getRound()->getGame(),
            FeatureType::MovieTheater,
            'Odeon',
            new Point(0.0, 0.02),
        );

        self::assertEquals(
            self::payload(
                'question.measuring.ask_with_name.movie_theater',
                "I'm 1 mi from my nearest movie theater (Odeon). "
                    . 'Are you closer to or further from your nearest movie theater?',
                ['distance' => '1 mi', 'name' => 'Odeon'],
            ),
            $this->formatter($theater, 1609.344)->askBody($question),
        );
    }

    #[Test]
    public function itOmitsTheFeatureNameInAMeasuringAskWhenTheFeatureIsNameless(): void
    {
        $question = self::featureQuestion(QuestionCategory::Measuring, FeatureType::Coastline, Edition::Metric);
        $coastline = new Feature($question->getRound()->getGame(), FeatureType::Coastline, null, new Point(0.0, 0.001));

        self::assertEquals(
            self::payload(
                'question.measuring.ask_with_feature_no_name.coastline',
                "I'm 120 m from my nearest coastline. Are you closer to or further from your nearest coastline?",
                ['distance' => '120 m'],
            ),
            $this->formatter($coastline, 120.0)->askBody($question),
        );
    }

    #[Test]
    public function itFallsBackToANamelessMeasuringAskWhenNoFeatureIsFound(): void
    {
        $question = self::featureQuestion(QuestionCategory::Measuring, FeatureType::MovieTheater, Edition::Metric);

        self::assertEquals(
            self::payload(
                'question.measuring.ask_no_name.movie_theater',
                'Are you closer to or further from your nearest movie theater than I am to mine?',
                [],
            ),
            $this->formatter()->askBody($question),
        );
    }

    #[Test]
    public function itPhrasesATentaclesAskWithTheRangeStatedTwice(): void
    {
        $question = self::featureQuestion(QuestionCategory::Tentacles, FeatureType::Museum, Edition::Metric);
        $question->setWithinMeters(2000.0);

        self::assertEquals(
            self::payload(
                'question.tentacles.ask.museum',
                'Within 2 km of me, which museum are you nearest to? (You must also be within 2 km.)',
                ['range' => '2 km'],
            ),
            $this->formatter()->askBody($question),
        );
    }

    /**
     * @return array<string, array{PhotoTarget, Edition, MessagePayload}>
     */
    public static function photoCases(): array
    {
        return [
            'tree' => [
                PhotoTarget::Tree,
                Edition::Metric,
                self::payload('question.photos.ask.tree', 'Send me a photo of a tree.'),
            ],
            'selfie' => [
                PhotoTarget::Selfie,
                Edition::Metric,
                self::payload('question.photos.ask.selfie', 'Send me a photo of yourself.'),
            ],
            'widest street' => [
                PhotoTarget::WidestStreet,
                Edition::Metric,
                self::payload(
                    'question.photos.ask.widest_street',
                    'Send me a photo of the widest street near you.',
                ),
            ],
            'streets traced metric' => [
                PhotoTarget::StreetsTraced,
                Edition::Metric,
                self::payload(
                    'question.photos.ask.streets_traced_metric',
                    'Send me a photo of 1 km of streets, traced.'
                    . ' It must be continuous, with at least 5 turns and no doubling back.',
                ),
            ],
            'streets traced imperial' => [
                PhotoTarget::StreetsTraced,
                Edition::Imperial,
                self::payload(
                    'question.photos.ask.streets_traced_imperial',
                    'Send me a photo of half a mile of streets, traced.'
                    . ' It must be continuous, with at least 5 turns and no doubling back.',
                ),
            ],
            'trace nearest street' => [
                PhotoTarget::TraceNearestStreet,
                Edition::Metric,
                self::payload(
                    'question.photos.ask.trace_nearest_street',
                    'Send me a photo of your nearest street or path, traced.'
                    . ' Trace it from intersection to intersection.',
                ),
            ],
        ];
    }

    #[DataProvider('photoCases')]
    #[Test]
    public function itPhrasesAPhotoAskFromTheTargetLabel(
        PhotoTarget $target,
        Edition $edition,
        MessagePayload $expected,
    ): void {
        $question = self::question(QuestionCategory::Photos, $edition);
        $question->setPhotoTarget($target);

        self::assertEquals($expected, $this->formatter()->askBody($question));
    }

    #[Test]
    public function askBodyRejectsThermometerQuestions(): void
    {
        $this->expectException(\LogicException::class);

        $this->formatter()->askBody(self::question(QuestionCategory::Thermometer, Edition::Metric));
    }

    /**
     * @return array<string, array{float, Edition, MessagePayload}>
     */
    public static function thermometerStartCases(): array
    {
        return [
            'metric' => [
                1000.0,
                Edition::Metric,
                self::payload(
                    'question.thermometer.start',
                    "I'm starting a 1 km thermometer...",
                    ['distance' => '1 km'],
                ),
            ],
            'imperial' => [
                804.672,
                Edition::Imperial,
                self::payload(
                    'question.thermometer.start',
                    "I'm starting a 0.5 mi thermometer...",
                    ['distance' => '0.5 mi'],
                ),
            ],
        ];
    }

    #[DataProvider('thermometerStartCases')]
    #[Test]
    public function itPhrasesAThermometerStart(float $distanceMeters, Edition $edition, MessagePayload $expected): void
    {
        $question = self::question(QuestionCategory::Thermometer, $edition);
        $question->setStartPoint(new Point(0.0, 0.0))->setDistanceMeters($distanceMeters);

        self::assertEquals($expected, $this->formatter()->thermometerStartBody($question));
    }

    /**
     * @return array<string, array{float, Edition, MessagePayload}>
     */
    public static function thermometerCompleteCases(): array
    {
        return [
            'metric traveled about a kilometer' => [
                0.009,
                Edition::Metric,
                self::payload(
                    'question.thermometer.complete',
                    "I've just traveled 1 km. Am I hotter or colder now?",
                    ['distance' => '1 km'],
                ),
            ],
            'metric traveled below a kilometer' => [
                0.005,
                Edition::Metric,
                self::payload(
                    'question.thermometer.complete',
                    "I've just traveled 556 m. Am I hotter or colder now?",
                    ['distance' => '556 m'],
                ),
            ],
            'imperial traveled about a mile' => [
                0.0145,
                Edition::Imperial,
                self::payload(
                    'question.thermometer.complete',
                    "I've just traveled 1 mi. Am I hotter or colder now?",
                    ['distance' => '1 mi'],
                ),
            ],
        ];
    }

    #[DataProvider('thermometerCompleteCases')]
    #[Test]
    public function itPhrasesAThermometerCompleteWithTheTraveledDistance(
        float $endLatitude,
        Edition $edition,
        MessagePayload $expected,
    ): void {
        $question = self::question(QuestionCategory::Thermometer, $edition);
        $question->setStartPoint(new Point(0.0, 0.0))->setEndPoint(new Point(0.0, $endLatitude));

        self::assertEquals($expected, $this->formatter()->thermometerCompleteBody($question));
    }

    /**
     * @return array<string, array{QuestionCategory, callable(AskedQuestion): AskedQuestion, MessagePayload}>
     */
    public static function answerCases(): array
    {
        return [
            'radar hit' => [
                QuestionCategory::Radar,
                static fn(AskedQuestion $q): AskedQuestion => $q->setRadarAnswer(true),
                self::payload('question.answer.yes_within_range', 'Yes, within range'),
            ],
            'radar miss' => [
                QuestionCategory::Radar,
                static fn(AskedQuestion $q): AskedQuestion => $q->setRadarAnswer(false),
                self::payload('question.answer.no_not_within_range', 'No, not within range'),
            ],
            'radar unanswered' => [
                QuestionCategory::Radar,
                static fn(AskedQuestion $q): AskedQuestion => $q,
                self::payload('question.answer.no_answer', 'No answer available'),
            ],
            'thermometer hotter' => [
                QuestionCategory::Thermometer,
                static fn(AskedQuestion $q): AskedQuestion => $q->setThermometerAnswer(ThermometerResult::Hotter),
                self::payload('question.answer.hotter', 'Hotter'),
            ],
            'thermometer colder' => [
                QuestionCategory::Thermometer,
                static fn(AskedQuestion $q): AskedQuestion => $q->setThermometerAnswer(ThermometerResult::Colder),
                self::payload('question.answer.colder', 'Colder'),
            ],
            'matching same' => [
                QuestionCategory::Matching,
                static fn(AskedQuestion $q): AskedQuestion => $q->setMatchingAnswer(true),
                self::payload('question.answer.same', 'Same'),
            ],
            'matching different' => [
                QuestionCategory::Matching,
                static fn(AskedQuestion $q): AskedQuestion => $q->setMatchingAnswer(false),
                self::payload('question.answer.different', 'Different'),
            ],
            'measuring closer' => [
                QuestionCategory::Measuring,
                static fn(AskedQuestion $q): AskedQuestion => $q->setMeasuringAnswer(MeasuringResult::Closer),
                self::payload('question.answer.closer', 'Closer'),
            ],
            'measuring further' => [
                QuestionCategory::Measuring,
                static fn(AskedQuestion $q): AskedQuestion => $q->setMeasuringAnswer(MeasuringResult::Further),
                self::payload('question.answer.further', 'Further'),
            ],
            'tentacles named feature' => [
                QuestionCategory::Tentacles,
                static fn(AskedQuestion $q): AskedQuestion => $q->setTentaclesAnswer('City Museum'),
                self::payload(
                    'question.answer.tentacles_answer',
                    'City Museum',
                    ['answer' => 'City Museum'],
                ),
            ],
            'tentacles not within reach' => [
                QuestionCategory::Tentacles,
                static function (AskedQuestion $q): AskedQuestion {
                    return $q->setTentaclesAnswer(AskedQuestion::TENTACLES_NOT_WITHIN_REACH);
                },
                self::payload(
                    'question.answer.tentacles_answer',
                    'Not within reach',
                    ['answer' => 'Not within reach'],
                ),
            ],
            'tentacles no answer available' => [
                QuestionCategory::Tentacles,
                static fn(AskedQuestion $q): AskedQuestion => $q,
                self::payload('question.answer.no_answer', 'No answer available'),
            ],
            'photos' => [
                QuestionCategory::Photos,
                static fn(AskedQuestion $q): AskedQuestion => $q,
                self::payload('question.answer.photo_in_chat', 'Photo will be posted in chat.'),
            ],
        ];
    }

    /**
     * @param callable(AskedQuestion): AskedQuestion $configure
     */
    #[DataProvider('answerCases')]
    #[Test]
    public function itPhrasesAnswersNaturally(
        QuestionCategory $category,
        callable $configure,
        MessagePayload $expected,
    ): void {
        $question = self::question($category, Edition::Metric);
        $configure($question);

        self::assertEquals($expected, $this->formatter()->answerBody($question));
    }

    private function formatter(?Feature $nearest = null, float $distanceMeters = 0.0): QuestionMessageFormatter
    {
        $features = $this->createStub(FeatureRepository::class);
        $features->method('findNearestWithin')->willReturn($nearest === null ? [] : [$nearest]);
        $features->method('distanceToFeature')->willReturn($distanceMeters);

        return new QuestionMessageFormatter($features);
    }

    /**
     * @param array<string, string> $bodyArgs
     */
    private static function payload(string $bodyKey, string $body, array $bodyArgs = []): MessagePayload
    {
        return new MessagePayload(bodyKey: $bodyKey, body: $body, bodyArgs: $bodyArgs);
    }

    private static function question(QuestionCategory $category, Edition $edition): AskedQuestion
    {
        $game = new Game('Berlin', GameSize::Medium, $edition);
        $round = new Round($game);
        $asker = new Player($game, AccountFactory::create('Bob', 'test-password'));

        return new AskedQuestion($round, $asker, $category, new \DateTimeImmutable('+5 minutes'));
    }

    private static function featureQuestion(
        QuestionCategory $category,
        FeatureType $type,
        Edition $edition,
    ): AskedQuestion {
        $question = self::question($category, $edition);
        $question->setFeatureType($type)->setSeekerPoint(new Point(0.0, 0.0));

        return $question;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\AskedQuestion;
use App\Entity\Feature;
use App\Entity\Game;
use App\Entity\GameTransitLine;
use App\Entity\GameTransitStation;
use App\Enum\FeatureType;
use App\Enum\RoundStatus;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use LongitudeOne\Spatial\PHP\Types\Geography\Point;
use PHPUnit\Framework\Attributes\Test;

final class AskedQuestionApiTest extends ApiTestCase
{
    #[Test]
    public function aSeekerCanAskAndAHiderCanRevealARadarQuestion(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [$seekerUuid, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        [$hiderUuid, $hiderToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $this->startSeeking($roundUuid);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hiderToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame($roundUuid, $asked['roundUuid']);
        self::assertSame('radar', $asked['category']);
        self::assertNull($asked['revealedAt']);
        self::assertNull($asked['radarAnswer']);
        self::assertNotNull($asked['revealDeadlineAt']);
        self::assertEqualsWithDelta(500.0, $asked['radiusMeters'], 0.001);
        self::assertSame(52.52, $asked['seekerLat']);
        self::assertSame(13.405, $asked['seekerLng']);
        self::assertNull($asked['distanceMeters']);
        self::assertNull($asked['startLat']);
        self::assertNull($asked['endLat']);
        self::assertNull($asked['photoTarget']);
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        $askMessage = $this->questionMessage($client, $gameUuid, 'question', $questionUuid);
        self::assertSame($seekerUuid, $askMessage['senderUuid']);
        self::assertSame('Are you within 500 m of me?', $askMessage['body']);

        $unrevealed = $client->request('GET', "/api/questions/{$questionUuid}", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNull($unrevealed['revealedAt']);
        self::assertNull($unrevealed['radarAnswer']);

        $revealed = $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertNotNull($revealed['revealedAt']);
        self::assertTrue($revealed['radarAnswer']);

        $answerMessage = $this->questionMessage($client, $gameUuid, 'answer', $questionUuid);
        self::assertSame($hiderUuid, $answerMessage['senderUuid']);
        self::assertSame('Yes, within range', $answerMessage['body']);
        self::assertSame($askMessage['uuid'], $answerMessage['replyToUuid']);

        $refetched = $client->request('GET', "/api/questions/{$questionUuid}", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotNull($refetched['revealedAt']);
        self::assertTrue($refetched['radarAnswer']);
    }

    #[Test]
    public function itListsQuestionsForARoundWithAnswersHiddenUntilRevealed(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [$seekerUuid, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        [$hiderUuid, $hiderToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $this->startSeeking($roundUuid);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hiderToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ]);

        $listing = $client->request('GET', "/api/rounds/{$roundUuid}/questions", self::AUTH)->toArray();

        self::assertResponseIsSuccessful();
        $questions = $listing['member'];
        self::assertIsArray($questions);
        self::assertCount(1, $questions);
        self::assertIsArray($questions[0]);
        self::assertSame('radar', $questions[0]['category']);
        self::assertNull($questions[0]['revealedAt']);
        self::assertNull($questions[0]['radarAnswer']);
    }

    #[Test]
    public function itRejectsASecondQuestionWhileOneIsStillOutstanding(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $this->startSeeking($roundUuid);

        $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ]);

        $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function itRejectsAQuestionWhileTheHidingPeriodIsStillRunning(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $client->request('POST', "/api/rounds/{$roundUuid}/start", $this->headersWithToken($seekerToken) + ['json' => []]);

        $rejected = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ])->toArray(false);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('question.hiding_period', $rejected['errorKey']);

        $listing = $client->request('GET', "/api/rounds/{$roundUuid}/questions", self::AUTH)->toArray();
        self::assertIsArray($listing['member']);
        self::assertCount(0, $listing['member']);
    }

    #[Test]
    public function aThermometerRunsAsATwoStepFlowWithChatMessages(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [$seekerUuid, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        [$hiderUuid, $hiderToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $this->startSeeking($roundUuid);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hiderToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'thermometer',
                'distanceMeters' => 1000.0,
                'startLat' => 52.52,
                'startLng' => 13.405,
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertNull($asked['revealDeadlineAt']);
        self::assertNull($asked['endLat']);
        self::assertSame(52.52, $asked['startLat']);
        self::assertSame(13.405, $asked['startLng']);
        self::assertEqualsWithDelta(1000.0, $asked['distanceMeters'], 0.001);
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        $startMessage = $this->questionMessage($client, $gameUuid, 'question_info', $questionUuid);
        self::assertSame($seekerUuid, $startMessage['senderUuid']);
        self::assertSame("I'm starting a 1 km thermometer...", $startMessage['body']);

        $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hiderToken));
        self::assertResponseStatusCodeSame(400);

        $completed = $client->request('POST', "/api/questions/{$questionUuid}/complete", $this->headersWithToken($seekerToken) + [
            'json' => ['endLat' => 52.529, 'endLng' => 13.405],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertNotNull($completed['revealDeadlineAt']);
        self::assertSame(52.529, $completed['endLat']);
        self::assertSame(13.405, $completed['endLng']);

        $completeMessage = $this->questionMessage($client, $gameUuid, 'question', $questionUuid);
        self::assertSame($seekerUuid, $completeMessage['senderUuid']);
        self::assertSame("I've just traveled 1 km. Am I hotter or colder now?", $completeMessage['body']);

        $revealed = $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('colder', $revealed['thermometerAnswer']);

        $answerMessage = $this->questionMessage($client, $gameUuid, 'answer', $questionUuid);
        self::assertSame($hiderUuid, $answerMessage['senderUuid']);
        self::assertSame('Colder', $answerMessage['body']);
        self::assertSame($completeMessage['uuid'], $answerMessage['replyToUuid']);
    }

    #[Test]
    public function aPhotoQuestionRequiresATargetAndPostsItsAskToChat(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [$seekerUuid, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $this->startSeeking($roundUuid);

        $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => ['category' => 'photos'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => ['category' => 'photos', 'photoTarget' => 'tree'],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('tree', $asked['photoTarget']);
        self::assertNotNull($asked['revealDeadlineAt']);
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        $askMessage = $this->questionMessage($client, $gameUuid, 'question', $questionUuid);
        self::assertSame($seekerUuid, $askMessage['senderUuid']);
        self::assertSame('Send me a photo of a tree.', $askMessage['body']);
    }

    #[Test]
    public function cancellingAQuestionPostsANoticeAndDeletesTheQuestion(): void
    {
        $client = static::createClient();

        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        $roundUuid = $game['roundUuid'];
        self::assertIsString($game['uuid']);
        $gameUuid = $game['uuid'];

        [, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        $this->startSeeking($roundUuid);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'radar',
                'radiusMeters' => 500.0,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ])->toArray();
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];
        $askMessage = $this->questionMessage($client, $gameUuid, 'question', $questionUuid);

        $client->request('POST', "/api/questions/{$questionUuid}/cancel", $this->headersWithToken($seekerToken));
        self::assertResponseIsSuccessful();

        $notice = $this->questionMessage($client, $gameUuid, 'system', $questionUuid);
        self::assertSame('Question cancelled.', $notice['body']);
        self::assertNull($notice['senderUuid'] ?? null);
        self::assertSame($askMessage['uuid'], $notice['replyToUuid']);

        $client->request('GET', "/api/questions/{$questionUuid}", self::AUTH);
        self::assertResponseStatusCodeSame(404);

        $listing = $client->request('GET', "/api/rounds/{$roundUuid}/questions", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame([], $listing['member']);
    }

    #[Test]
    public function aTransitLineMatchingAutoRevealsFromTheHidersNearestStation(): void
    {
        $client = static::createClient();
        [$roundUuid, $gameUuid, $seekerUuid, $seekerToken, $hiderUuid, $hiderToken] = $this->setUpGameWithSides($client);
        $this->seedTransitLine($gameUuid, 4242, 'S1', 'Airport Line');
        $this->seedTransitStation($gameUuid, 'Airport', 13.405, 52.52, ['S1']);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hiderToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405],
        ]);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'matching',
                'transitLineOsmId' => '4242',
                'transitLineOsmType' => 'relation',
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('matching', $asked['category']);
        self::assertSame('S1: Airport Line', $asked['transitLineLabel']);
        self::assertNull($asked['matchingAnswer']);
        self::assertNull($asked['featureType']);
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        $revealed = $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertTrue($revealed['matchingAnswer']);
        self::assertNotNull($revealed['revealedAt']);

        $answerMessage = $this->questionMessage($client, $gameUuid, 'answer', $questionUuid);
        self::assertSame($hiderUuid, $answerMessage['senderUuid']);
        self::assertSame('Yes, S1: Airport Line stops at my station.', $answerMessage['body']);
    }

    #[Test]
    public function aSeekerCanAskAStationNameLengthMatchingWhichStoresTheFlag(): void
    {
        $client = static::createClient();
        [$roundUuid, $gameUuid, , $seekerToken] = $this->setUpGameWithSides($client);
        $this->seedStation($gameUuid, 'Alexanderplatz', 13.411, 52.521);

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'matching',
                'stationNameLength' => true,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('matching', $asked['category']);
        self::assertNull($asked['matchingAnswer']);
        self::assertIsString($asked['uuid']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $stored = $em->getRepository(AskedQuestion::class)->findOneBy(['uuid' => $asked['uuid']]);
        self::assertInstanceOf(AskedQuestion::class, $stored);
        self::assertTrue($stored->isStationNameLength());
        self::assertSame(FeatureType::TransitStation, $stored->getFeatureType());
    }

    #[Test]
    public function aSeekerCanAskAndAHiderCanRevealASeaLevelMeasuringQuestion(): void
    {
        $client = static::createClient();
        [$roundUuid, $gameUuid, $seekerUuid, $seekerToken, $hiderUuid, $hiderToken] = $this->setUpGameWithSides($client);

        $client->request('POST', "/api/rounds/{$roundUuid}/location", $this->headersWithToken($hiderToken) + [
            'json' => ['lat' => 52.52, 'lng' => 13.405, 'altitude' => 10.0],
        ]);
        self::assertResponseIsSuccessful();

        $asked = $client->request('POST', "/api/rounds/{$roundUuid}/questions", $this->headersWithToken($seekerToken) + [
            'json' => [
                'category' => 'measuring',
                'seaLevel' => true,
                'seekerLat' => 52.52,
                'seekerLng' => 13.405,
                'seekerAltitude' => 100.0,
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('measuring', $asked['category']);
        self::assertTrue($asked['seaLevel']);
        self::assertNull($asked['featureType']);
        self::assertNull($asked['measuringAnswer']);
        self::assertIsString($asked['uuid']);
        $questionUuid = $asked['uuid'];

        $askMessage = $this->questionMessage($client, $gameUuid, 'question', $questionUuid);
        self::assertSame('Are you closer to or further from sea level than I am?', $askMessage['body']);

        $revealed = $client->request('POST', "/api/questions/{$questionUuid}/reveal", $this->headersWithToken($hiderToken))->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('closer', $revealed['measuringAnswer']);
        self::assertNotNull($revealed['revealedAt']);

        $answerMessage = $this->questionMessage($client, $gameUuid, 'answer', $questionUuid);
        self::assertSame($hiderUuid, $answerMessage['senderUuid']);
        self::assertSame('Closer to sea level', $answerMessage['body']);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function setUpGameWithSides(Client $client): array
    {
        $game = $client->request('POST', '/api/games', self::AUTH + [
            'json' => ['name' => 'Berlin', 'size' => 'M', 'edition' => 'metric'],
        ])->toArray();
        self::assertIsString($game['roundUuid']);
        self::assertIsString($game['uuid']);
        $roundUuid = $game['roundUuid'];
        $gameUuid = $game['uuid'];

        [$seekerUuid, $seekerToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Bob', 'seeker');
        [$hiderUuid, $hiderToken] = $this->joinAndPickSide($client, $gameUuid, $roundUuid, 'Alice', 'hider');
        $this->startSeeking($roundUuid);

        return [$roundUuid, $gameUuid, $seekerUuid, $seekerToken, $hiderUuid, $hiderToken];
    }

    /** Asking is a seeking-phase action, so the round has to be past its hiding period. */
    private function startSeeking(string $roundUuid): void
    {
        /** @var RoundRepository $rounds */
        $rounds = self::getContainer()->get(RoundRepository::class);
        $round = $rounds->findOneByUuid($roundUuid);
        self::assertNotNull($round);
        $round->setStatus(RoundStatus::Seeking)->setHidingPeriodEndsAt(new \DateTimeImmutable('-1 minute'));
        $rounds->save($round);
    }

    private function seedTransitLine(string $gameUuid, int $osmId, string $ref, string $name): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $game = $em->getRepository(Game::class)->findOneBy(['uuid' => $gameUuid]);
        self::assertInstanceOf(Game::class, $game);

        $em->persist(new GameTransitLine($game, 'relation', $osmId, $ref, $name, 'subway', 'BVG', null, null));
        $em->flush();
    }

    private function seedStation(string $gameUuid, string $name, float $lng, float $lat): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $game = $em->getRepository(Game::class)->findOneBy(['uuid' => $gameUuid]);
        self::assertInstanceOf(Game::class, $game);

        $game->setBoundary(52.4, 13.3, 52.6, 13.5);
        $em->persist(new Feature($game, FeatureType::TransitStation, $name, new Point($lng, $lat)));
        $em->flush();
    }

    /** @param list<string> $lineRefs */
    private function seedTransitStation(string $gameUuid, string $name, float $lng, float $lat, array $lineRefs): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $game = $em->getRepository(Game::class)->findOneBy(['uuid' => $gameUuid]);
        self::assertInstanceOf(Game::class, $game);

        $game->setBoundary(52.4, 13.3, 52.6, 13.5);
        $em->persist(new GameTransitStation($game, 'node/1', $name, new Point($lng, $lat), $lineRefs));
        $em->flush();
    }

    /**
     * @return array<mixed>
     */
    private function questionMessage(Client $client, string $gameUuid, string $type, string $questionUuid): array
    {
        $chat = $client->request('GET', "/api/games/{$gameUuid}/chat", self::AUTH)->toArray();
        self::assertResponseIsSuccessful();
        self::assertIsArray($chat['member']);
        foreach ($chat['member'] as $message) {
            self::assertIsArray($message);
            if (($message['type'] ?? null) === $type && ($message['questionUuid'] ?? null) === $questionUuid) {
                return $message;
            }
        }

        self::fail(sprintf('No chat message of type %s for question %s.', $type, $questionUuid));
    }
}

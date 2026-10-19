<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Service\MercureJwtService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MercureJwtService::class)]
final class MercureJwtServiceTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';
    private const string PUBLIC_URL = 'https://jetlag.test';

    #[Test]
    public function itIssuesAThreePartJwt(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-1');

        self::assertSame(2, substr_count($token, '.'));
    }

    #[Test]
    public function issuedTokenIsAnAccessTokenForTheHolderWithSubscribeGrants(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-1');
        $header = $this->decodePart($token, 0);
        $payload = $this->decodePart($token, 1);

        self::assertSame('at+jwt', $header['typ'] ?? null);
        self::assertSame('player-1', $payload['sub'] ?? null);
        self::assertSame(self::PUBLIC_URL, $payload['iss'] ?? null);
        self::assertSame(self::PUBLIC_URL . '/.well-known/mercure', $payload['aud'] ?? null);
        self::assertSame('jetlag-companion', $payload['client_id'] ?? null);
        self::assertIsNumeric($payload['exp'] ?? null);

        $expiresIn = (int) $payload['exp'] - time();
        // 12h TTL, not the historical 24h; a little slack for the seconds the test takes.
        self::assertGreaterThan(12 * 3600 - 60, $expiresIn);
        self::assertLessThan(12 * 3600 + 60, $expiresIn);

        $details = $payload['authorization_details'] ?? null;
        self::assertIsArray($details);
        $grant = $details[0] ?? null;
        self::assertIsArray($grant);
        self::assertSame('https://mercure.rocks/authorization-detail', $grant['type'] ?? null);
        self::assertSame(['subscribe'], $grant['actions'] ?? null);
        self::assertSame([['match' => 'game/x/roster']], $grant['topics'] ?? null);
    }

    #[Test]
    public function baselineTopicsAreRoundScopedToTheGameAndRoundUuids(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $topics = $service->baselineTopics($game, $round);

        self::assertContains('game/' . $game->getUuid() . '/roster', $topics);
        self::assertContains('game/' . $game->getUuid() . '/chat', $topics);
        self::assertContains('game/' . $game->getUuid() . '/timer', $topics);
        self::assertContains(
            'game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/time-traps',
            $topics,
        );
        // Default SETUP-8 flag shares the possible-area overlay with hiders too.
        self::assertContains(
            'game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/possible-area',
            $topics,
        );
    }

    #[Test]
    public function baselineTopicsKeepThePossibleAreaFromHidersWhenTheGameOptsOut(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $game->setPossibleAreaSharedWithHiders(false);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $topics = $service->baselineTopics($game, $round, Side::Hider);

        self::assertNotContains(
            'game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/possible-area',
            $topics,
        );
    }

    #[Test]
    public function playerEndgameTopicIsScopedToThePlayerUuid(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $topic = $service->playerEndgameTopic('abc-123');

        self::assertSame('player/abc-123/endgame', $topic);
    }

    #[Test]
    public function seekerTopicsExcludeHiderLocationsAndOtherPlayersEndgame(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $topics = $service->locationTopics($game, $round, Side::Seeker);

        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-locations', $topics);
        self::assertNotContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/hider-locations', $topics);
    }

    #[Test]
    public function hiderTopicsIncludeHiderLocations(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $topics = $service->locationTopics($game, $round, Side::Hider);

        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-locations', $topics);
        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/hider-locations', $topics);
    }

    #[Test]
    public function issuedTokenNormalizesATrailingSlashInThePublicUrl(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL . '/');

        $payload = $this->decodePart($service->issueSubscriberToken(['game/x/roster'], 'player-1'), 1);

        self::assertSame(self::PUBLIC_URL, $payload['iss'] ?? null);
        self::assertSame(self::PUBLIC_URL . '/.well-known/mercure', $payload['aud'] ?? null);
    }

    #[Test]
    public function subscriberPlayerUuidReadsTheSubClaim(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-42');

        self::assertSame('player-42', $service->subscriberPlayerUuid($token));
    }

    #[Test]
    public function subscriberClaimsReadsTheTokenEpoch(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-42', 7);

        self::assertSame(['playerUuid' => 'player-42', 'tokenEpoch' => 7], $service->subscriberClaims($token));
    }

    #[Test]
    public function subscriberPlayerUuidRejectsForeignSignedAndMalformedTokens(): void
    {
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $foreign = \Lcobucci\JWT\Configuration::forSymmetricSigner(
            new \Lcobucci\JWT\Signer\Hmac\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText('a-different-secret-that-is-also-long-enough!'),
        );
        $now = new \DateTimeImmutable();
        $foreignToken = $foreign->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->relatedTo('attacker-1')
            ->getToken($foreign->signer(), $foreign->signingKey())
            ->toString();

        self::assertNull($service->subscriberPlayerUuid($foreignToken));
        self::assertNull($service->subscriberPlayerUuid('a.b.c'));
        self::assertNull($service->subscriberPlayerUuid('eyJhbGciOiJIUzI1NiJ9.payload.'));
    }

    #[Test]
    public function seekerOnlyTopicsCoverCandidatesAndTheZoneRadiusAndExcludeHiders(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET, self::PUBLIC_URL);

        $seekerTopics = $service->seekerOnlyTopics($game, $round, Side::Seeker);
        $hiderTopics = $service->seekerOnlyTopics($game, $round, Side::Hider);

        self::assertContains(
            'game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-candidates',
            $seekerTopics,
        );
        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-zone', $seekerTopics);
        self::assertSame([], $hiderTopics);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePart(string $token, int $index): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $encoded = strtr($parts[$index], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        self::assertIsString($decoded);
        $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}

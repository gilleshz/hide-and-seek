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

    #[Test]
    public function itIssuesAThreePartJwt(): void
    {
        $service = new MercureJwtService(self::SECRET);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-1');

        self::assertSame(2, substr_count($token, '.'));
    }

    #[Test]
    public function issuedTokenExpiresInTwelveHoursAndNamesTheHolderInTheSubClaim(): void
    {
        $service = new MercureJwtService(self::SECRET);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-1');
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $decoded = base64_decode($parts[1], true);
        self::assertIsString($decoded);
        $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('exp', $payload);
        self::assertIsNumeric($payload['exp']);
        self::assertSame('player-1', $payload['sub'] ?? null);

        $expiresIn = (int) $payload['exp'] - time();
        // 12h TTL, not the historical 24h; a little slack for the seconds the test takes.
        self::assertGreaterThan(12 * 3600 - 60, $expiresIn);
        self::assertLessThan(12 * 3600 + 60, $expiresIn);
    }

    #[Test]
    public function baselineTopicsAreRoundScopedToTheGameAndRoundUuids(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET);

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
        $service = new MercureJwtService(self::SECRET);

        $topics = $service->baselineTopics($game, $round, Side::Hider);

        self::assertNotContains(
            'game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/possible-area',
            $topics,
        );
    }

    #[Test]
    public function playerEndgameTopicIsScopedToThePlayerUuid(): void
    {
        $service = new MercureJwtService(self::SECRET);

        $topic = $service->playerEndgameTopic('abc-123');

        self::assertSame('player/abc-123/endgame', $topic);
    }

    #[Test]
    public function seekerTopicsExcludeHiderLocationsAndOtherPlayersEndgame(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET);

        $topics = $service->locationTopics($game, $round, Side::Seeker);

        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-locations', $topics);
        self::assertNotContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/hider-locations', $topics);
    }

    #[Test]
    public function hiderTopicsIncludeHiderLocations(): void
    {
        $game = new Game('Berlin', GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $service = new MercureJwtService(self::SECRET);

        $topics = $service->locationTopics($game, $round, Side::Hider);

        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/seeker-locations', $topics);
        self::assertContains('game/' . $game->getUuid() . '/round/' . $round->getUuid() . '/hider-locations', $topics);
    }

    #[Test]
    public function subscriberPlayerUuidReadsTheSubClaim(): void
    {
        $service = new MercureJwtService(self::SECRET);

        $token = $service->issueSubscriberToken(['game/x/roster'], 'player-42');

        self::assertSame('player-42', $service->subscriberPlayerUuid($token));
    }

    #[Test]
    public function subscriberPlayerUuidFallsBackToTheEndgameTopicForLegacyTokens(): void
    {
        $service = new MercureJwtService(self::SECRET);

        // Minted without a sub claim, exactly like tokens from before the claim existed.
        $legacy = $service->issueSubscriberToken([$service->playerEndgameTopic('legacy-1')], 'legacy-1');
        $parsed = $this->decodePayload($legacy);
        unset($parsed['sub']);
        $rebuilt = $this->rebuildToken($legacy, $parsed);

        self::assertSame('legacy-1', $service->subscriberPlayerUuid($rebuilt));
        self::assertNull($service->subscriberPlayerUuid(''));
        self::assertNull($service->subscriberPlayerUuid('not-a-jwt'));
    }

    #[Test]
    public function subscriberPlayerUuidRejectsForeignSignedAndMalformedTokens(): void
    {
        $service = new MercureJwtService(self::SECRET);

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
        $service = new MercureJwtService(self::SECRET);

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
    private function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $decoded = base64_decode($parts[1], true);
        self::assertIsString($decoded);
        $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Re-signs a legacy-shaped payload (no sub claim) with the service's own key.
     *
     * @param array<string, mixed> $claims
     */
    private function rebuildToken(string $original, array $claims): string
    {
        $configuration = \Lcobucci\JWT\Configuration::forSymmetricSigner(
            new \Lcobucci\JWT\Signer\Hmac\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText(self::SECRET),
        );
        $mercure = $claims['mercure'] ?? [];
        $subscribe = is_array($mercure) ? ($mercure['subscribe'] ?? []) : [];
        $now = new \DateTimeImmutable();
        $token = $configuration->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+12 hours'))
            ->withClaim('mercure', ['subscribe' => is_array($subscribe) ? $subscribe : []])
            ->getToken($configuration->signer(), $configuration->signingKey());

        return $token->toString();
    }
}

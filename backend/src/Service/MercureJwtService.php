<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\Side;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\ProtocolVersion;

final readonly class MercureJwtService
{
    private LcobucciFactory $factory;

    private Configuration $configuration;

    private string $publicUrl;

    /**
     * 12h instead of the historical 24h: long enough to cover a full game day, short enough that a
     * leaked token is useless the next morning (round-scoped topics + the refresh endpoint shrink it further).
     */
    private const int TOKEN_TTL_SECONDS = 43_200;

    private const string CLIENT_ID = 'jetlag-companion';

    public function __construct(
        #[Autowire('%env(MERCURE_SUBSCRIBER_JWT_KEY)%')] string $secret,
        #[Autowire('%env(PUBLIC_URL)%')] string $publicUrl,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('MERCURE_SUBSCRIBER_JWT_KEY must not be empty.');
        }

        if ($publicUrl === '') {
            throw new \InvalidArgumentException('PUBLIC_URL must not be empty.');
        }

        $this->publicUrl = rtrim($publicUrl, '/');
        $this->factory = new LcobucciFactory($secret, protocolVersion: ProtocolVersion::V1);
        $this->configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

    private function parse(string $token): ?UnencryptedToken
    {
        if ($token === '') {
            return null;
        }

        try {
            $parsed = $this->configuration->parser()->parse($token);
        } catch (\Throwable) {
            return null;
        }

        $signed = $parsed instanceof UnencryptedToken && $this->configuration->validator()->validate(
            $parsed,
            new SignedWith($this->configuration->signer(), $this->configuration->verificationKey()),
        );

        return $signed && !$parsed->isExpired(new \DateTimeImmutable()) ? $parsed : null;
    }

    /**
     * @param list<string> $topics
     */
    public function issueSubscriberToken(array $topics, string $playerUuid, int $tokenEpoch = 1): string
    {
        return $this->factory->create(
            [new Grant(actions: [Grant::ACTION_SUBSCRIBE], topics: $topics)],
            [
                'iss' => $this->publicUrl,
                'aud' => $this->publicUrl . '/.well-known/mercure',
                'sub' => $playerUuid,
                'token_epoch' => $tokenEpoch,
                'client_id' => self::CLIENT_ID,
                'exp' => new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function baselineTopics(Game $game, Round $round, ?Side $side = null): array
    {
        $topics = [
            $this->rosterTopic($game),
            $this->chatTopic($game),
            $this->timerTopic($game),
            $this->timeTrapTopic($game, $round),
        ];

        // The possible-area overlay is shared with hiders unless the host opted out (SETUP-8).
        if ($side === null || $side === Side::Seeker || $game->isPossibleAreaSharedWithHiders()) {
            $topics[] = $this->possibleAreaTopic($game, $round);
        }

        return $topics;
    }

    /** A trap's station is public per the card, so both sides share one topic. */
    public function timeTrapTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/time-traps";
    }

    public function rosterTopic(Game $game): string
    {
        return "game/{$game->getUuid()}/roster";
    }

    public function chatTopic(Game $game): string
    {
        return "game/{$game->getUuid()}/chat";
    }

    public function timerTopic(Game $game): string
    {
        return "game/{$game->getUuid()}/timer";
    }

    /**
     * Seekers' locations are visible to everyone (hiders need them to evade);
     * hider-locations is only added for the hiding side.
     *
     * @return list<string>
     */
    public function locationTopics(Game $game, Round $round, Side $side): array
    {
        $topics = [$this->seekerLocationsTopic($game, $round)];

        if ($side === Side::Hider) {
            $topics[] = $this->hiderLocationsTopic($game, $round);
        }

        return $topics;
    }

    /**
     * Candidate markers and the zone radius are seeker-only aids; the hider gets the whole zone
     * on its own private topic, so sending these to both sides would just duplicate it.
     *
     * @return list<string>
     */
    public function seekerOnlyTopics(Game $game, Round $round, Side $side): array
    {
        return $side === Side::Seeker
            ? [$this->seekerCandidatesTopic($game, $round), $this->seekerZoneTopic($game, $round)]
            : [];
    }

    public function seekerCandidatesTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-candidates";
    }

    public function seekerZoneTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-zone";
    }

    public function seekerLocationsTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/seeker-locations";
    }

    public function hiderLocationsTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/hider-locations";
    }

    public function playerEndgameTopic(string $playerUuid): string
    {
        return "player/{$playerUuid}/endgame";
    }

    /**
     * @return array{playerUuid: string, tokenEpoch: ?int}|null
     */
    public function subscriberClaims(string $token): ?array
    {
        $parsed = $this->parse($token);
        if ($parsed === null) {
            return null;
        }

        $sub = $parsed->claims()->get('sub');
        if (!is_string($sub) || $sub === '') {
            return null;
        }

        $epoch = $parsed->claims()->get('token_epoch');

        return ['playerUuid' => $sub, 'tokenEpoch' => is_int($epoch) ? $epoch : null];
    }

    public function subscriberPlayerUuid(string $token): ?string
    {
        return $this->subscriberClaims($token)['playerUuid'] ?? null;
    }

    public function possibleAreaTopic(Game $game, Round $round): string
    {
        return "game/{$game->getUuid()}/round/{$round->getUuid()}/possible-area";
    }
}

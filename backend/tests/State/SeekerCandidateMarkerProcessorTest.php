<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\ValidatorInterface;
use App\Dto\SeekerCandidateMarkerInput;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Entity\RoundMembership;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\Side;
use App\Exception\EntityNotFoundException;
use App\Exception\FunctionalException;
use App\Exception\IdentityRequiredException;
use App\Repository\PlayerRepository;
use App\Repository\RoundMembershipRepository;
use App\Repository\RoundRepository;
use App\Repository\SeekerCandidateMarkerRepository;
use App\Service\IdentityResolver;
use App\Service\MercureJwtService;
use App\Service\SeekerCandidateMarkerService;
use App\State\SeekerCandidateMarkerProcessor;
use App\Tests\Fake\FakeMercureHub;
use App\Tests\Support\AccountFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(SeekerCandidateMarkerProcessor::class)]
final class SeekerCandidateMarkerProcessorTest extends TestCase
{
    private const string SECRET = 'test-mercure-secret-at-least-32-bytes-long!';

    private function input(): SeekerCandidateMarkerInput
    {
        $input = new SeekerCandidateMarkerInput();
        $input->lat = 52.52;
        $input->lng = 13.405;

        return $input;
    }

    #[Test]
    public function itLetsASeekerPlaceAMarker(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Alice', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Seeker);

        $markerRepo = $this->createMock(SeekerCandidateMarkerRepository::class);
        $markerRepo->expects(self::once())->method('save');
        $processor = $this->processor($round, $player, $membership, $markerRepo);

        $resource = $processor->process(
            $this->input(),
            new Post(),
            ['roundUuid' => $round->getUuid()],
        );

        self::assertSame($player->getUuid(), $resource->playerUuid);
        self::assertSame(52.52, $resource->lat);
        self::assertSame(13.405, $resource->lng);
    }

    #[Test]
    public function itRejectsAHider(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));
        $membership = new RoundMembership($round, $player, Side::Hider);

        $markerRepo = $this->createMock(SeekerCandidateMarkerRepository::class);
        $markerRepo->expects(self::never())->method('save');
        $processor = $this->processor($round, $player, $membership, $markerRepo);

        try {
            $processor->process(
                $this->input(),
                new Post(),
                ['roundUuid' => $round->getUuid()],
            );
            self::fail('Expected a FunctionalException.');
        } catch (FunctionalException $e) {
            self::assertSame('seeker_candidate.not_seeker', $e->getErrorKey());
        }
    }

    #[Test]
    public function itRejectsAPlayerWithNoMembership(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $markerRepo = $this->createMock(SeekerCandidateMarkerRepository::class);
        $markerRepo->expects(self::never())->method('save');
        $processor = $this->processor($round, $player, null, $markerRepo);

        $this->expectException(FunctionalException::class);

        $processor->process(
            $this->input(),
            new Post(),
            ['roundUuid' => $round->getUuid()],
        );
    }

    #[Test]
    public function itRejectsAnAbsentToken(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $markerRepo = $this->createMock(SeekerCandidateMarkerRepository::class);
        $markerRepo->expects(self::never())->method('save');
        $processor = $this->processor($round, $player, null, $markerRepo, withHeader: false);

        $this->expectException(IdentityRequiredException::class);

        $processor->process(
            $this->input(),
            new Post(),
            ['roundUuid' => $round->getUuid()],
        );
    }

    #[Test]
    public function itRejectsAnUnknownRound(): void
    {
        $game = new Game('Berlin', GameSize::Medium, Edition::Metric);
        $round = new Round($game);
        $player = new Player($game, AccountFactory::create('Bob', 'test-password'));

        $rounds = $this->createStub(RoundRepository::class);
        $rounds->method('findOneByUuid')->willReturn(null);

        $markerRepo = $this->createMock(SeekerCandidateMarkerRepository::class);
        $markerRepo->expects(self::never())->method('save');
        $processor = $this->processor($round, $player, null, $markerRepo, rounds: $rounds);

        $this->expectException(EntityNotFoundException::class);

        $processor->process(
            $this->input(),
            new Post(),
            ['roundUuid' => $round->getUuid()],
        );
    }

    private function processor(
        Round $round,
        Player $player,
        ?RoundMembership $membership,
        SeekerCandidateMarkerRepository $markerRepo,
        bool $withHeader = true,
        ?RoundRepository $rounds = null,
    ): SeekerCandidateMarkerProcessor {
        $validator = $this->createStub(ValidatorInterface::class);
        $defaultRounds = $this->createStub(RoundRepository::class);
        $defaultRounds->method('findOneByUuid')->willReturn($round);
        $resolvedRounds = $rounds ?? $defaultRounds;

        // IdentityResolver is final, so the real thing runs over a staged request header.
        $players = $this->createStub(PlayerRepository::class);
        $players->method('findOneByUuidIncludingLeft')->willReturn($player);
        $mercure = new MercureJwtService(self::SECRET);
        $request = new Request();
        if ($withHeader) {
            $request->headers->set(
                IdentityResolver::HEADER,
                $mercure->issueSubscriberToken([$mercure->playerEndgameTopic($player->getUuid())], $player->getUuid()),
            );
        }
        $stack = new RequestStack();
        $stack->push($request);
        $identity = new IdentityResolver($mercure, $players, $stack);

        $memberships = $this->createStub(RoundMembershipRepository::class);
        $memberships->method('findOneByRoundAndPlayer')->willReturn($membership);

        $service = new SeekerCandidateMarkerService(
            $markerRepo,
            new MercureJwtService(self::SECRET),
            new FakeMercureHub(),
        );

        return new SeekerCandidateMarkerProcessor($validator, $resolvedRounds, $memberships, $service, $identity);
    }
}

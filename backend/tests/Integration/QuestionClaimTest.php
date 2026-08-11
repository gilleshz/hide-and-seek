<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AskedQuestion;
use App\Entity\Game;
use App\Entity\Player;
use App\Entity\Round;
use App\Enum\Edition;
use App\Enum\GameSize;
use App\Enum\QuestionCategory;
use App\Enum\QuestionStatus;
use App\Repository\AskedQuestionRepository;
use App\Tests\Support\AccountFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Hiding is a team side, so two hiders can play a powerup on the same question at the same moment.
 * The compare-and-set is what decides between them, so it has to be exercised against a real row.
 */
final class QuestionClaimTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AskedQuestionRepository $questions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $questions = $container->get(AskedQuestionRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(AskedQuestionRepository::class, $questions);
        $this->em = $em;
        $this->questions = $questions;
    }

    #[Test]
    public function onlyTheFirstClaimOnAnOpenQuestionSucceeds(): void
    {
        $question = $this->persistOpenQuestion();

        self::assertTrue($this->questions->claimOpen($question, QuestionStatus::Randomized));
        self::assertFalse(
            $this->questions->claimOpen($question, QuestionStatus::Vetoed),
            'A second hider must not be able to move the question again.',
        );
    }

    #[Test]
    public function theClaimWritesTheTransitionToTheRow(): void
    {
        $question = $this->persistOpenQuestion();
        $uuid = $question->getUuid();

        $this->questions->claimOpen($question, QuestionStatus::Vetoed);
        $this->em->clear();

        $reloaded = $this->questions->findOneByUuid($uuid);
        self::assertNotNull($reloaded);
        self::assertSame(QuestionStatus::Vetoed, $reloaded->getStatus());
    }

    #[Test]
    public function anAlreadyRevealedQuestionCannotBeClaimed(): void
    {
        $question = $this->persistOpenQuestion();
        $question->setRevealedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertFalse($this->questions->claimOpen($question, QuestionStatus::Randomized));
    }

    #[Test]
    public function onlyOneAnswerCanBeClaimedForAQuestion(): void
    {
        $question = $this->persistOpenQuestion();
        $at = new \DateTimeImmutable();

        self::assertTrue($this->questions->claimUnrevealed($question, $at));
        self::assertFalse(
            $this->questions->claimUnrevealed($question, $at),
            'A second claim would post a second answer to the same question.',
        );
    }

    #[Test]
    public function theAnswerClaimWritesRevealedAtToTheRow(): void
    {
        $question = $this->persistOpenQuestion();
        $uuid = $question->getUuid();
        $at = new \DateTimeImmutable('2026-07-27 12:00:00');

        $this->questions->claimUnrevealed($question, $at);
        $this->em->clear();

        $reloaded = $this->questions->findOneByUuid($uuid);
        self::assertNotNull($reloaded);
        self::assertSame($at->getTimestamp(), $reloaded->getRevealedAt()?->getTimestamp());
    }

    #[Test]
    public function aVetoedQuestionCannotHaveAnAnswerClaimed(): void
    {
        $question = $this->persistOpenQuestion();
        $this->questions->claimOpen($question, QuestionStatus::Vetoed);

        self::assertFalse(
            $this->questions->claimUnrevealed($question, new \DateTimeImmutable()),
            'Vetoing means no answer is ever posted, so an in-flight reveal must not slip through.',
        );
    }

    private function persistOpenQuestion(): AskedQuestion
    {
        $game = new Game('Berlin ' . uniqid(), GameSize::Small, Edition::Metric);
        $round = new Round($game);
        $askerAccount = AccountFactory::create('Seeker ' . uniqid(), 'test-password');
        $asker = new Player($game, $askerAccount);
        $question = new AskedQuestion($round, $asker, QuestionCategory::Radar, new \DateTimeImmutable('+5 minutes'));
        $this->em->persist($game);
        $this->em->persist($round);
        $this->em->persist($askerAccount);
        $this->em->persist($asker);
        $this->em->persist($question);
        $this->em->flush();

        return $question;
    }
}

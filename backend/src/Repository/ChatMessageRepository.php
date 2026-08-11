<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatMessage;
use App\Entity\Game;
use App\Enum\ChatMessageType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return list<ChatMessage>
     */
    public function findByGame(Game $game): array
    {
        // created_at is second-precision: the id tie-breaks messages posted within the same second.
        return $this->findBy(['game' => $game], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findLatestByQuestionUuid(string $questionUuid): ?ChatMessage
    {
        return $this->findOneBy(['questionUuid' => $questionUuid], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneByQuestionUuidAndType(string $questionUuid, ChatMessageType $type): ?ChatMessage
    {
        return $this->findOneBy(
            ['questionUuid' => $questionUuid, 'type' => $type],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function findOneByUuid(string $uuid): ?ChatMessage
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(ChatMessage $message, bool $flush = true): void
    {
        $this->getEntityManager()->persist($message);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

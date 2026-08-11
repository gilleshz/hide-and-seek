<?php

declare(strict_types=1);

namespace App\Repository;

use App\DateFormat;
use App\Entity\ChatMessage;
use App\Entity\ChatMessageRead;
use App\Entity\Game;
use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessageRead>
 */
class ChatMessageReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessageRead::class);
    }

    /**
     * Raw SQL because DQL cannot INSERT; ON CONFLICT makes repeated or concurrent
     * reports a no-op instead of a unique violation, at a cost independent of how
     * far behind the reader was. Own and system messages are skipped: nobody asks
     * who read those.
     *
     * @return int the number of messages newly marked, so callers can skip a pointless broadcast
     */
    public function markReadUpTo(
        Game $game,
        Player $reader,
        \DateTimeImmutable $cursor,
        \DateTimeImmutable $readAt,
    ): int {
        $sql = <<<'SQL'
            INSERT INTO chat_message_reads (message_id, player_id, read_at)
            SELECT m.id, :playerId, :readAt
            FROM chat_messages m
            WHERE m.game_id = :gameId
              AND m.created_at <= :cursor
              AND m.sender_id IS NOT NULL
              AND m.sender_id <> :playerId
            ON CONFLICT (message_id, player_id) DO NOTHING
        SQL;

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, [
            'playerId' => $reader->getId(),
            'gameId' => $game->getId(),
            'cursor' => $cursor->format(DateFormat::DB_DATETIME),
            'readAt' => $readAt->format(DateFormat::DB_DATETIME),
        ]);
    }

    /**
     * Newest message each player has read, as a watermark; clients derive
     * per-message ticks from it, so history payloads stay free of receipt data.
     *
     * @return list<array{playerUuid: string, playerName: string, readUpTo: \DateTimeImmutable}>
     */
    public function findCursorsByGame(Game $game): array
    {
        /** @var list<array{playerUuid: mixed, playerName: mixed, readUpTo: mixed}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('p.uuid AS playerUuid', 'acct.name AS playerName', 'MAX(m.createdAt) AS readUpTo')
            ->innerJoin('r.message', 'm')
            ->innerJoin('r.reader', 'p')
            ->innerJoin('p.account', 'acct')
            ->where('m.game = :game')
            ->andWhere('p.leftAt IS NULL')
            ->groupBy('p.uuid')
            ->addGroupBy('acct.name')
            ->orderBy('acct.name', 'ASC')
            ->setParameter('game', $game)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): array => [
                'playerUuid' => is_string($row['playerUuid']) ? $row['playerUuid'] : '',
                'playerName' => is_string($row['playerName']) ? $row['playerName'] : '',
                'readUpTo' => self::toDateTime($row['readUpTo']),
            ],
            $rows,
        );
    }

    public function findCursorForPlayer(Game $game, Player $reader): ?\DateTimeImmutable
    {
        $readUpTo = $this->createQueryBuilder('r')
            ->select('MAX(m.createdAt)')
            ->innerJoin('r.message', 'm')
            ->where('m.game = :game')
            ->andWhere('r.reader = :reader')
            ->setParameter('game', $game)
            ->setParameter('reader', $reader)
            ->getQuery()
            ->getSingleScalarResult();

        return $readUpTo === null ? null : self::toDateTime($readUpTo);
    }

    /**
     * @return list<array{playerUuid: string, playerName: string, readAt: \DateTimeImmutable}>
     */
    public function findReadersByMessage(ChatMessage $message): array
    {
        /** @var list<array{playerUuid: mixed, playerName: mixed, readAt: mixed}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('p.uuid AS playerUuid', 'acct.name AS playerName', 'r.readAt AS readAt')
            ->innerJoin('r.reader', 'p')
            ->innerJoin('p.account', 'acct')
            ->where('r.message = :message')
            ->andWhere('p.leftAt IS NULL')
            ->orderBy('r.readAt', 'ASC')
            ->addOrderBy('acct.name', 'ASC')
            ->setParameter('message', $message)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): array => [
                'playerUuid' => is_string($row['playerUuid']) ? $row['playerUuid'] : '',
                'playerName' => is_string($row['playerName']) ? $row['playerName'] : '',
                'readAt' => self::toDateTime($row['readAt']),
            ],
            $rows,
        );
    }

    /** Aggregates like MAX() lose their DQL type, so a timestamp may arrive hydrated or as a raw string. */
    private static function toDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable(is_string($value) ? $value : 'now');
    }
}

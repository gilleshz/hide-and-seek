<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurgedGame;
use App\Entity\Game;
use App\Repository\GameGtfsLineRepository;
use App\Repository\GtfsSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class GameCleanupService
{
    public function __construct(
        private GameGtfsLineRepository $gameGtfsLines,
        private GtfsSourceRepository $gtfsSources,
        private GtfsService $gtfsService,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        #[Autowire('%env(CHAT_IMAGE_DIR)%')]
        private string $chatImageDir,
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
    ) {
    }

    /**
     * Purge a game unconditionally: its rows (children cascade at the DB level), its GTFS uploads,
     * chat-image uploads and transit tiles. No host/permission check, unlike delete().
     */
    public function purge(Game $game): PurgedGame
    {
        $uuid = $game->getUuid();
        $name = $game->getName();
        $sources = $this->gtfsSources->findByGame($game);

        $this->entityManager->wrapInTransaction(function () use ($game): void {
            $this->gameGtfsLines->deleteByGame($game);
            $this->gtfsSources->deleteByGame($game);
            $this->entityManager->remove($game);
            $this->entityManager->flush();
        });

        $removed = [];
        foreach ($sources as $source) {
            $path = $source->getFilePath();
            if ($path !== '' && file_exists($path)) {
                $removed[] = $path;
            }
            $this->gtfsService->deleteFile($source);
        }

        return new PurgedGame($uuid, $name, [...$removed, ...$this->removeGameDirectories($uuid)]);
    }

    /** @return list<string> */
    private function removeGameDirectories(string $uuid): array
    {
        $dirs = ["{$this->chatImageDir}/{$uuid}", "{$this->tilesBaseDir}/{$uuid}"];
        $existing = array_values(array_filter($dirs, is_dir(...)));
        $this->filesystem->remove($dirs);

        return $existing;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Builds the per-game station timetable index from the merged GTFS directory.
 *
 * The index is optional: a failure is logged and the game keeps its transit overlay.
 */
final readonly class TransitTimetableBuilder
{
    private const string PYTHON_BIN = '/opt/osm2gtfs/bin/python';
    private const string SCRIPT = '/app/bin/gtfs_timetable.py';
    private const int TIMEOUT_SECONDS = 45;

    public function __construct(
        #[Autowire('%app.tiles_dir%')]
        private string $tilesBaseDir,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<array{path: string, uuid?: string, selectedRefs: list<string>}> $gtfsSources
     */
    public function build(string $gameUuid, string $mergedDir, array $gtfsSources): void
    {
        $outputDir = $this->tilesBaseDir . '/' . $gameUuid . '/timetable';
        $specPath = tempnam(sys_get_temp_dir(), 'gtfs-timetable-');
        if ($specPath === false) {
            $this->logger->warning('Could not create the timetable spec for game {game}.', ['game' => $gameUuid]);

            return;
        }

        try {
            $spec = [
                'mergedDir' => $mergedDir,
                'outputDir' => $outputDir,
                'daysBack' => 2,
                'daysForward' => 60,
                'sources' => array_map(static fn (array $source): array => [
                    'path' => $source['path'],
                    'uuid' => $source['uuid'] ?? '',
                    'selectedRefs' => $source['selectedRefs'],
                ], $gtfsSources),
            ];
            file_put_contents($specPath, json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $process = new Process(
                [self::PYTHON_BIN, self::SCRIPT, $specPath],
                null,
                null,
                null,
                self::TIMEOUT_SECONDS,
            );
            $process->run();

            if (!$process->isSuccessful() || !is_file($outputDir . '/meta.json')) {
                $this->fail($gameUuid, $outputDir, trim($process->getErrorOutput()));
            }
        } catch (\Throwable $e) {
            $this->fail($gameUuid, $outputDir, $e->getMessage());
        } finally {
            @unlink($specPath);
        }
    }

    private function fail(string $gameUuid, string $outputDir, string $error): void
    {
        $this->logger->warning('Timetable generation failed for game {game}: {error}', [
            'game' => $gameUuid,
            'error' => mb_substr($error, 0, 500),
        ]);
        $this->filesystem->remove($outputDir);
    }
}

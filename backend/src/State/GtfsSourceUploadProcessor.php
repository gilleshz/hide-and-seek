<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GtfsSourceResource;
use App\Dto\RealtimeSelection;
use App\Exception\FunctionalException;
use App\Service\GtfsService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<mixed, GtfsSourceResource>
 */
final readonly class GtfsSourceUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private GtfsService $gtfsService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): GtfsSourceResource {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new FunctionalException(message: 'No request available.', errorKey: 'gtfs_source.no_request');
        }

        $uploadedFile = $request->files->get('file');
        $name = $request->request->get('name');
        $realtimeProvider = $this->readText(
            $request->request->get('realtimeProvider'),
            16,
            'gtfs_source.realtime_provider_too_long',
            'The realtime provider is too long.',
        );
        $realtimeProfile = $this->readText(
            $request->request->get('realtimeProfile'),
            32,
            'gtfs_source.realtime_profile_too_long',
            'The realtime profile is too long.',
        );
        $realtimeUrl = $this->readUrl(
            $request->request->get('realtimeUrl'),
            'gtfs_source.realtime_url_too_long',
            'The realtime feed URL is too long.',
        );
        $realtimeAlertsUrl = $this->readUrl(
            $request->request->get('realtimeAlertsUrl'),
            'gtfs_source.realtime_alerts_url_invalid',
            'The realtime alerts URL is invalid.',
        );

        if ($uploadedFile instanceof UploadedFile) {
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw new FunctionalException(message: 'File upload failed.', errorKey: 'gtfs_source.upload_failed');
            }
            $sourceName = is_string($name) && $name !== '' ? $name : 'GTFS feed';
            $selection = RealtimeSelection::fromParts(
                $realtimeProvider,
                $realtimeProfile,
                $realtimeUrl,
                $realtimeAlertsUrl,
            );
            $source = $this->gtfsService->createFromUpload($uploadedFile, $sourceName, $selection);
        } else {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new FunctionalException(message: 'Expected JSON body with url and name fields.', errorKey: 'gtfs_source.expected_json');
            }
            $url = isset($body['url']) && is_string($body['url']) ? $body['url'] : '';
            if ($url === '') {
                throw new FunctionalException(message: 'Either a file upload or a URL is required.', errorKey: 'gtfs_source.input_required');
            }
            $sourceName = is_string($name) && $name !== '' ? $name : ($body['name'] ?? 'GTFS feed');
            if (!is_string($sourceName)) {
                $sourceName = 'GTFS feed';
            }
            $realtimeProvider = $this->readText(
                $body['realtimeProvider'] ?? null,
                16,
                'gtfs_source.realtime_provider_too_long',
                'The realtime provider is too long.',
            );
            $realtimeProfile = $this->readText(
                $body['realtimeProfile'] ?? null,
                32,
                'gtfs_source.realtime_profile_too_long',
                'The realtime profile is too long.',
            );
            $realtimeUrl = $this->readUrl(
                $body['realtimeUrl'] ?? null,
                'gtfs_source.realtime_url_too_long',
                'The realtime feed URL is too long.',
            );
            $realtimeAlertsUrl = $this->readUrl(
                $body['realtimeAlertsUrl'] ?? null,
                'gtfs_source.realtime_alerts_url_invalid',
                'The realtime alerts URL is invalid.',
            );
            $fallbackUrl = $this->readUrl(
                $body['fallbackUrl'] ?? null,
                'gtfs_source.fallback_url_too_long',
                'The fallback feed URL is too long.',
            );
            $selection = RealtimeSelection::fromParts(
                $realtimeProvider,
                $realtimeProfile,
                $realtimeUrl,
                $realtimeAlertsUrl,
            );
            $source = $this->gtfsService->createFromUrl($url, $sourceName, $selection, $fallbackUrl);
        }

        $routes = $this->gtfsService->extractRoutes($source);
        $metadata = $this->gtfsService->sourceMetadata($source);

        return GtfsSourceResource::fromSource($source, $routes, $metadata);
    }

    private function readUrl(mixed $value, string $errorKey, string $message): ?string
    {
        return $this->readText($value, 1000, $errorKey, $message);
    }

    private function readText(mixed $value, int $maxLength, string $errorKey, string $message): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw new FunctionalException(
                message: $message,
                errorKey: $errorKey,
            );
        }

        return $trimmed;
    }
}

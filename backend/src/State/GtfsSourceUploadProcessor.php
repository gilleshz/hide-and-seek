<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GtfsSourceResource;
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

        if ($uploadedFile instanceof UploadedFile) {
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw new FunctionalException(message: 'File upload failed.', errorKey: 'gtfs_source.upload_failed');
            }
            $sourceName = is_string($name) && $name !== '' ? $name : 'GTFS feed';
            $source = $this->gtfsService->createFromUpload($uploadedFile, $sourceName);
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
            $source = $this->gtfsService->createFromUrl($url, $sourceName);
        }

        $routes = $this->gtfsService->extractRoutes($source);

        return GtfsSourceResource::fromSource($source, $routes);
    }
}

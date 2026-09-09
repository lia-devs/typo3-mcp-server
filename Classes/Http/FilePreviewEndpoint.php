<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\McpAuthenticationException;
use Hn\McpServer\Service\McpAuthenticationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HTTP endpoint that serves FAL file thumbnails as direct image responses.
 *
 * URL: /mcp/preview?uid=123&w=400&h=400&token=BEARER_TOKEN
 *
 * This avoids base64 overhead in MCP responses — the PreviewFileTool returns
 * a URL to this endpoint, and MCP clients download the small thumbnail via curl.
 */
class FilePreviewEndpoint
{
    use CorsHeadersTrait;

    private const DEFAULT_WIDTH = 400;
    private const DEFAULT_HEIGHT = 400;
    private const MAX_WIDTH = 1200;
    private const MAX_HEIGHT = 1200;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }

        if ($request->getMethod() !== 'GET') {
            return $this->errorResponse('Method not allowed', 405);
        }

        // Authenticate via Bearer token (header or query param)
        try {
            $authService = GeneralUtility::makeInstance(McpAuthenticationService::class);
            $authService->authenticateRequest($request);
        } catch (McpAuthenticationException $exception) {
            return $this->errorResponse('Unauthorized: ' . $exception->getMessage(), 401);
        }

        $queryParams = $request->getQueryParams();
        $fileUid = (int)($queryParams['uid'] ?? 0);
        if ($fileUid <= 0) {
            return $this->errorResponse('Missing or invalid "uid" parameter', 400);
        }

        $width = min((int)($queryParams['w'] ?? self::DEFAULT_WIDTH), self::MAX_WIDTH);
        $height = min((int)($queryParams['h'] ?? self::DEFAULT_HEIGHT), self::MAX_HEIGHT);

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        try {
            $file = $resourceFactory->getFileObject($fileUid);
        } catch (\Throwable) {
            return $this->errorResponse('File not found: uid ' . $fileUid, 404);
        }

        $mimeType = $file->getMimeType();

        // Only process previewable images
        if (!str_starts_with($mimeType, 'image/') || $mimeType === 'image/svg+xml') {
            return $this->errorResponse('Preview not supported for ' . $mimeType, 415);
        }

        $processedFile = $file->process(
            ProcessedFile::CONTEXT_IMAGEPREVIEW,
            ['width' => $width, 'height' => $height]
        );

        $localPath = $processedFile->getForLocalProcessing(false);
        if ($localPath === '' || !file_exists($localPath)) {
            return $this->errorResponse('Failed to generate thumbnail', 500);
        }

        $imageData = file_get_contents($localPath);
        if ($imageData === false) {
            return $this->errorResponse('Failed to read processed file', 500);
        }

        $stream = new Stream('php://temp', 'rw');
        $stream->write($imageData);
        $stream->rewind();

        $response = new Response($stream, 200, [
            'Content-Type' => $processedFile->getMimeType(),
            'Content-Length' => (string)strlen($imageData),
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="preview-' . $fileUid . '.' . $processedFile->getExtension() . '"',
        ]);

        return $this->addCorsHeaders($response, $request);
    }

    private function errorResponse(string $message, int $status): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
        $stream->rewind();

        return new Response($stream, $status, ['Content-Type' => 'application/json']);
    }
}

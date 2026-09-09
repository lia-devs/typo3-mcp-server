<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Event\FilePreviewEvent;
use Hn\McpServer\Http\RequestUrlTrait;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\MCP\Tool\RequestAwareToolInterface;
use Hn\McpServer\Service\BaseUrlService;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for generating file previews (thumbnails) from TYPO3 FAL
 *
 * Returns a download URL to the /mcp/preview endpoint which serves the
 * thumbnail as a direct image response. MCP clients can download via curl.
 *
 * Built-in support for images (via TYPO3 ProcessedFile, locally cached).
 * Other file types (PDFs, Office docs, etc.) can be handled by PSR-14
 * event listeners via FilePreviewEvent.
 */
class PreviewFileTool extends AbstractRecordTool implements RequestAwareToolInterface
{
    use RequestUrlTrait;

    private const DEFAULT_WIDTH = 400;
    private const DEFAULT_HEIGHT = 400;
    private const MAX_WIDTH = 1200;
    private const MAX_HEIGHT = 1200;

    /**
     * Edge length used when several files are previewed in one call. A 400px
     * preview is roughly 56 KB of base64, a 150px one roughly 6 KB — for a row
     * of candidates that is the difference between a usable response and half
     * a megabyte of payload.
     */
    private const GRID_EDGE = 150;

    /**
     * Upper bound on files per call. Each preview is a separate image
     * operation, and on a remote storage those are network round trips — a
     * request asking for a hundred hits the proxy read timeout instead of
     * returning anything.
     */
    private const MAX_FILES = 20;

    private ?ServerRequestInterface $request = null;
    private ?BaseUrlService $baseUrlService = null;

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Generate a preview thumbnail for a file in TYPO3 FAL. '
                . 'Built-in support for image files (JPEG, PNG, GIF, WebP). '
                . 'PDF and Office previews require PSR-14 event listeners (e.g. imgix integration) — without them, these types return "preview not available". '
                . 'Width/height values above 1200px are silently clamped to 1200px. '
                . 'Use inline=true (default) to get the image as Base64 directly in the response. '
                . 'Use inline=false to get a download URL (requires Bearer token auth via curl). '
                . 'With several UIDs the previews default to ' . self::GRID_EDGE . 'px per edge instead of '
                . self::DEFAULT_WIDTH . 'px, which keeps a row of candidates affordable; pass width/height to override.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'oneOf' => [
                            ['type' => 'integer'],
                            ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                        'description' => 'The sys_file UID to preview, or an array of UIDs to preview several at once '
                            . '(max ' . self::MAX_FILES . '). Use the array form to compare candidates from a ReadTable '
                            . 'or SearchFile result in one call. Provide uid OR identifier.',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Combined identifier of the file (e.g. "1:/user_upload/photo.jpg"). Alternative to uid.',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Preview width in pixels (default: 400, max: 1200)',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Preview height in pixels (default: 400, max: 1200)',
                    ],
                    'inline' => [
                        'type' => 'boolean',
                        'description' => 'Return image as Base64 inline (default: true). Set to false for a download URL instead.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $identifier = (string)($params['identifier'] ?? '');
        $inline = (bool)($params['inline'] ?? true);

        $uids = [];
        if (isset($params['uid'])) {
            foreach (is_array($params['uid']) ? $params['uid'] : [$params['uid']] as $candidate) {
                if ((int)$candidate > 0) {
                    $uids[] = (int)$candidate;
                }
            }
            $uids = array_values(array_unique($uids));
        }

        if ($uids === [] && $identifier === '') {
            return $this->createErrorResult('Either "uid" or "identifier" is required.');
        }

        if (count($uids) > self::MAX_FILES) {
            return $this->createErrorResult(sprintf(
                'Too many files: %d requested, %d is the maximum per call. Each preview is a separate image '
                . 'operation, and on a remote storage those are network round trips.',
                count($uids),
                self::MAX_FILES
            ));
        }

        if (count($uids) > 1) {
            return $this->previewMany(
                $uids,
                min((int)($params['width'] ?? self::GRID_EDGE), self::MAX_WIDTH),
                min((int)($params['height'] ?? self::GRID_EDGE), self::MAX_HEIGHT),
                $inline
            );
        }

        $fileUid = $uids[0] ?? 0;
        $width = min((int)($params['width'] ?? self::DEFAULT_WIDTH), self::MAX_WIDTH);
        $height = min((int)($params['height'] ?? self::DEFAULT_HEIGHT), self::MAX_HEIGHT);

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        if ($fileUid > 0) {
            $file = $resourceFactory->getFileObject($fileUid);
        } else {
            $file = $resourceFactory->getFileObjectFromCombinedIdentifier($identifier);
        }

        $mimeType = $file->getMimeType();
        $metadata = $this->buildMetadataText($file, $mimeType);

        // Built-in: image preview
        if ($this->isPreviewableImage($mimeType)) {
            if ($inline) {
                return $this->inlinePreview($file, $width, $height, $metadata);
            }

            $previewUrl = $this->buildPreviewUrl($file->getUid(), $width, $height);
            return new CallToolResult([
                new TextContent($metadata),
                new TextContent(
                    'Preview URL: ' . $previewUrl . "\n\n"
                    . "Download with MCP OAuth Bearer token (same token used for this MCP session):\n"
                    . "curl -H 'Authorization: Bearer \$MCP_TOKEN' -o preview.jpg '" . $previewUrl . "'\n\n"
                    . "IMPORTANT: Use the MCP OAuth Bearer token for authentication. Do NOT use cookies or other auth methods."
                ),
            ]);
        }

        // Delegate to PSR-14 event listeners for other file types (e.g. PDFs via imgix)
        $event = new FilePreviewEvent($file, $width, $height);
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch($event);

        if ($event->hasPreview()) {
            return new CallToolResult([
                new TextContent($metadata),
                $event->getPreview(),
            ]);
        }

        // No listener provided a preview
        $errorMessage = $event->getErrorMessage()
            ?? 'Preview not available for this file type (' . $mimeType . ').';

        return new CallToolResult([
            new TextContent($metadata),
            new TextContent($errorMessage),
        ]);
    }

    /**
     * Preview several files in one result: a metadata line and an image per
     * file, in the order asked for. A file that cannot be previewed gets its
     * metadata and a reason rather than failing the whole call — the point of
     * asking for a row is to see what is there.
     *
     * @param int[] $uids
     */
    private function previewMany(array $uids, int $width, int $height, bool $inline): CallToolResult
    {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $content = [new TextContent(sprintf('%d file(s), previews at %dx%d px:', count($uids), $width, $height))];

        foreach ($uids as $uid) {
            try {
                $file = $resourceFactory->getFileObject($uid);
            } catch (\Exception $exception) {
                $content[] = new TextContent(sprintf('uid:%d | not available (%s)', $uid, $exception->getMessage()));
                continue;
            }

            $mimeType = $file->getMimeType();
            $content[] = new TextContent($this->buildMetadataText($file, $mimeType));

            if (!$this->isPreviewableImage($mimeType)) {
                $event = new FilePreviewEvent($file, $width, $height);
                GeneralUtility::makeInstance(EventDispatcherInterface::class)->dispatch($event);
                $content[] = $event->hasPreview()
                    ? $event->getPreview()
                    : new TextContent($event->getErrorMessage() ?? 'Preview not available for this file type (' . $mimeType . ').');
                continue;
            }

            if (!$inline) {
                $content[] = new TextContent('Preview URL: ' . $this->buildPreviewUrl($file->getUid(), $width, $height));
                continue;
            }

            $content[] = $this->renderInlineImage($file, $width, $height)
                ?? new TextContent('Failed to generate thumbnail for uid:' . $uid);
        }

        return new CallToolResult($content);
    }

    /**
     * The processed image as inline content, or null when processing failed.
     */
    private function renderInlineImage(File $file, int $width, int $height): ?ImageContent
    {
        $processedFile = $file->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => $width, 'height' => $height]);
        $localPath = $processedFile->getForLocalProcessing(false);
        if ($localPath === '' || !file_exists($localPath)) {
            return null;
        }
        $imageData = file_get_contents($localPath);

        return $imageData === false
            ? null
            : new ImageContent(base64_encode($imageData), $processedFile->getMimeType());
    }

    private function inlinePreview(
        \TYPO3\CMS\Core\Resource\File $file,
        int $width,
        int $height,
        string $metadata
    ): CallToolResult {
        $processedFile = $file->process(
            ProcessedFile::CONTEXT_IMAGEPREVIEW,
            ['width' => $width, 'height' => $height]
        );

        $localPath = $processedFile->getForLocalProcessing(false);
        if ($localPath === '' || !file_exists($localPath)) {
            return $this->createErrorResult('Failed to generate thumbnail');
        }

        $imageData = file_get_contents($localPath);
        if ($imageData === false) {
            return $this->createErrorResult('Failed to read processed file');
        }

        return new CallToolResult([
            new TextContent($metadata),
            new ImageContent(
                base64_encode($imageData),
                $processedFile->getMimeType()
            ),
        ]);
    }

    private function buildPreviewUrl(int $fileUid, int $width, int $height): string
    {
        $baseUrl = $this->resolveBaseUrl();

        return $baseUrl . '/mcp/preview?' . http_build_query([
            'uid' => $fileUid,
            'w' => $width,
            'h' => $height,
        ]);
    }

    private function resolveBaseUrl(): string
    {
        if ($this->request !== null) {
            return $this->getRequestBaseUrl($this->request);
        }

        if ($this->baseUrlService === null) {
            $this->baseUrlService = GeneralUtility::makeInstance(BaseUrlService::class);
        }

        return $this->baseUrlService->getBaseUrlFromSiteConfiguration();
    }

    private function isPreviewableImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            && $mimeType !== 'image/svg+xml';
    }

    private function buildMetadataText(File $file, string $mimeType): string
    {
        $lines = [
            sprintf('uid:%d | %s | %s | %s', $file->getUid(), $file->getName(), $this->formatFileSize($file->getSize()), $mimeType),
            sprintf('Path: %d:%s', $file->getStorage()->getUid(), $file->getIdentifier()),
        ];

        if ($this->isPreviewableImage($mimeType)) {
            $properties = $file->getProperties();
            $imageWidth = $properties['width'] ?? 0;
            $imageHeight = $properties['height'] ?? 0;
            if ($imageWidth > 0 && $imageHeight > 0) {
                $lines[] = sprintf('Dimensions: %dx%d px', $imageWidth, $imageHeight);
            }
        }

        return implode("\n", $lines);
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}

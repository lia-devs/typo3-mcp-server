<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

use Mcp\Types\ImageContent;
use TYPO3\CMS\Core\Resource\File;

/**
 * PSR-14 event dispatched when PreviewFileTool cannot generate a preview locally.
 *
 * Listeners can provide a preview by calling setPreview() with an ImageContent object.
 * If no listener provides a preview, the tool returns a "not supported" message.
 */
final class FilePreviewEvent
{
    private ?ImageContent $preview = null;
    private ?string $errorMessage = null;

    public function __construct(
        private readonly File $file,
        private readonly int $width,
        private readonly int $height,
    ) {}

    public function getFile(): File
    {
        return $this->file;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getMimeType(): string
    {
        return $this->file->getMimeType();
    }

    public function setPreview(ImageContent $preview): void
    {
        $this->preview = $preview;
    }

    public function getPreview(): ?ImageContent
    {
        return $this->preview;
    }

    public function hasPreview(): bool
    {
        return $this->preview !== null;
    }

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}

<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\PreviewFileTool;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PreviewFile takes a single UID or an array of them. The array form exists so
 * a caller can look at a row of candidates from one ReadTable or SearchFile
 * result without a call per file.
 */
class PreviewFileToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    /** @var int[] */
    private array $imageUids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $base = $this->instancePath . '/fileadmin';
        @mkdir($base . '/images', 0777, true);
        foreach (['one', 'two', 'three'] as $name) {
            $image = imagecreatetruecolor(8, 8);
            imagepng($image, $base . '/images/' . $name . '.png');
            imagedestroy($image);
        }

        // Index them so sys_file uids exist.
        $storage = GeneralUtility::makeInstance(StorageRepository::class)->findByUid(1);
        $folder = $storage->getFolder('/images/');
        foreach ($folder->getFiles() as $file) {
            $this->imageUids[] = $file->getUid();
        }
        sort($this->imageUids);
    }

    public function testASingleUidReturnsOneImage(): void
    {
        $tool = GeneralUtility::makeInstance(PreviewFileTool::class);

        $result = $tool->execute(['uid' => $this->imageUids[0]]);

        $this->assertFalse($result->isError, $result->content[0]->text ?? '');
        $images = array_filter($result->content, fn($block) => $block instanceof ImageContent);
        $this->assertCount(1, $images, 'One uid must produce exactly one image');
    }

    public function testAnArrayOfUidsReturnsOneImagePerFile(): void
    {
        $tool = GeneralUtility::makeInstance(PreviewFileTool::class);

        $result = $tool->execute(['uid' => $this->imageUids]);

        $this->assertFalse($result->isError, $result->content[0]->text ?? '');
        $images = array_filter($result->content, fn($block) => $block instanceof ImageContent);
        $this->assertCount(count($this->imageUids), $images);
        $this->assertStringContainsString(
            sprintf('%d file(s), previews at 150x150 px', count($this->imageUids)),
            $result->content[0]->text,
            'Several files default to the grid edge, not 400px'
        );
    }

    public function testAnUnknownUidInTheArrayDoesNotFailTheCall(): void
    {
        $tool = GeneralUtility::makeInstance(PreviewFileTool::class);

        $result = $tool->execute(['uid' => [$this->imageUids[0], 999999]]);

        $this->assertFalse($result->isError, 'The point of asking for a row is to see what is there');
        $texts = implode("\n", array_map(
            fn($block) => $block instanceof TextContent ? $block->text : '',
            $result->content
        ));
        $this->assertStringContainsString('uid:999999 | not available', $texts);
        $images = array_filter($result->content, fn($block) => $block instanceof ImageContent);
        $this->assertCount(1, $images, 'The file that does exist still comes back');
    }

    public function testTooManyUidsAreRefusedWithTheLimitNamed(): void
    {
        $tool = GeneralUtility::makeInstance(PreviewFileTool::class);

        $result = $tool->execute(['uid' => range(1, 21)]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('20 is the maximum per call', $result->content[0]->text);
    }

    public function testWidthOverridesTheGridDefault(): void
    {
        $tool = GeneralUtility::makeInstance(PreviewFileTool::class);

        $result = $tool->execute(['uid' => $this->imageUids, 'width' => 300, 'height' => 300]);

        $this->assertFalse($result->isError, $result->content[0]->text ?? '');
        $this->assertStringContainsString('previews at 300x300 px', $result->content[0]->text);
    }
}

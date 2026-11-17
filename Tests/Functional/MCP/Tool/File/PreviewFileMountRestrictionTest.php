<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\PreviewFileTool;
use Hn\McpServer\Service\BackendUserContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PreviewFile reaches files past FAL's permission checks
 * (ResourceFactory::getFileObject(), File::process()), so it has to apply the
 * user's file mounts itself.
 */
class PreviewFileMountRestrictionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private int $visibleUid;
    private int $secretUid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_filemounts.csv');

        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $storage = GeneralUtility::makeInstance(StorageRepository::class)->findByUid(1);
        foreach (['/user_upload/mountcheck-visible.png', '/secret/mountcheck-hidden.png'] as $identifier) {
            $path = $this->instancePath . '/fileadmin' . $identifier;
            GeneralUtility::mkdir_deep(dirname($path));
            imagepng(imagecreatetruecolor(8, 8), $path);
        }
        $this->visibleUid = $storage->getFile('/user_upload/mountcheck-visible.png')->getUid();
        $this->secretUid = $storage->getFile('/secret/mountcheck-hidden.png')->getUid();
    }

    /**
     * Become a non-admin editor the way the MCP endpoint does it.
     */
    private function actAsEditor(string $fileMountUids): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'editor_' . uniqid(),
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'file_mountpoints' => $fileMountUids,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        GeneralUtility::makeInstance(BackendUserContextService::class)->impersonate((int)$connection->lastInsertId());
    }

    private function textOf(CallToolResult $result): string
    {
        return implode("\n", array_map(
            fn($block) => $block instanceof TextContent ? $block->text : '',
            $result->content
        ));
    }

    private function imageCountOf(CallToolResult $result): int
    {
        return count(array_filter($result->content, fn($block) => $block instanceof ImageContent));
    }

    public function testPreviewFileShowsAFileWithinTheFileMounts(): void
    {
        $this->actAsEditor('1');

        $result = GeneralUtility::makeInstance(PreviewFileTool::class)->execute(['uid' => $this->visibleUid]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(1, $this->imageCountOf($result));
    }

    public function testPreviewFileRefusesAUidOutsideTheFileMounts(): void
    {
        $this->actAsEditor('1');

        $result = GeneralUtility::makeInstance(PreviewFileTool::class)->execute(['uid' => $this->secretUid]);

        $this->assertTrue($result->isError);
        $this->assertSame(0, $this->imageCountOf($result));
        $this->assertStringNotContainsString('mountcheck-hidden.png', $this->textOf($result));
    }

    public function testPreviewFileRefusesAnIdentifierOutsideTheFileMounts(): void
    {
        $this->actAsEditor('1');

        $result = GeneralUtility::makeInstance(PreviewFileTool::class)
            ->execute(['identifier' => '1:/secret/mountcheck-hidden.png']);

        $this->assertTrue($result->isError);
        $this->assertSame(0, $this->imageCountOf($result));
    }

    public function testPreviewFileSkipsUidsOutsideTheFileMountsInARow(): void
    {
        $this->actAsEditor('1');

        $result = GeneralUtility::makeInstance(PreviewFileTool::class)
            ->execute(['uid' => [$this->visibleUid, $this->secretUid]]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(1, $this->imageCountOf($result));
        $text = $this->textOf($result);
        $this->assertStringContainsString('uid:' . $this->secretUid . ' | not available', $text);
        $this->assertStringNotContainsString('mountcheck-hidden.png', $text);
    }
}

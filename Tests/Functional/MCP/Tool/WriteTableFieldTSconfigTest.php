<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Field visibility (TCEFORM.<table>.<field>.disabled) on write is resolved at the
 * page the record lives on, not at a fallback page.
 *
 * Regression: the record write layer rewrite dropped the pid from the per-field
 * canAccessField() check that #87 had added, so a field disabled on a subpage was
 * writable there as long as the fallback page did not disable it too.
 */
class WriteTableFieldTSconfigTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
    ];

    protected WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');

        // Page 2 ("About") disables the field for itself and its subtree;
        // the root page 1 leaves it enabled.
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->update('pages', [
                'TSconfig' => 'TCEFORM.tt_content.subheader.disabled = 1',
            ], ['uid' => 2]);

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    #[Test]
    public function updateRejectsFieldDisabledOnTheRecordsPage(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 102,
            'data' => ['subheader' => 'Disabled on page 2'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(
            "Validation error: Field 'subheader' is not accessible",
            $result->content[0]->text
        );
    }

    #[Test]
    public function createRejectsFieldDisabledOnTheTargetPage(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 2,
                'CType' => 'textmedia',
                'header' => 'New element',
                'subheader' => 'Disabled on page 2',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(
            "Validation error: Field 'subheader' is not accessible",
            $result->content[0]->text
        );
    }

    #[Test]
    public function updateAcceptsFieldOutsideTheDisablingSubtree(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['subheader' => 'Enabled on page 1'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}

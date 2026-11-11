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
 * The parent's inline or file field holds the number of its children, as DataHandler
 * writes it when the backend saves the form (#136).
 *
 * The children are written after the parent, so the parent's field kept its default.
 * Extbase skips a property whose column is NULL, so an image added through the MCP
 * was never loaded, not even after publishing, until the record was saved once in the
 * backend.
 */
class InlineRelationCounterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_untracked_table_field',
        __DIR__ . '/../../Fixtures/Extensions/test_shared_independent_children',
        'mcp_server',
    ];

    private const EMBEDDED_FIELD = 'tx_testuntrackedtablefield_items';
    private const INDEPENDENT_FIELD = 'tx_testsharedindependentchildren_items';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function newParentCountsItsEmbeddedIndependentAndFileChildren(): void
    {
        $uid = $this->create([
            self::EMBEDDED_FIELD => [['title' => 'First'], ['title' => 'Second']],
            self::INDEPENDENT_FIELD => [['title' => 'Shared']],
            'assets' => [1],
        ]);

        $this->assertSame(
            [self::EMBEDDED_FIELD => 2, self::INDEPENDENT_FIELD => 1, 'assets' => 1],
            $this->getCounters($uid)
        );
    }

    #[Test]
    public function updateCountsTheChildrenTheListLeaves(): void
    {
        $uid = $this->create([
            self::EMBEDDED_FIELD => [['title' => 'First'], ['title' => 'Second']],
            self::INDEPENDENT_FIELD => [['title' => 'Shared'], ['title' => 'Another']],
        ]);

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => [
                self::EMBEDDED_FIELD => [['title' => 'Only']],
                self::INDEPENDENT_FIELD => [],
            ],
        ]);

        $this->assertSame(
            [self::EMBEDDED_FIELD => 1, self::INDEPENDENT_FIELD => 0, 'assets' => 0],
            $this->getCounters($uid)
        );
    }

    #[Test]
    public function liveParentGetsTheCounterInItsWorkspaceVersionOnly(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 500,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Live element',
            self::EMBEDDED_FIELD => 1,
        ]);
        $connectionPool->getConnectionForTable('tx_testuntrackedtablefield_item')->insert('tx_testuntrackedtablefield_item', [
            'uid' => 51,
            'pid' => 1,
            'title' => 'Live item',
            'parent_uid' => 500,
            'parent_table' => 'tt_content',
        ]);

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => 500,
            'data' => [self::EMBEDDED_FIELD => [['uid' => 51], ['title' => 'Added']]],
        ]);

        $queryBuilder = $connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $versionUid = (int)$queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(500)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter((int)$GLOBALS['BE_USER']->workspace))
            )
            ->executeQuery()
            ->fetchOne();
        $this->assertGreaterThan(0, $versionUid, 'The live parent must have a workspace version');
        $this->assertSame(2, $this->getCounters($versionUid)[self::EMBEDDED_FIELD]);
        $this->assertSame(1, $this->getCounters(500)[self::EMBEDDED_FIELD]);
    }

    private function create(array $relations): int
    {
        return (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => ['pid' => 1, 'CType' => 'textmedia', 'header' => 'Counted element'] + $relations,
        ])['uid'];
    }

    private function write(array $params): array
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace, 'WriteTable must operate in a workspace');
        return json_decode($result->content[0]->text, true);
    }

    /**
     * The counters stored on one row of tt_content.
     *
     * @return array<string, int>
     */
    private function getCounters(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select(self::EMBEDDED_FIELD, self::INDEPENDENT_FIELD, 'assets')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($row);
        return array_map('intval', $row);
    }
}

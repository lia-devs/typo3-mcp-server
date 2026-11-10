<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Embedded inline children whose foreign_table_field is no TCA column of the child
 * table (#158).
 *
 * DataHandler drops a value for a field without a column, so a new child was written
 * without its parent's table. TYPO3 selects the children by that table, so the backend
 * form, the page module and the frontend showed the parent without them.
 */
class UntrackedTableFieldInlineTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_untracked_table_field',
        'mcp_server',
    ];

    private const CHILD_TABLE = 'tx_testuntrackedtablefield_item';
    private const FIELD = 'tx_testuntrackedtablefield_items';

    private const PARENT_UID = 400;
    private const LIVE_CHILD_UID = 41;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::PARENT_UID,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Live list element',
            self::FIELD => 1,
        ]);
        $connectionPool->getConnectionForTable(self::CHILD_TABLE)->insert(self::CHILD_TABLE, [
            'uid' => self::LIVE_CHILD_UID,
            'pid' => 1,
            'title' => 'Live item',
            'parent_uid' => self::PARENT_UID,
            'parent_table' => 'tt_content',
            'sorting' => 256,
        ]);
    }

    #[Test]
    public function newChildrenOfANewParentNameItsTable(): void
    {
        $parentUid = (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'New list element',
                self::FIELD => [['title' => 'First'], ['title' => 'Second']],
            ],
        ])['uid'];

        $children = $this->readChildren($parentUid);
        $this->assertSame(['First', 'Second'], array_column($children, 'title'));
        foreach ($children as $child) {
            $this->assertSame(['parent_uid' => $parentUid, 'parent_table' => 'tt_content'], $this->getRelationFields((int)$child['uid']));
        }
    }

    #[Test]
    public function newChildOfALiveParentNamesItsTable(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::PARENT_UID,
            'data' => [self::FIELD => [['uid' => self::LIVE_CHILD_UID], ['title' => 'Added']]],
        ]);

        $children = $this->readChildren(self::PARENT_UID);
        $this->assertSame(['Live item', 'Added'], array_column($children, 'title'));
        $this->assertSame(
            ['parent_uid' => self::PARENT_UID, 'parent_table' => 'tt_content'],
            $this->getRelationFields((int)$children[1]['uid'])
        );
    }

    #[Test]
    public function nestedChildNamesTheTableOfItsOwnParent(): void
    {
        $parentUid = (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Nested list element',
                self::FIELD => [['title' => 'Group', 'subitems' => [['title' => 'Nested']]]],
            ],
        ])['uid'];

        $groupUid = (int)$this->readChildren($parentUid)[0]['uid'];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::CHILD_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $nested = $queryBuilder
            ->select('title', 'parent_table')
            ->from(self::CHILD_TABLE)
            ->where($queryBuilder->expr()->eq('parent_uid', $queryBuilder->createNamedParameter($groupUid)))
            ->executeQuery()
            ->fetchAllAssociative();
        $this->assertSame([['title' => 'Nested', 'parent_table' => self::CHILD_TABLE]], $nested);
    }

    private function write(array $params): array
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace, 'WriteTable must operate in a workspace');
        return json_decode($result->content[0]->text, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readChildren(int $parentUid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $parentUid,
            'fields' => [self::FIELD],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true)['records'][0][self::FIELD];
    }

    /**
     * The relation fields of a child created in the workspace, which is its own row.
     *
     * @return array{parent_uid: int, parent_table: string}
     */
    private function getRelationFields(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::CHILD_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('parent_uid', 'parent_table')
            ->from(self::CHILD_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($row, 'Item ' . $uid . ' does not exist');
        return ['parent_uid' => (int)$row['parent_uid'], 'parent_table' => (string)$row['parent_table']];
    }
}

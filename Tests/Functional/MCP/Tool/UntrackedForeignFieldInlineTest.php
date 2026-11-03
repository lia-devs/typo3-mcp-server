<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Independent inline children whose foreign_field is no TCA column of the child table.
 *
 * TYPO3 sets such a pointer through RelationHandler, never through the child's form, and
 * DataHandler silently drops a field it has no column for. Linking the children through
 * DataHandler therefore reported success while every child stayed detached.
 */
class UntrackedForeignFieldInlineTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_untracked_foreign_field',
        'mcp_server',
    ];

    private const PARENT_UID = 300;
    private const LINKED_CHILD_UID = 10;
    private const DETACHED_CHILD_UID = 11;
    private const SECOND_LINKED_CHILD_UID = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        // Everything below is live, the tool has to stage every change in a workspace.
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::PARENT_UID,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Live teaser element',
            'tx_testuntrackedforeignfield_items' => 2,
        ]);
        foreach ([
            self::LINKED_CHILD_UID => self::PARENT_UID,
            self::DETACHED_CHILD_UID => 0,
            self::SECOND_LINKED_CHILD_UID => self::PARENT_UID,
        ] as $uid => $parentUid) {
            $connectionPool->getConnectionForTable('tx_testuntrackedforeignfield_item')->insert('tx_testuntrackedforeignfield_item', [
                'uid' => $uid,
                'pid' => 1,
                'title' => 'Live teaser ' . $uid,
                'content_element' => $parentUid,
                'sorting' => $uid * 256,
            ]);
        }
    }

    public function testCreatingParentWithNewChildrenLinksThem(): void
    {
        $parentUid = (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'New teaser element',
                'tx_testuntrackedforeignfield_items' => [
                    ['title' => 'First teaser', 'image' => [1]],
                    ['title' => 'Second teaser', 'image' => [1]],
                ],
            ],
        ])['uid'];

        $childUids = $this->readChildUids($parentUid);
        $this->assertCount(2, $childUids);
        $this->assertSame(
            ['First teaser', 'Second teaser'],
            array_map(fn(int $uid): string => $this->readChild($uid)['title'], $childUids)
        );
    }

    public function testLinkingAnotherChildToLiveParentKeepsLiveUntouched(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::PARENT_UID,
            'data' => [
                // Independent children keep their own sorting; listed here in that order.
                'tx_testuntrackedforeignfield_items' => [
                    self::LINKED_CHILD_UID,
                    self::DETACHED_CHILD_UID,
                    self::SECOND_LINKED_CHILD_UID,
                ],
            ],
        ]);

        $this->assertSame(
            [self::LINKED_CHILD_UID, self::DETACHED_CHILD_UID, self::SECOND_LINKED_CHILD_UID],
            $this->readChildUids(self::PARENT_UID)
        );
        $this->assertSame(0, $this->getLiveParentPointer(self::DETACHED_CHILD_UID));
    }

    public function testOmittedChildIsDetachedInTheWorkspaceOnly(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::PARENT_UID,
            'data' => ['tx_testuntrackedforeignfield_items' => [self::LINKED_CHILD_UID]],
        ]);

        $this->assertSame([self::LINKED_CHILD_UID], $this->readChildUids(self::PARENT_UID));
        $this->assertSame(self::PARENT_UID, $this->getLiveParentPointer(self::SECOND_LINKED_CHILD_UID));
    }

    private function write(array $params): array
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace, 'WriteTable must operate in a workspace');
        return json_decode($result->content[0]->text, true);
    }

    /**
     * @return list<int>
     */
    private function readChildUids(int $parentUid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $parentUid,
            'fields' => ['tx_testuntrackedforeignfield_items'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        return array_map('intval', $record['tx_testuntrackedforeignfield_items']);
    }

    private function readChild(int $uid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tx_testuntrackedforeignfield_item',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true)['records'][0];
    }

    private function getLiveParentPointer(int $childUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_testuntrackedforeignfield_item');
        $queryBuilder->getRestrictions()->removeAll();
        return (int)$queryBuilder
            ->select('content_element')
            ->from('tx_testuntrackedforeignfield_item')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($childUid)))
            ->executeQuery()
            ->fetchOne();
    }
}

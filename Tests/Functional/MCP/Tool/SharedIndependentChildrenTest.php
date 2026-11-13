<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Independent inline children that several parent tables share through
 * foreign_table_field (#137).
 *
 * The backend sets three things when it links such a child: the parent pointer
 * (foreign_field), the parent's table (foreign_table_field) and the position
 * (foreign_sortby). It writes them through RelationHandler, not through the child's
 * form, so neither a missing TCA column nor a missing exclude-field permission on the
 * child keeps the link from being written.
 */
class SharedIndependentChildrenTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_shared_independent_children',
        'mcp_server',
    ];

    private const CHILD_TABLE = 'tx_testsharedindependentchildren_item';
    private const FIELD = 'tx_testsharedindependentchildren_items';

    /** Same uid as the page below, so only parent_table tells their children apart. */
    private const CONTENT_UID = 2;
    private const PAGE_UID = 2;

    private const CHILD_OF_PAGE = 21;
    private const UNLINKED_CHILD = 22;
    private const CHILD_OF_CONTENT = 23;
    /** On a page the editor below may not edit. */
    private const CHILD_ON_FOREIGN_PAGE = 24;
    /** On a page the editor below may edit, but that is locked for editing. */
    private const CHILD_ON_LOCKED_PAGE = 25;
    private const LOCKED_PAGE = 3;

    private const EDITOR_UID = 99;
    private const EDITOR_GROUP_UID = 98;
    private const EDITOR_WORKSPACE = 50;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        // Everything below is live, the tool has to stage every change in a workspace.
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::CONTENT_UID,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Live element',
            self::FIELD => 1,
        ]);
        foreach ([
            self::CHILD_OF_PAGE => [self::PAGE_UID, 'pages'],
            self::UNLINKED_CHILD => [0, ''],
            self::CHILD_OF_CONTENT => [self::CONTENT_UID, 'tt_content'],
            self::CHILD_ON_FOREIGN_PAGE => [0, ''],
            self::CHILD_ON_LOCKED_PAGE => [0, ''],
        ] as $uid => [$parentUid, $parentTable]) {
            $connectionPool->getConnectionForTable(self::CHILD_TABLE)->insert(self::CHILD_TABLE, [
                'uid' => $uid,
                'pid' => match ($uid) {
                    self::CHILD_ON_FOREIGN_PAGE => 2,
                    self::CHILD_ON_LOCKED_PAGE => self::LOCKED_PAGE,
                    default => 1,
                },
                'title' => 'Live item ' . $uid,
                'parent_uid' => $parentUid,
                'parent_table' => $parentTable,
                'sorting' => $uid * 256,
            ]);
        }
    }

    #[Test]
    public function newParentNamesItsTableOnTheChildren(): void
    {
        $itemUid = (int)$this->write([
            'table' => self::CHILD_TABLE,
            'action' => 'create',
            'data' => ['pid' => 1, 'title' => 'New item'],
        ])['uid'];
        $contentUid = (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'New element',
                self::FIELD => [$itemUid],
            ],
        ])['uid'];

        $this->assertSame([$itemUid], $this->readChildUids($contentUid));
        $this->assertSame(
            ['parent_uid' => $contentUid, 'parent_table' => 'tt_content'],
            $this->getRelationFieldsInWorkspace($itemUid)
        );
    }

    #[Test]
    public function newChildrenNameTheTableOfTheirParent(): void
    {
        $contentUid = (int)$this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'New element',
                self::FIELD => [['title' => 'New item']],
            ],
        ])['uid'];

        $childUids = $this->readChildUids($contentUid);
        $this->assertCount(1, $childUids);
        $this->assertSame(
            ['parent_uid' => $contentUid, 'parent_table' => 'tt_content'],
            $this->getRelationFieldsInWorkspace($childUids[0])
        );
    }

    #[Test]
    public function linkedChildNamesTheTableOfItsParentInTheWorkspaceOnly(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);

        $this->assertSame([self::CHILD_OF_CONTENT, self::UNLINKED_CHILD], $this->readChildUids(self::CONTENT_UID));
        $this->assertSame(
            ['parent_uid' => self::CONTENT_UID, 'parent_table' => 'tt_content'],
            $this->getRelationFieldsInWorkspace(self::UNLINKED_CHILD)
        );
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getLiveRelationFields(self::UNLINKED_CHILD));
    }

    #[Test]
    public function childOfAnotherParentTableWithTheSameUidIsNeitherListedNorDeleted(): void
    {
        $this->assertSame([self::CHILD_OF_CONTENT], $this->readChildUids(self::CONTENT_UID));

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        $this->assertSame([self::UNLINKED_CHILD], $this->readChildUids(self::CONTENT_UID));
        $this->assertSame(
            ['parent_uid' => self::PAGE_UID, 'parent_table' => 'pages'],
            $this->getRelationFieldsInWorkspace(self::CHILD_OF_PAGE)
        );
    }

    #[Test]
    public function editorWithoutPermissionForTheParentPointerStillLinksTheChild(): void
    {
        $this->loginEditorWithoutParentPointerPermission();

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);

        $this->assertSame(self::EDITOR_WORKSPACE, (int)$GLOBALS['BE_USER']->workspace);
        $this->assertSame(
            ['parent_uid' => self::CONTENT_UID, 'parent_table' => 'tt_content'],
            $this->getRelationFieldsInWorkspace(self::UNLINKED_CHILD)
        );
    }

    /**
     * Publishing the parent does not take the children's change along: the result names
     * them, so they can be published together.
     */
    #[Test]
    public function resultNamesTheChildrenChangedInTheWorkspace(): void
    {
        $result = $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        $this->assertSame([self::CHILD_TABLE => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]], $result['changed_inline_children']);
        $this->assertStringContainsString('Publish them together with this record', $result['changed_inline_children_note']);
    }

    /**
     * The pointer is no field the editor fills in, but the child is still a record: like
     * the backend, which only links children the editor may edit, a child on a page the
     * editor may not edit is refused, and nothing is written.
     */
    #[Test]
    public function editorCannotLinkAChildOnAPageTheyMayNotEdit(): void
    {
        $this->loginEditorWithoutParentPointerPermission();

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::CHILD_ON_FOREIGN_PAGE]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString((string)self::CHILD_ON_FOREIGN_PAGE, $result->content[0]->text);
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getRelationFieldsInWorkspace(self::CHILD_ON_FOREIGN_PAGE));
        $this->assertSame(
            ['parent_uid' => self::CONTENT_UID, 'parent_table' => 'tt_content'],
            $this->getRelationFieldsInWorkspace(self::CHILD_OF_CONTENT)
        );
    }

    #[Test]
    public function childDeletedInTheWorkspaceCannotBeLinked(): void
    {
        $this->write(['table' => self::CHILD_TABLE, 'action' => 'delete', 'uid' => self::UNLINKED_CHILD]);

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('deleted', $result->content[0]->text);
    }

    /**
     * A child table without workspace support has no row a workspace could change: in a
     * workspace the link is refused instead of written live.
     */
    #[Test]
    public function childTableWithoutWorkspaceSupportIsRefusedInAWorkspace(): void
    {
        // Get a workspace first, the way any write does.
        $this->write(['table' => 'tt_content', 'action' => 'update', 'uid' => self::CONTENT_UID, 'data' => ['header' => 'Changed']]);
        $GLOBALS['TCA'][self::CHILD_TABLE]['ctrl']['versioningWS'] = false;

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getLiveRelationFields(self::UNLINKED_CHILD));
        $this->assertSame(
            ['parent_uid' => self::CONTENT_UID, 'parent_table' => 'tt_content'],
            $this->getLiveRelationFields(self::CHILD_OF_CONTENT)
        );
    }

    #[Test]
    public function listOrderIsTheChildrensOrder(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD, self::CHILD_OF_CONTENT]],
        ]);
        $this->assertSame([self::UNLINKED_CHILD, self::CHILD_OF_CONTENT], $this->readChildUids(self::CONTENT_UID));

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);
        $this->assertSame([self::CHILD_OF_CONTENT, self::UNLINKED_CHILD], $this->readChildUids(self::CONTENT_UID));
    }

    /**
     * A record that was linked in the workspace only stays a record of its own when the
     * next list leaves it out: only the workspace link is taken back.
     */
    #[Test]
    public function childLinkedInTheWorkspaceOnlyIsUnlinkedAgainNotDeleted(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT]],
        ]);

        $this->assertSame([self::CHILD_OF_CONTENT], $this->readChildUids(self::CONTENT_UID));
        $this->assertFalse($this->isDeletedInWorkspace(self::UNLINKED_CHILD));
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getRelationFieldsInWorkspace(self::UNLINKED_CHILD));
    }

    /**
     * A child linked live is deleted when the list leaves it out, as in the backend form:
     * unlinked in the workspace only, publishing the parent would link it again.
     */
    #[Test]
    public function childLinkedLiveIsDeletedWhenTheListLeavesItOut(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => []],
        ]);

        $this->assertSame([], $this->readChildUids(self::CONTENT_UID));
        $this->assertTrue($this->isDeletedInWorkspace(self::CHILD_OF_CONTENT));
    }

    /**
     * A child whose live row still points at the parent, but whose workspace version was
     * linked to another parent, is no child of the first one in the workspace.
     */
    #[Test]
    public function childMovedToAnotherParentInTheWorkspaceIsNeitherListedNorDeleted(): void
    {
        $this->write([
            'table' => 'pages',
            'action' => 'update',
            'uid' => self::PAGE_UID,
            'data' => [self::FIELD => [self::CHILD_OF_PAGE, self::CHILD_OF_CONTENT]],
        ]);
        $this->assertSame([], $this->readChildUids(self::CONTENT_UID));

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        $this->assertSame(
            ['parent_uid' => self::PAGE_UID, 'parent_table' => 'pages'],
            $this->getRelationFieldsInWorkspace(self::CHILD_OF_CONTENT)
        );
        $this->assertFalse($this->isDeletedInWorkspace(self::CHILD_OF_CONTENT));
    }

    /**
     * The permission is checked on the row that is written: a child moved in the
     * workspace to a page the editor may not edit cannot be linked, although its live
     * row lies on a page they may edit.
     */
    #[Test]
    public function editorCannotLinkAChildMovedToAPageTheyMayNotEdit(): void
    {
        $this->loginEditorWithoutParentPointerPermission();
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::CHILD_TABLE)->insert(self::CHILD_TABLE, [
            'pid' => 2,
            'title' => 'Live item ' . self::UNLINKED_CHILD,
            'parent_uid' => 0,
            'parent_table' => '',
            't3ver_wsid' => self::EDITOR_WORKSPACE,
            't3ver_oid' => self::UNLINKED_CHILD,
            't3ver_state' => VersionState::MOVE_POINTER->value,
        ]);

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::UNLINKED_CHILD]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString((string)self::UNLINKED_CHILD, $result->content[0]->text);
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getRelationFieldsInWorkspace(self::UNLINKED_CHILD));
    }

    #[Test]
    public function editorCannotLinkAChildOnALockedPage(): void
    {
        $this->loginEditorWithoutParentPointerPermission();
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', [
            'perms_userid' => self::EDITOR_UID,
            'perms_user' => 31,
            'editlock' => 1,
        ], ['uid' => self::LOCKED_PAGE]);

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::CHILD_OF_CONTENT, self::CHILD_ON_LOCKED_PAGE]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('locked', $result->content[0]->text);
        $this->assertSame(['parent_uid' => 0, 'parent_table' => ''], $this->getRelationFieldsInWorkspace(self::CHILD_ON_LOCKED_PAGE));
    }

    #[Test]
    public function aLaterWriteOfTheSameToolDoesNotNameEarlierChildren(): void
    {
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $tool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        $result = $tool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => ['header' => 'Only the header'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertArrayNotHasKey('changed_inline_children', json_decode($result->content[0]->text, true));
    }

    /**
     * Published together, the parent and the children the result names give the live
     * state the workspace showed.
     */
    #[Test]
    public function publishingTheParentWithTheNamedChildrenTakesTheRelationLive(): void
    {
        $result = $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [self::UNLINKED_CHILD]],
        ]);

        // Publishing one record may take others along: only what still has a workspace
        // version is published.
        foreach ($result['changed_inline_children'][self::CHILD_TABLE] as $childUid) {
            $this->publishIfVersioned(self::CHILD_TABLE, $childUid);
        }
        $this->publishIfVersioned('tt_content', self::CONTENT_UID);

        $this->assertSame(
            ['parent_uid' => self::CONTENT_UID, 'parent_table' => 'tt_content'],
            $this->getLiveRelationFields(self::UNLINKED_CHILD)
        );
        $this->assertSame(1, (int)$this->fetchLiveRow(self::CHILD_OF_CONTENT)['deleted']);
    }

    /**
     * Publishes the workspace version of a live record, if it still has one.
     */
    private function publishIfVersioned(string $table, int $liveUid): void
    {
        $workspace = (int)$GLOBALS['BE_USER']->workspace;
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect($workspace));
        $version = BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, $liveUid, 'uid');
        if (!is_array($version)) {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$liveUid => ['version' => ['action' => 'publish', 'swapWith' => (int)$version['uid']]]]]);
        $dataHandler->process_cmdmap();
        $this->assertSame([], $dataHandler->errorLog);
    }

    /**
     * An editor who may edit the parent's inline field and the child table, but not the
     * child's exclude field parent_uid. The backend form still links children for them:
     * the pointer is part of the parent's relation, not a field the editor fills in.
     */
    private function loginEditorWithoutParentPointerPermission(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        // Page permissions only apply to a user in at least one group (calcPerms()).
        $connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => self::EDITOR_GROUP_UID,
            'pid' => 0,
            'title' => 'Editors',
        ]);
        $connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'usergroup' => (string)self::EDITOR_GROUP_UID,
            'db_mountpoints' => '1',
        ]);
        $connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => self::EDITOR_WORKSPACE,
            'pid' => 0,
            'title' => 'Editor workspace',
            'members' => 'be_users_' . self::EDITOR_UID,
        ]);
        $connectionPool->getConnectionForTable('pages')->update('pages', [
            'perms_userid' => self::EDITOR_UID,
            'perms_user' => 31,
        ], ['uid' => 1]);

        $editor = $this->setUpBackendUser(self::EDITOR_UID);
        $editor->groupData['tables_select'] = 'pages,tt_content,' . self::CHILD_TABLE;
        $editor->groupData['tables_modify'] = 'tt_content,' . self::CHILD_TABLE;
        $editor->groupData['non_exclude_fields'] = 'tt_content:' . self::FIELD;
        $editor->groupData['webmounts'] = '1';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:textmedia';
        $editor->setWorkspace(self::EDITOR_WORKSPACE);
        $this->assertFalse($editor->check('non_exclude_fields', self::CHILD_TABLE . ':parent_uid'));
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
    private function readChildUids(int $contentUid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
            'fields' => [self::FIELD],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        return array_map('intval', $record[self::FIELD]);
    }

    /**
     * The relation fields of the row the workspace sees: the child itself when it was
     * created in the workspace, else its workspace version, else the live row.
     *
     * @return array{parent_uid: int, parent_table: string}
     */
    private function getRelationFieldsInWorkspace(int $liveUid): array
    {
        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::CHILD_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('parent_uid', 'parent_table')
            ->from(self::CHILD_TABLE)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid))
                )
            )
            ->executeQuery()
            ->fetchAssociative();
        return $this->toRelationFields($row === false ? $this->fetchLiveRow($liveUid) : $row);
    }

    /**
     * @return array{parent_uid: int, parent_table: string}
     */
    private function getLiveRelationFields(int $liveUid): array
    {
        return $this->toRelationFields($this->fetchLiveRow($liveUid));
    }

    private function isDeletedInWorkspace(int $liveUid): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::CHILD_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $state = $queryBuilder
            ->select('t3ver_state')
            ->from(self::CHILD_TABLE)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter((int)$GLOBALS['BE_USER']->workspace)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid))
            )
            ->executeQuery()
            ->fetchOne();
        return $state !== false && VersionState::tryFrom((int)$state) === VersionState::DELETE_PLACEHOLDER;
    }

    private function fetchLiveRow(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::CHILD_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('parent_uid', 'parent_table', 'deleted')
            ->from(self::CHILD_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($row, 'Item ' . $uid . ' does not exist');
        return $row;
    }

    /**
     * @return array{parent_uid: int, parent_table: string}
     */
    private function toRelationFields(array $row): array
    {
        return ['parent_uid' => (int)$row['parent_uid'], 'parent_table' => (string)$row['parent_table']];
    }
}

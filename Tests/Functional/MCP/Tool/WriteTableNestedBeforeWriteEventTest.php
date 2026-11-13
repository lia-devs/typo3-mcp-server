<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Mcp\Types\CallToolResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BeforeRecordWriteEvent is where an installation vetoes writes as a matter of
 * policy. A record can be written directly or nested into its inline parent;
 * the listener has to be asked either way, and before anything is written.
 *
 * Content element 2 has one independent child, item 23.
 */
class WriteTableNestedBeforeWriteEventTest extends FunctionalTestCase
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
    private const CONTENT_UID = 2;
    private const CHILD_UID = 23;

    /** @var list<string> "action table" of every event the listener saw */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::CONTENT_UID,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Live element',
            self::FIELD => 1,
        ]);
        $connectionPool->getConnectionForTable(self::CHILD_TABLE)->insert(self::CHILD_TABLE, [
            'uid' => self::CHILD_UID,
            'pid' => 1,
            'title' => 'Live item',
            'parent_uid' => self::CONTENT_UID,
            'parent_table' => 'tt_content',
            'sorting' => 256,
        ]);
    }

    protected function tearDown(): void
    {
        GeneralUtility::removeSingletonInstance(
            EventDispatcherInterface::class,
            GeneralUtility::makeInstance(EventDispatcherInterface::class)
        );
        parent::tearDown();
    }

    /**
     * Veto every write of the given kind to the child table.
     */
    private function forbidOnChildTable(string $action): void
    {
        $this->listen(function (BeforeRecordWriteEvent $event) use ($action): void {
            if ($event->getTable() === self::CHILD_TABLE && $event->getAction() === $action) {
                $event->veto('Items are managed elsewhere');
            }
        });
    }

    private function listen(callable $listener): void
    {
        $seen = &$this->seen;
        $dispatcher = new class($listener, $seen) implements EventDispatcherInterface, SingletonInterface {
            public function __construct(private $listener, private array &$seen) {}

            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeRecordWriteEvent) {
                    $this->seen[] = $event->getAction() . ' ' . $event->getTable();
                    ($this->listener)($event);
                }
                return $event;
            }
        };
        GeneralUtility::setSingletonInstance(EventDispatcherInterface::class, $dispatcher);
    }

    private function write(array $params): CallToolResult
    {
        return GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
    }

    /**
     * @return array<string, mixed> the content element as ReadTable returns it
     */
    private function readContent(): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'uid' => self::CONTENT_UID]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return json_decode($result->content[0]->text, true)['records'][0];
    }

    private function countRows(string $table): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->count('*', $table, []);
    }

    public function testAVetoOnANestedCreateStopsTheWholeWrite(): void
    {
        $this->forbidOnChildTable('create');
        $contentRows = $this->countRows('tt_content');

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Parent of a forbidden item',
                self::FIELD => [['title' => 'Smuggled item']],
            ],
        ]);

        $this->assertTrue($result->isError, 'The nested create must reach the listener');
        $this->assertStringContainsString('Items are managed elsewhere', $result->content[0]->text);
        $this->assertSame($contentRows, $this->countRows('tt_content'), 'The parent must not be written either');
        $this->assertSame(1, $this->countRows(self::CHILD_TABLE));
    }

    public function testAVetoOnANestedUpdateStopsTheWholeWrite(): void
    {
        $this->forbidOnChildTable('update');

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::CONTENT_UID,
            'data' => [
                'header' => 'Changed along the way',
                self::FIELD => [['uid' => self::CHILD_UID, 'title' => 'Changed item']],
            ],
        ]);

        $this->assertTrue($result->isError, 'The nested update must reach the listener');
        $this->assertStringContainsString('Items are managed elsewhere', $result->content[0]->text);
        $this->assertSame('Live element', $this->readContent()['header'], 'The parent must not be written either');
    }

    public function testAVetoOnTheDeletionOfADroppedChildStopsTheWholeWrite(): void
    {
        $this->forbidOnChildTable('delete');

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => []],
        ]);

        $this->assertTrue($result->isError, 'Emptying the list deletes the child; the listener must be asked');
        $this->assertStringContainsString('Items are managed elsewhere', $result->content[0]->text);
        $this->assertSame([self::CHILD_UID], $this->readContent()[self::FIELD]);
    }

    public function testLinkingAnExistingChildWithoutChangingItIsNoWriteToIt(): void
    {
        $this->forbidOnChildTable('update');

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [['uid' => self::CHILD_UID]]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(['update tt_content'], $this->seen);
    }

    public function testAListenerCanChangeTheDataOfANestedChild(): void
    {
        $this->listen(function (BeforeRecordWriteEvent $event): void {
            if ($event->getTable() === self::CHILD_TABLE) {
                $event->setData(['title' => 'Title set by the listener'] + $event->getData());
            }
        });

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::CONTENT_UID,
            'data' => [self::FIELD => [['uid' => self::CHILD_UID, 'title' => 'Title sent by the client']]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $read = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => self::CHILD_TABLE, 'uid' => self::CHILD_UID]);
        $this->assertFalse($read->isError, json_encode($read->jsonSerialize()));
        $this->assertSame('Title set by the listener', json_decode($read->content[0]->text, true)['records'][0]['title']);
    }
}

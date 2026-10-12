<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Event;

use Hn\McpServer\Event\BeforeToolCallEvent;
use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stateful test listener for BeforeToolCallEvent
 */
class BeforeToolCallTestListener
{
    /** @var list<array{toolName: string, arguments: array}> */
    public static array $calls = [];
    public static ?string $vetoTool = null;
    public static string $vetoReason = '';

    public static function reset(): void
    {
        self::$calls = [];
        self::$vetoTool = null;
        self::$vetoReason = '';
    }

    public function __invoke(BeforeToolCallEvent $event): void
    {
        self::$calls[] = ['toolName' => $event->getToolName(), 'arguments' => $event->getArguments()];

        if ($event->getToolName() === self::$vetoTool) {
            $event->veto(self::$vetoReason);
        }
    }
}

/**
 * Tests for BeforeToolCallEvent
 */
class BeforeToolCallEventTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    protected function setUp(): void
    {
        parent::setUp();

        BeforeToolCallTestListener::reset();

        // Register the test listener in the DI container so ListenerProvider can resolve it
        $container = GeneralUtility::getContainer();
        $container->set(BeforeToolCallTestListener::class, new BeforeToolCallTestListener());
        $container->get(ListenerProvider::class)->addListener(BeforeToolCallEvent::class, BeforeToolCallTestListener::class);
    }

    private function countPagesWithTitle(string $title): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)))
            ->executeQuery()
            ->fetchOne();
    }

    #[Test]
    public function listenerReceivesToolNameAndArguments(): void
    {
        $result = (new ListTablesTool())->execute(['example' => 'value']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([['toolName' => 'ListTables', 'arguments' => ['example' => 'value']]], BeforeToolCallTestListener::$calls);
    }

    #[Test]
    public function vetoStopsTheCallBeforeTheToolRuns(): void
    {
        BeforeToolCallTestListener::$vetoTool = 'WriteTable';
        BeforeToolCallTestListener::$vetoReason = 'Writing is disabled by an example extension';

        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Vetoed tool call page'],
        ]);

        $this->assertToolError($result, 'Tool call vetoed: Writing is disabled by an example extension');
        $this->assertSame(0, $this->countPagesWithTitle('Vetoed tool call page'));
    }

    #[Test]
    public function vetoOfAnotherToolDoesNotAffectTheCall(): void
    {
        BeforeToolCallTestListener::$vetoTool = 'WriteTable';
        BeforeToolCallTestListener::$vetoReason = 'Writing is disabled by an example extension';

        $result = (new ListTablesTool())->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}

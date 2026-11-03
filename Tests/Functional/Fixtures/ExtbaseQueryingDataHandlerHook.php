<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Stand-in for third-party DataHandler hooks that query through an Extbase
 * repository after every write, as a cache extension does to find the cache
 * tags it has to flush. Building an Extbase query reads the framework
 * configuration from $GLOBALS['TYPO3_REQUEST'], and for a frontend request that
 * requires the frontend TypoScript the MCP routes never get. Registered in
 * TYPO3_CONF_VARS['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'] by the tests
 * that need it.
 */
class ExtbaseQueryingDataHandlerHook
{
    public static int $calls = 0;

    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        $this->runExtbaseQuery();
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        $this->runExtbaseQuery();
    }

    private function runExtbaseQuery(): void
    {
        self::$calls++;
        GeneralUtility::makeInstance(PersistenceManagerInterface::class)
            ->createQueryForType(Category::class)
            ->execute()
            ->count();
    }
}

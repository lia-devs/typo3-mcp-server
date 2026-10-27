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
 * DS-aware FlexForm write behavior against the test_flexform fixture
 * extension (CType `test_multisheetflex`, sheets sDEF + persistence).
 */
class WriteTableToolFlexFormTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        '../Tests/Functional/Fixtures/Extensions/test_flexform',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    /**
     * Test that FlexForm fields are stored in the sheet their DataStructure
     * declares them in — including non-`settings` subtrees (K26 scenario)
     */
    public function testFieldsAreStoredInTheirDataStructureSheets(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Multi sheet element',
                'pi_flexform' => [
                    'persistence' => ['storagePid' => '109,142'],
                    'settings' => ['contacts' => ''],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $flexForm = $this->parseStoredFlexForm($uid);

        $this->assertSame(
            '109,142',
            $flexForm['data']['persistence']['lDEF']['persistence.storagePid']['vDEF'] ?? null,
            'persistence.storagePid must be stored in the "persistence" sheet: ' . json_encode($flexForm)
        );
        $this->assertSame(
            '',
            $flexForm['data']['sDEF']['lDEF']['settings.contacts']['vDEF'] ?? null,
            'settings.contacts must be stored in the "sDEF" sheet: ' . json_encode($flexForm)
        );
    }

    /**
     * Test that a partial FlexForm update preserves all previously stored
     * fields (guards against the destructive whole-XML replace)
     */
    public function testPartialUpdatePreservesOtherFlexFormFields(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Partial update element',
                'pi_flexform' => [
                    'persistence' => ['storagePid' => '109'],
                    'settings' => ['contacts' => 'contact@example.com'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        // Update ONLY settings.sortOrder — everything else must survive
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => [
                'pi_flexform' => [
                    'settings' => ['sortOrder' => 'name'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $flexForm = $this->parseStoredFlexForm($uid);

        $this->assertSame(
            'name',
            $flexForm['data']['sDEF']['lDEF']['settings.sortOrder']['vDEF'] ?? null,
            'Updated field must be stored: ' . json_encode($flexForm)
        );
        $this->assertSame(
            'contact@example.com',
            $flexForm['data']['sDEF']['lDEF']['settings.contacts']['vDEF'] ?? null,
            'settings.contacts must survive the partial update: ' . json_encode($flexForm)
        );
        $this->assertSame(
            '109',
            $flexForm['data']['persistence']['lDEF']['persistence.storagePid']['vDEF'] ?? null,
            'persistence.storagePid must survive the partial update: ' . json_encode($flexForm)
        );
    }

    /**
     * Test that FlexForm fields the DataStructure does not declare are
     * rejected with an explicit error naming the field (no silent drop)
     */
    public function testUnknownFlexFormFieldReturnsExplicitError(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Unknown field element',
                'pi_flexform' => [
                    'nonexistent' => ['foo' => 'bar'],
                ],
            ],
        ]);

        $this->assertTrue($result->isError, 'Unknown FlexForm fields must produce an error');
        $message = $result->content[0]->text;
        $this->assertStringContainsString('Unknown FlexForm field', $message);
        $this->assertStringContainsString('nonexistent.foo', $message);
        $this->assertStringContainsString('persistence.storagePid', $message, 'Error must list the available fields');
    }

    /**
     * Test that raw FlexForm XML is refused without the explicit opt-in. It
     * replaces the whole stored structure, so every field it omits is erased —
     * silently, and reported as success. Nothing a caller should reach by
     * accident.
     */
    public function testRawFlexFormXmlIsRefusedWithoutOptIn(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Raw XML element',
                'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
                    . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                    . '<field index="settings.contacts"><value index="vDEF">x</value></field>'
                    . '</language></sheet></data></T3FlexForms>',
            ],
        ]);

        $this->assertTrue($result->isError, 'Raw FlexForm XML must be refused by default');
        $message = $result->content[0]->text;
        $this->assertStringContainsString('raw FlexForm XML string', $message);
        $this->assertStringContainsString('replaceFlexFormXml', $message, 'The error must name the opt-in');
    }

    /**
     * Test that the opt-in still allows the escape hatch — the documented way
     * to repair a stored value that no longer parses.
     */
    public function testRawFlexFormXmlIsAcceptedWithOptIn(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'replaceFlexFormXml' => true,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Raw XML element',
                'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
                    . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                    . '<field index="settings.contacts"><value index="vDEF">from raw xml</value></field>'
                    . '</language></sheet></data></T3FlexForms>',
            ],
        ]);

        $this->assertFalse($result->isError, $result->content[0]->text);

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $read = $readTool->execute(['table' => 'tt_content', 'where' => [['field' => 'header', 'operator' => '=', 'value' => 'Raw XML element']]]);
        $this->assertFalse($read->isError, $read->content[0]->text);
        $this->assertStringContainsString('from raw xml', $read->content[0]->text);
    }

    /**
     * Test that the opt-in does not survive into the next call. The tool
     * instance is shared between calls, so a `true` that leaked would silently
     * re-open the destructive path for every later caller.
     */
    public function testOptInDoesNotLeakIntoTheNextCall(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
            . '<field index="settings.contacts"><value index="vDEF">y</value></field>'
            . '</language></sheet></data></T3FlexForms>';

        $allowed = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'replaceFlexFormXml' => true,
            'data' => ['CType' => 'test_multisheetflex', 'header' => 'First', 'pi_flexform' => $xml],
        ]);
        $this->assertFalse($allowed->isError, $allowed->content[0]->text);

        $refused = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'test_multisheetflex', 'header' => 'Second', 'pi_flexform' => $xml],
        ]);
        $this->assertTrue($refused->isError, 'The opt-in must not carry over to a call that did not ask for it');
        $this->assertStringContainsString('replaceFlexFormXml', $refused->content[0]->text);
    }

    /**
     * Test the K26 scenario end to end: a non-`settings` subtree written as
     * JSON comes back as the same nested JSON on read (write→read round trip)
     */
    public function testFlexFormWriteReadRoundTrip(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_multisheetflex',
                'header' => 'Round trip element',
                'pi_flexform' => [
                    'persistence' => ['storagePid' => '109,142'],
                    'settings' => ['contacts' => 'contact@example.com'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];

        $this->assertEquals(
            [
                'persistence' => ['storagePid' => '109,142'],
                'settings' => ['contacts' => 'contact@example.com'],
            ],
            $record['pi_flexform'],
            'FlexForm must round-trip as nested JSON across all sheets'
        );
    }

    /**
     * Test that reading a record whose stored FlexForm XML is unparseable
     * produces an explicit error instead of a silently empty value
     */
    public function testUnparseableStoredFlexFormReturnsReadError(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->insert('tt_content', [
                'uid' => 900,
                'pid' => 1,
                'CType' => 'test_multisheetflex',
                'header' => 'Broken FlexForm element',
                'pi_flexform' => '<?xml version="1.0"?><T3FlexForms><not-closed>',
                'colPos' => 0,
                'sorting' => 256,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 900,
        ]);

        $this->assertTrue($result->isError, 'Unparseable stored FlexForm XML must produce a read error');
        $this->assertStringContainsString('FlexForm', $result->content[0]->text);
    }

    /**
     * Fetch and parse the stored FlexForm XML of the record (workspace
     * version preferred — MCP writes always target a workspace).
     */
    private function parseStoredFlexForm(int $liveUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 'pi_flexform', 't3ver_wsid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, \Doctrine\DBAL\ParameterType::INTEGER))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertNotEmpty($rows, 'Record not found: ' . $liveUid);

        // Prefer the workspace version over the live row
        usort($rows, static fn(array $a, array $b): int => (int)$b['t3ver_wsid'] <=> (int)$a['t3ver_wsid']);
        $xml = (string)$rows[0]['pi_flexform'];
        $this->assertNotSame('', $xml, 'Stored FlexForm XML is empty');

        $parsed = GeneralUtility::xml2array($xml);
        $this->assertIsArray($parsed, 'Stored FlexForm XML is not parseable: ' . $xml);

        return $parsed;
    }
}

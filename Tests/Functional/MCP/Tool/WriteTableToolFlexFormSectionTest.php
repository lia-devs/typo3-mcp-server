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
 * FlexForm sections (repeatable item lists) against the test_flexform fixture
 * extension (CType `test_sectionflex`): `settings.teasers` with two named
 * containers in sheet sDEF, and an ext:form-like recipient list with a single
 * `_arrayContainer` in sheet `mail`.
 *
 * A section is stored as `el` → item id → container → `el` → subfield → `vDEF`,
 * not as a `vDEF` value. A partial patch that only carried `vDEF` entries over
 * erased every section of the record, and section values could not be written
 * at all.
 */
class WriteTableToolFlexFormSectionTest extends FunctionalTestCase
{
    private const SEEDED_UID = 910;

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

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->insert('tt_content', [
                'uid' => self::SEEDED_UID,
                'pid' => 1,
                'CType' => 'test_sectionflex',
                'header' => 'Section element',
                'pi_flexform' => $this->seededFlexFormXml(),
                'colPos' => 0,
                'sorting' => 256,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
    }

    #[Test]
    public function patchingAnotherFieldKeepsTheStoredSectionsUnchanged(): void
    {
        $seeded = GeneralUtility::xml2array($this->seededFlexFormXml());

        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SEEDED_UID,
            'data' => ['pi_flexform' => ['settings' => ['subject' => 'New subject']]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $stored = $this->parseStoredFlexForm(self::SEEDED_UID);
        $this->assertSame('New subject', $stored['data']['sDEF']['lDEF']['settings.subject']['vDEF']);
        $this->assertSame(
            $seeded['data']['sDEF']['lDEF']['settings.teasers'],
            $stored['data']['sDEF']['lDEF']['settings.teasers']
        );
        $this->assertSame(
            $seeded['data']['mail']['lDEF']['settings.finishers.EmailToReceiver.recipients'],
            $stored['data']['mail']['lDEF']['settings.finishers.EmailToReceiver.recipients']
        );
    }

    #[Test]
    public function readListsTheSectionItemsInStoredOrder(): void
    {
        $this->assertSame(
            [
                'settings' => [
                    'subject' => 'Contact request',
                    'teasers' => [
                        ['link' => ['url' => 'https://example.com']],
                        ['text' => ['headline' => 'Hello', 'bodytext' => 'World']],
                    ],
                    'finishers' => [
                        'EmailToReceiver' => [
                            'recipients' => [
                                ['email' => 'first@example.com', 'name' => 'First'],
                                ['email' => 'second@example.com', 'name' => 'Second'],
                            ],
                        ],
                    ],
                ],
            ],
            $this->readFlexForm(self::SEEDED_UID)
        );
    }

    #[Test]
    public function aSectionReadFromReadTableIsAcceptedByWriteTable(): void
    {
        $flexForm = $this->readFlexForm(self::SEEDED_UID);
        $flexForm['settings']['finishers']['EmailToReceiver']['recipients'][1]['name'] = 'Second renamed';
        $flexForm['settings']['finishers']['EmailToReceiver']['recipients'][] = ['email' => 'third@example.com', 'name' => 'Third'];

        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SEEDED_UID,
            'data' => ['pi_flexform' => $flexForm],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame($flexForm, $this->readFlexForm(self::SEEDED_UID));
    }

    #[Test]
    public function aWrittenSectionReusesTheStoredItemIdsByPosition(): void
    {
        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SEEDED_UID,
            'data' => ['pi_flexform' => ['settings' => ['finishers' => ['EmailToReceiver' => ['recipients' => [
                ['email' => 'a@example.com', 'name' => 'A'],
                ['email' => 'b@example.com', 'name' => 'B'],
                ['email' => 'c@example.com', 'name' => 'C'],
            ]]]]]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $items = $this->parseStoredFlexForm(self::SEEDED_UID)['data']['mail']['lDEF']['settings.finishers.EmailToReceiver.recipients']['el'];
        $ids = array_map('strval', array_keys($items));
        $this->assertSame(['5f1a2b', '5f1a2c'], array_slice($ids, 0, 2));
        $this->assertCount(3, array_unique($ids));
        $this->assertSame(
            [
                ['_arrayContainer' => ['el' => ['email' => ['vDEF' => 'a@example.com'], 'name' => ['vDEF' => 'A']]]],
                ['_arrayContainer' => ['el' => ['email' => ['vDEF' => 'b@example.com'], 'name' => ['vDEF' => 'B']]]],
                ['_arrayContainer' => ['el' => ['email' => ['vDEF' => 'c@example.com'], 'name' => ['vDEF' => 'C']]]],
            ],
            array_values($items)
        );
    }

    #[Test]
    public function creatingARecordStoresEachSectionInTheSheetItsDataStructureDeclares(): void
    {
        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'test_sectionflex',
                'header' => 'Created with sections',
                'pi_flexform' => ['settings' => [
                    'teasers' => [['text' => ['headline' => 'Only teaser']]],
                    'finishers' => ['EmailToReceiver' => ['recipients' => [['email' => 'x@example.com', 'name' => 'X']]]],
                ]],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $stored = $this->parseStoredFlexForm($uid);
        $this->assertSame(
            [['text' => ['el' => ['headline' => ['vDEF' => 'Only teaser']]]]],
            array_values($stored['data']['sDEF']['lDEF']['settings.teasers']['el'])
        );
        $this->assertSame(
            [['_arrayContainer' => ['el' => ['email' => ['vDEF' => 'x@example.com'], 'name' => ['vDEF' => 'X']]]]],
            array_values($stored['data']['mail']['lDEF']['settings.finishers.EmailToReceiver.recipients']['el'])
        );
    }

    #[Test]
    public function anEmptyListRemovesAllItemsOfASection(): void
    {
        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SEEDED_UID,
            'data' => ['pi_flexform' => ['settings' => ['teasers' => []]]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $flexForm = $this->readFlexForm(self::SEEDED_UID);
        $this->assertSame([], $flexForm['settings']['teasers']);
        $this->assertSame(
            [
                ['email' => 'first@example.com', 'name' => 'First'],
                ['email' => 'second@example.com', 'name' => 'Second'],
            ],
            $flexForm['settings']['finishers']['EmailToReceiver']['recipients']
        );
    }

    #[Test]
    public function aSectionKeyedByItemIdsIsRejected(): void
    {
        $message = $this->writeRejected(['settings' => ['finishers' => ['EmailToReceiver' => ['recipients' => [
            '5f1a2b' => ['email' => 'first@example.com', 'name' => 'First'],
        ]]]]]);

        $this->assertStringContainsString('settings.finishers.EmailToReceiver.recipients', $message);
        $this->assertStringContainsString('must be a JSON list', $message);
    }

    #[Test]
    public function anUnknownSubfieldIsRejectedWithTheDeclaredSubfields(): void
    {
        $message = $this->writeRejected(['settings' => ['finishers' => ['EmailToReceiver' => ['recipients' => [
            ['mail' => 'first@example.com'],
        ]]]]]);

        $this->assertStringContainsString('Unknown subfield "mail"', $message);
        $this->assertStringContainsString('email, name', $message);
    }

    #[Test]
    public function anItemWithoutExactlyOneDeclaredContainerIsRejected(): void
    {
        $unnamed = $this->writeRejected(['settings' => ['teasers' => [['url' => 'https://example.com']]]]);
        $this->assertStringContainsString('Unknown container "url"', $unnamed);
        $this->assertStringContainsString('link, text', $unnamed);

        $twoContainers = $this->writeRejected(['settings' => ['teasers' => [
            ['link' => ['url' => 'https://example.com'], 'text' => ['headline' => 'Hello']],
        ]]]);
        $this->assertStringContainsString('exactly one container', $twoContainers);
    }

    #[Test]
    public function aNestedSubfieldValueIsRejected(): void
    {
        $message = $this->writeRejected(['settings' => ['teasers' => [['link' => ['url' => ['href' => 'x']]]]]]);

        $this->assertStringContainsString('Subfield "url"', $message);
        $this->assertStringContainsString('scalar', $message);
    }

    /**
     * A rejected write must leave the stored value untouched.
     */
    private function writeRejected(array $flexForm): string
    {
        $result = (new WriteTableTool())->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SEEDED_UID,
            'data' => ['pi_flexform' => $flexForm],
        ]);
        $this->assertTrue($result->isError, 'The write must be rejected: ' . json_encode($flexForm));
        $this->assertSame(
            GeneralUtility::xml2array($this->seededFlexFormXml()),
            $this->parseStoredFlexForm(self::SEEDED_UID)
        );

        return $result->content[0]->text;
    }

    private function readFlexForm(int $uid): array
    {
        $result = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return json_decode($result->content[0]->text, true)['records'][0]['pi_flexform'];
    }

    /**
     * Stored FlexForm of the record, the workspace version preferred — MCP
     * writes always target a workspace.
     */
    private function parseStoredFlexForm(int $liveUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('pi_flexform', 't3ver_wsid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, \Doctrine\DBAL\ParameterType::INTEGER))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
        usort($rows, static fn(array $a, array $b): int => (int)$b['t3ver_wsid'] <=> (int)$a['t3ver_wsid']);

        $parsed = GeneralUtility::xml2array((string)$rows[0]['pi_flexform']);
        $this->assertIsArray($parsed);

        return $parsed;
    }

    /**
     * Stored the way FormEngine stores it, including the `_TOGGLE` state of
     * an item that a write must carry over untouched.
     */
    private function seededFlexFormXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data>'
            . '<sheet index="sDEF"><language index="lDEF">'
            . '<field index="settings.subject"><value index="vDEF">Contact request</value></field>'
            . '<field index="settings.teasers"><el index="el">'
            . '<field index="7c01"><value index="link"><el index="el">'
            . '<field index="url"><value index="vDEF">https://example.com</value></field>'
            . '</el></value><value index="_TOGGLE">1</value></field>'
            . '<field index="7c02"><value index="text"><el index="el">'
            . '<field index="headline"><value index="vDEF">Hello</value></field>'
            . '<field index="bodytext"><value index="vDEF">World</value></field>'
            . '</el></value></field>'
            . '</el></field>'
            . '</language></sheet>'
            . '<sheet index="mail"><language index="lDEF">'
            . '<field index="settings.finishers.EmailToReceiver.recipients"><el index="el">'
            . '<field index="5f1a2b"><value index="_arrayContainer"><el index="el">'
            . '<field index="email"><value index="vDEF">first@example.com</value></field>'
            . '<field index="name"><value index="vDEF">First</value></field>'
            . '</el></value><value index="_TOGGLE">0</value></field>'
            . '<field index="5f1a2c"><value index="_arrayContainer"><el index="el">'
            . '<field index="email"><value index="vDEF">second@example.com</value></field>'
            . '<field index="name"><value index="vDEF">Second</value></field>'
            . '</el></value></field>'
            . '</el></field>'
            . '</language></sheet>'
            . '</data></T3FlexForms>';
    }
}

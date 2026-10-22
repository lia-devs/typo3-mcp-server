<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\FlexFormStructureService;
use Hn\McpServer\Tests\Functional\Traits\PluginContentTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class FlexFormStructureServiceTest extends FunctionalTestCase
{
    use PluginContentTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
        '../Tests/Functional/Fixtures/Extensions/test_flexform',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test DataStructure resolution by identifier (news list plugin)
     */
    public function testResolveDataStructureByIdentifier(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $structure = $service->resolveDataStructure('tt_content', 'pi_flexform', 'news_pi1', null);

        $this->assertIsArray($structure);
        $this->assertArrayHasKey('sheets', $structure);
        $this->assertArrayHasKey('sDEF', $structure['sheets']);
        $this->assertArrayHasKey('additional', $structure['sheets']);
        $this->assertArrayHasKey('template', $structure['sheets']);
        $this->assertArrayHasKey('settings.orderBy', $structure['sheets']['sDEF']['ROOT']['el']);
    }

    /**
     * Test that unknown identifiers do not resolve (no default-DS fallback)
     */
    public function testResolveDataStructureReturnsNullForUnknownIdentifier(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $structure = $service->resolveDataStructure('tt_content', 'pi_flexform', 'unknown_flexform_identifier', null);

        $this->assertNull($structure);
    }

    /**
     * Test DataStructure resolution from an actual record row in the shape
     * the running TYPO3 version stores plugins in (legacy `CType=list` rows
     * on v13 must not end up on the default-DS fallback)
     */
    public function testResolveDataStructureForRecord(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $row = $this->buildPluginContentRow('news_pi1', ['pi_flexform' => '']);
        $structure = $service->resolveDataStructureForRecord('tt_content', 'pi_flexform', $row);

        $this->assertIsArray($structure);
        $this->assertArrayHasKey('sDEF', $structure['sheets']);
        $this->assertArrayHasKey('additional', $structure['sheets']);
    }

    /**
     * Test DataStructure resolution from a CType-registered plugin row
     */
    public function testResolveDataStructureForRecordWithCTypeRow(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $row = ['CType' => 'news_pi1', 'list_type' => '', 'pi_flexform' => ''];
        $structure = $service->resolveDataStructureForRecord('tt_content', 'pi_flexform', $row);

        $this->assertIsArray($structure);
        $this->assertArrayHasKey('sDEF', $structure['sheets']);
        $this->assertArrayHasKey('additional', $structure['sheets']);
    }

    /**
     * Test that a record type's own DataStructure is resolved, whichever place
     * the running core keeps it in. The `test_multisheetflex` fixture registers
     * through ExtensionManagementUtility::addPiFlexFormValue(), which writes
     * `columns.pi_flexform.config.ds['*,<CType>']` on TYPO3 13 and
     * `types.<CType>.columnsOverrides.pi_flexform.config.ds` on TYPO3 14 — the
     * latter is resolvable only when FlexFormTools gets the table's TcaSchema.
     *
     * Unlike the news-based tests above this uses an in-repo fixture, so the
     * coverage does not depend on how a third-party extension registers.
     */
    public function testResolveDataStructureForRecordWithRecordTypeOwnedDataStructure(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $row = ['CType' => 'test_multisheetflex', 'list_type' => '', 'pi_flexform' => ''];
        $structure = $service->resolveDataStructureForRecord('tt_content', 'pi_flexform', $row);

        $this->assertIsArray($structure);
        $this->assertSame([
            'settings.contacts' => ['sDEF'],
            'settings.sortOrder' => ['sDEF'],
            'persistence.storagePid' => ['persistence'],
        ], $service->getFieldSheetMap($structure));
    }

    /**
     * Test the same DataStructure resolved from the identifier alone, the path
     * GetFlexFormSchema takes when no record uid is given
     */
    public function testResolveDataStructureByIdentifierWithRecordTypeOwnedDataStructure(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $structure = $service->resolveDataStructure('tt_content', 'pi_flexform', 'test_multisheetflex', null);

        $this->assertIsArray($structure);
        $this->assertSame([
            'settings.contacts' => ['sDEF'],
            'settings.sortOrder' => ['sDEF'],
            'persistence.storagePid' => ['persistence'],
        ], $service->getFieldSheetMap($structure));
    }

    /**
     * Test the field→sheet map used for DS-aware sheet placement on write
     */
    public function testGetFieldSheetMap(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $structure = $service->resolveDataStructure('tt_content', 'pi_flexform', 'news_pi1', null);
        $this->assertIsArray($structure);

        $map = $service->getFieldSheetMap($structure);

        $this->assertSame(['sDEF'], $map['settings.orderBy']);
        $this->assertSame(['additional'], $map['settings.detailPid']);
        $this->assertSame(['template'], $map['settings.media.maxWidth']);
        // Every mapped field belongs to at least one sheet
        foreach ($map as $fieldName => $sheets) {
            $this->assertNotEmpty($sheets, "Field '$fieldName' has no sheet");
        }
    }

    /**
     * Test precondition: non-FlexForm fields are rejected
     */
    public function testResolveDataStructureRejectsNonFlexField(): void
    {
        $service = GeneralUtility::makeInstance(FlexFormStructureService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1752489601);

        $service->resolveDataStructure('tt_content', 'header', 'news_pi1', null);
    }
}

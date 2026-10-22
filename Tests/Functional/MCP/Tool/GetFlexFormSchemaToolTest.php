<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Mcp\Types\TextContent;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetFlexFormSchemaToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'news',  // Add News extension for success test cases
        'mcp_server',
        '../Tests/Functional/Fixtures/Extensions/test_flexform',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test basic FlexForm schema retrieval with nonexistent identifier
     */
    public function testGetFlexFormSchema(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Test with form_formframework (likely not available in test environment)
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework'
        ]);
        
        // The tool returns an error when FlexForm identifier is not found
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify error message
        $this->assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
    }

    /**
     * Test FlexForm schema with default parameters
     */
    public function testGetFlexFormSchemaWithDefaults(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Test with only identifier (defaults to tt_content.pi_flexform)
        $result = $tool->execute([
            'identifier' => 'form_formframework'
        ]);
        
        // The tool returns an error when FlexForm identifier is not found
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Verify error message contains transformed identifier
        $this->assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
    }

    /**
     * Test FlexForm schema with custom table and field
     */
    public function testGetFlexFormSchemaWithCustomTableAndField(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Test with custom table and field
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'textmedia'
        ]);
        
        // The tool returns an error when FlexForm identifier is not found
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Verify error message
        $this->assertStringContainsString('FlexForm schema not found for identifier: textmedia', $content);
    }

    /**
     * Test error handling for missing identifier parameter
     */
    public function testMissingIdentifierParameter(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Identifier parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for empty identifier parameter
     */
    public function testEmptyIdentifierParameter(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => ''
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Identifier parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for invalid table
     */
    public function testInvalidTable(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'nonexistent_table',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Cannot access table \'nonexistent_table\': Table does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test error handling for table without TCA
     */
    public function testTableWithoutTca(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'sys_log',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Cannot access table \'sys_log\': Table does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test FlexForm schema with unknown identifier
     */
    public function testUnknownIdentifier(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'unknown_flexform_identifier'
        ]);
        
        // Should return error for unknown identifier
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Should show error message
        $this->assertStringContainsString('FlexForm schema not found for identifier: unknown_flexform_identifier', $content);
    }

    /**
     * Test FlexForm schema output format when identifier not found
     */
    public function testFlexFormSchemaOutputFormat(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'identifier' => 'form_formframework'
        ]);
        
        // The tool returns an error when FlexForm identifier is not found
        $this->assertTrue($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify error message
        $this->assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
    }

    /**
     * Test FlexForm schema with field that exists but is not FlexForm
     */
    public function testNonFlexFormField(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'header', // This is not a FlexForm field
            'identifier' => 'form_formframework'
        ]);
        
        // Should return error for non-FlexForm field
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Should show error message
        $this->assertStringContainsString('Field \'header\' in table \'tt_content\' is not a FlexForm field', $content);
    }

    /**
     * Test FlexForm schema field information
     */
    public function testFlexFormSchemaFieldInformation(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $result = $tool->execute([
            'identifier' => 'form_formframework'
        ]);
        
        // The tool returns an error when FlexForm identifier is not found
        $this->assertTrue($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify error message
        $this->assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
    }

    /**
     * Test workspace context initialization
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Should work in workspace context but still return error for missing identifier
        $result = $tool->execute([
            'identifier' => 'form_formframework'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $result->content[0]->text);
    }

    /**
     * Test tool schema contains required information
     */
    public function testToolSchema(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        $schema = $tool->getSchema();
        
        // Verify schema structure
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('inputSchema', $schema);
        
        // Verify parameters
        $this->assertArrayHasKey('properties', $schema['inputSchema']);
        $this->assertArrayHasKey('identifier', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('table', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('field', $schema['inputSchema']['properties']);
        
        // Verify required fields
        $this->assertArrayHasKey('required', $schema['inputSchema']);
        $this->assertContains('identifier', $schema['inputSchema']['required']);
    }

    /**
     * Test successful FlexForm schema retrieval with News plugin
     */
    public function testGetFlexFormSchemaSuccess(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Test with News plugin identifier
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'news_pi1'
        ]);
        
        // Should succeed with News extension loaded
        $this->assertFalse($result->isError, 'Should successfully retrieve News FlexForm schema');
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify schema structure
        $this->assertStringContainsString('FLEXFORM SCHEMA: news_pi1', $content);
        $this->assertStringContainsString('Table: tt_content', $content);
        $this->assertStringContainsString('Field: pi_flexform', $content);

        // Verify sheets are present (resolved via FlexFormTools, so the DS
        // identifier events have run — no raw file path is exposed anymore)
        $this->assertStringContainsString('SHEETS:', $content);
        $this->assertStringContainsString('Sheet: sDEF', $content);
        $this->assertStringContainsString('Sheet: additional', $content);
        $this->assertStringContainsString('Sheet: template', $content);
        
        // Verify key fields are present
        $this->assertStringContainsString('settings.orderBy', $content);
        $this->assertStringContainsString('settings.orderDirection', $content);
        $this->assertStringContainsString('settings.categories', $content);
        $this->assertStringContainsString('settings.detailPid', $content);
        $this->assertStringContainsString('settings.listPid', $content);
        $this->assertStringContainsString('settings.limit', $content);
        
        // Verify JSON structure example is present
        $this->assertStringContainsString('JSON STRUCTURE:', $content);
        $this->assertStringContainsString('"pi_flexform": {', $content);
        
        // Verify the dot notation conversion note is present
        $this->assertStringContainsString('Field names with dots', $content);
        $this->assertStringContainsString('converted to nested structures', $content);
    }
    
    /**
     * Test FlexForm schema with different News plugin types
     */
    public function testGetFlexFormSchemaWithDifferentNewsTypes(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // Test category list FlexForm
        $result = $tool->execute([
            'identifier' => 'news_categorylist'
        ]);
        
        $this->assertFalse($result->isError, 'Should successfully retrieve News category list FlexForm schema');
        $content = $result->content[0]->text;
        
        $this->assertStringContainsString('FLEXFORM SCHEMA: news_categorylist', $content);
        $this->assertStringContainsString('settings.', $content);

        // Test detail view FlexForm
        $result = $tool->execute([
            'identifier' => 'news_newsdetail'
        ]);
        
        $this->assertFalse($result->isError, 'Should successfully retrieve News detail FlexForm schema');
        $content = $result->content[0]->text;
        
        $this->assertStringContainsString('FLEXFORM SCHEMA: news_newsdetail', $content);
        $this->assertStringContainsString('settings.', $content);
    }
    
    /**
     * Test FlexForm schema parameter handling with recordUid
     */
    public function testGetFlexFormSchemaWithRecordUid(): void
    {
        $tool = new GetFlexFormSchemaTool();
        
        // recordUid is used to load the real record for DataStructure resolution;
        // a non-existing UID must not break resolution via the identifier.
        $result = $tool->execute([
            'identifier' => 'news_pi1',
            'recordUid' => 123  // Does not exist in this test setup
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should still retrieve the schema successfully (recordUid 123 does not
        // exist, so resolution falls back to the synthesized identifier row)
        $this->assertStringContainsString('FLEXFORM SCHEMA: news_pi1', $content);
        $this->assertStringContainsString('settings.', $content);
    }

    /**
     * Test that a multi-sheet FlexForm with a non-`settings` subtree lists
     * all sheets and their fields (test_flexform fixture extension)
     */
    public function testGetFlexFormSchemaListsAllSheetsOfMultiSheetFlexForm(): void
    {
        $tool = new GetFlexFormSchemaTool();

        $result = $tool->execute([
            'identifier' => 'test_multisheetflex',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertStringContainsString('FLEXFORM SCHEMA: test_multisheetflex', $content);
        $this->assertStringContainsString('SHEETS:', $content);
        $this->assertStringContainsString('Sheet: sDEF', $content);
        $this->assertStringContainsString('Sheet: persistence', $content);
        $this->assertStringContainsString('settings.contacts', $content);
        $this->assertStringContainsString('settings.sortOrder', $content);
        $this->assertStringContainsString('persistence.storagePid', $content);
    }

    /**
     * A section is a repeatable item list. The schema must describe the item
     * shape WriteTable accepts instead of reporting the section as "unknown".
     */
    #[Test]
    public function sectionsDescribeTheirItemShape(): void
    {
        $result = (new GetFlexFormSchemaTool())->execute([
            'identifier' => 'test_sectionflex',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertStringContainsString(
            "  - settings.teasers (Teasers): section\n"
            . "    JSON Path: pi_flexform.settings.teasers\n"
            . '    Value: JSON list of items, each {"<container>": {"<subfield>": "<value>"}} with exactly one container; the list replaces all stored items' . "\n"
            . "    Container: link (Link teaser)\n"
            . "      - url (URL): input\n"
            . "    Container: text (Text teaser)\n"
            . "      - headline (Headline): input\n"
            . "      - bodytext (Text): text\n",
            $content
        );
        $this->assertStringContainsString(
            "  - settings.finishers.EmailToReceiver.recipients (Recipients): section\n"
            . "    JSON Path: pi_flexform.settings.finishers.EmailToReceiver.recipients\n"
            . '    Value: JSON list of items, each {"<subfield>": "<value>"}; the list replaces all stored items' . "\n"
            . "    Item subfields:\n"
            . "      - email (Email): input\n"
            . "      - name (Name): input\n",
            $content
        );

        $jsonExample = json_decode(substr($content, strpos($content, '{', strpos($content, 'JSON STRUCTURE:')), strrpos($content, '}') - strpos($content, '{', strpos($content, 'JSON STRUCTURE:')) + 1), true);
        $this->assertSame(
            ['pi_flexform' => ['settings' => [
                'subject' => '<subject value>',
                'teasers' => [['link' => ['url' => '<url value>']]],
                'finishers' => ['EmailToReceiver' => ['recipients' => [['email' => '<email value>', 'name' => '<name value>']]]],
            ]]],
            $jsonExample
        );
    }

}

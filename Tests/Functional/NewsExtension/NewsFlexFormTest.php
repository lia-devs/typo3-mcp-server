<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\Traits\PluginContentTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test FlexForm handling with News plugin
 */
class NewsFlexFormTest extends FunctionalTestCase
{
    use PluginContentTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test creating a News plugin with comprehensive FlexForm settings
     */
    public function testCreateNewsPluginWithFlexForm(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create a News plugin with extensive FlexForm configuration
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'Latest News',
                'pi_flexform' => [
                    'settings' => [
                        // Display settings
                        'orderBy' => 'datetime',
                        'orderDirection' => 'desc',
                        'topNewsFirst' => '1',
                        'limit' => '10',
                        'offset' => '0',
                        'hidePagination' => '0',
                        
                        // Page references
                        'detailPid' => '20',
                        'listPid' => '15',
                        'backPid' => '1',
                        'startingpoint' => '10',
                        'recursive' => '2',
                        
                        // Category settings
                        'categories' => '1,2',
                        'categoryConjunction' => 'or',
                        'includeSubCategories' => '1',
                        
                        // Date and archive settings
                        'dateField' => 'datetime',
                        'archiveRestriction' => 'active',
                        'timeRestriction' => '2678400', // 31 days
                        'timeRestrictionHigh' => '0',
                        
                        // Template settings
                        'templateLayout' => '100',
                        'media' => [
                            'maxWidth' => '800',
                            'maxHeight' => '600'
                        ]
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Read the plugin back
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify basic fields. CType differs by TYPO3 version: v13 stores
        // plugins as CType=list with list_type pointing at the plugin.
        $expectedCType = TableAccessService::hasPluginSubtypes() ? 'list' : 'news_pi1';
        $this->assertEquals($expectedCType, $plugin['CType']);
        if (TableAccessService::hasPluginSubtypes()) {
            $this->assertEquals('news_pi1', $plugin['list_type'] ?? null);
        }
        $this->assertEquals('Latest News', $plugin['header']);
        
        // Verify FlexForm was converted from array and stored
        $this->assertArrayHasKey('pi_flexform', $plugin);
        $this->assertIsArray($plugin['pi_flexform']);
        
        // Verify FlexForm settings were preserved
        $this->assertArrayHasKey('settings', $plugin['pi_flexform']);
        $settings = $plugin['pi_flexform']['settings'];
        
        // Check display settings
        $this->assertEquals('datetime', $settings['orderBy']);
        $this->assertEquals('desc', $settings['orderDirection']);
        $this->assertEquals('1', $settings['topNewsFirst']);
        $this->assertEquals('10', $settings['limit']);
        
        // Check page references
        $this->assertEquals('20', $settings['detailPid']);
        $this->assertEquals('15', $settings['listPid']);
        $this->assertEquals('10', $settings['startingpoint']);
        
        // Check category settings
        $this->assertEquals('1,2', $settings['categories']);
        $this->assertEquals('or', $settings['categoryConjunction']);
        
        // Check nested media settings
        $this->assertArrayHasKey('media', $settings);
        $this->assertEquals('800', $settings['media']['maxWidth']);
        $this->assertEquals('600', $settings['media']['maxHeight']);
    }

    /**
     * Test updating News plugin FlexForm settings
     */
    public function testUpdateNewsPluginFlexForm(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // First create a News plugin
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'News to Update',
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'title',
                        'limit' => '5',
                        'categories' => '1'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update the FlexForm settings
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'datetime',
                        'orderDirection' => 'asc',
                        'limit' => '20',
                        'categories' => '1,2,3',
                        'categoryConjunction' => 'and',
                        'detailPid' => '25',
                        'templateLayout' => '200'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Read and verify the update
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);
        
        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];
        
        // Verify updates
        $this->assertEquals('datetime', $settings['orderBy']);
        $this->assertEquals('asc', $settings['orderDirection']);
        $this->assertEquals('20', $settings['limit']);
        $this->assertEquals('1,2,3', $settings['categories']);
        $this->assertEquals('and', $settings['categoryConjunction']);
        $this->assertEquals('25', $settings['detailPid']);
        $this->assertEquals('200', $settings['templateLayout']);
    }

    /**
     * Test different News plugin modes
     */
    public function testDifferentNewsPluginModes(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        // Every field must be declared by the news list DataStructure —
        // undeclared FlexForm fields (like the legacy
        // switchableControllerActions) are rejected explicitly since the
        // DS-aware write path.
        $modes = [
            'List' => [
                'limit' => '10',
                'orderBy' => 'datetime'
            ],
            'Ordered' => [
                'orderBy' => 'title',
                'orderDirection' => 'asc'
            ],
            'Categorized' => [
                'categories' => '1,2',
                'categoryConjunction' => 'or'
            ],
            'Paged' => [
                'hidePagination' => '1',
                'listPid' => '15'
            ]
        ];
        
        foreach ($modes as $modeName => $modeSettings) {
            // Create plugin with specific mode
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => 1,
                'data' => [
                    ...$this->buildPluginContentRow('news_pi1'),
                    'header' => "News Plugin - $modeName Mode",
                    'pi_flexform' => [
                        'settings' => $modeSettings
                    ]
                ],
            ]);
            
            $this->assertFalse($result->isError, "Failed to create $modeName mode: " . json_encode($result->jsonSerialize()));
            
            // Read back and verify
            $pluginUid = json_decode($result->content[0]->text, true)['uid'];
            $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
            $result = $readTool->execute([
                'table' => 'tt_content',
                'uid' => $pluginUid,
            ]);
            
            $plugin = json_decode($result->content[0]->text, true)['records'][0];
            $this->assertArrayHasKey('pi_flexform', $plugin);
            $this->assertArrayHasKey('settings', $plugin['pi_flexform']);
            
            // Verify mode-specific settings
            foreach ($modeSettings as $key => $value) {
                $this->assertEquals($value, $plugin['pi_flexform']['settings'][$key], 
                    "Setting $key not preserved for $modeName mode");
            }
        }
    }

    /**
     * Test empty FlexForm handling
     */
    public function testEmptyFlexFormHandling(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create plugin with empty FlexForm
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'News Plugin with Empty FlexForm',
                'pi_flexform' => []
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update with empty settings
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => []
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test GetFlexFormSchemaTool integration
     */
    public function testGetFlexFormSchemaToolIntegration(): void
    {
        // First create a News plugin
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'News Plugin for Schema Test'
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Get FlexForm schema
        $schemaTool = GeneralUtility::makeInstance(GetFlexFormSchemaTool::class);
        $result = $schemaTool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'recordUid' => $pluginUid,
            'identifier' => $this->pluginFlexFormIdentifier('news_pi1'),
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify schema contains News-specific settings
        $this->assertStringContainsString('orderBy', $content);
        $this->assertStringContainsString('orderDirection', $content);
        $this->assertStringContainsString('categories', $content);
        $this->assertStringContainsString('detailPid', $content);
        $this->assertStringContainsString('listPid', $content);
        
        // Check for sheet structure
        $this->assertStringContainsString('SHEETS:', $content);
    }

    /**
     * Test workspace handling for FlexForm updates
     */
    public function testFlexFormWorkspaceHandling(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create plugin in workspace
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'Workspace FlexForm Test',
                'pi_flexform' => [
                    'settings' => [
                        'limit' => '5',
                        'orderBy' => 'title'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update in workspace
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => [
                        'limit' => '15',
                        'orderBy' => 'datetime',
                        'orderDirection' => 'desc'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify workspace version has the updates
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);
        
        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];
        
        $this->assertEquals('15', $settings['limit']);
        $this->assertEquals('datetime', $settings['orderBy']);
        $this->assertEquals('desc', $settings['orderDirection']);
    }

    /**
     * Test complex nested FlexForm structures
     */
    public function testComplexNestedFlexFormStructures(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create plugin with nested structures — every dotted path must be
        // declared by the news DataStructure (undeclared FlexForm fields are
        // rejected with an explicit error since the DS-aware write path)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                ...$this->buildPluginContentRow('news_pi1'),
                'header' => 'Complex FlexForm Test',
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'datetime',
                        'limit' => '10',
                        // Nested media configuration (settings.media.maxWidth/maxHeight)
                        'media' => [
                            'maxWidth' => '1200',
                            'maxHeight' => '800',
                        ],
                        // Three-level nesting (settings.list.paginate.itemsPerPage)
                        'list' => [
                            'paginate' => [
                                'itemsPerPage' => '10',
                            ],
                        ],
                    ]
                ]
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Read and verify nested structures
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);

        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];

        // Verify nesting
        $this->assertArrayHasKey('media', $settings);
        $this->assertEquals('1200', $settings['media']['maxWidth']);
        $this->assertEquals('800', $settings['media']['maxHeight']);

        // Verify three-level nesting
        $this->assertArrayHasKey('list', $settings);
        $this->assertArrayHasKey('paginate', $settings['list']);
        $this->assertEquals('10', $settings['list']['paginate']['itemsPerPage']);
    }
}
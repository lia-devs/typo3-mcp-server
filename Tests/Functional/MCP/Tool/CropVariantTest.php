<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Crop values on file references: the variants come from the field the file is
 * attached to, and a value TYPO3 would silently drop or misapply is rejected.
 *
 * The fixture image (sys_file 1) is 1868x1261 pixels.
 */
class CropVariantTest extends FunctionalTestCase
{
    /**
     * A 3:2 area on the 1868x1261 fixture image: full width, 1245.33 px high, centered.
     * Whole numbers are integers, as they come back from the stored JSON.
     */
    private const LANDSCAPE_AREA = ['x' => 0, 'y' => 0.0062, 'width' => 1, 'height' => 0.9876];

    /**
     * A 1:1 area on the fixture image: full height, 1261.1 px wide, centered.
     */
    private const SQUARE_AREA = ['x' => 0.1625, 'y' => 0, 'width' => 0.6751, 'height' => 1];

    /**
     * Crop of a teaser item image with the variants only the teaser element type sets
     * (two overrideChildTca levels above the file reference).
     */
    private const TEASER_CROP = [
        'teaser' => ['cropArea' => self::LANDSCAPE_AREA, 'selectedRatio' => '3:2'],
        'tile' => ['cropArea' => self::SQUARE_AREA, 'selectedRatio' => '1:1'],
    ];

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_crop_variants',
        __DIR__ . '/../../Fixtures/Extensions/test_nested_files',
        __DIR__ . '/../../Fixtures/Extensions/test_untracked_foreign_field',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_metadata.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function schemaListsTheVariantsOfTheParentField(): void
    {
        $schema = $this->schemaFor('textmedia');

        $this->assertStringContainsString(
            '[crop variants: default (landscape = 1.5), mobile (square = 1, NaN = free) - write as',
            $this->lineOf('tx_testcropvariants_image', $schema)
        );
    }

    #[Test]
    public function schemaAppliesTheRecordTypesColumnsOverrides(): void
    {
        $schema = $this->schemaFor('image');

        $this->assertStringContainsString(
            '[crop variants: default (landscape = 1.5), mobile (square = 1, NaN = free), teaser (wide = 1.7778) - write as',
            $this->lineOf('tx_testcropvariants_image', $schema)
        );
    }

    #[Test]
    public function schemaFallsBackToTheCoreDefaultVariant(): void
    {
        $schema = $this->schemaFor('textmedia');

        $this->assertStringContainsString(
            '[crop variants: default (16:9 = 1.7778, 3:2 = 1.5, 4:3 = 1.3333, 1:1 = 1, NaN = free) - write as',
            $this->lineOf('assets', $schema)
        );
    }

    #[Test]
    public function cropMatchingTheSelectedRatioIsStored(): void
    {
        $crop = [
            'default' => ['cropArea' => self::LANDSCAPE_AREA, 'selectedRatio' => 'landscape', 'focusArea' => []],
        ];

        $contentUid = $this->createContentWithCrop($crop);

        $this->assertSame($crop, $this->readImageReferences($contentUid)[0]['crop']);
    }

    #[Test]
    public function cropWithTheWrongPixelRatioIsRejectedWithTheMatchingArea(): void
    {
        $result = $this->writeContentWithCrop([
            'default' => [
                'cropArea' => ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0],
                'selectedRatio' => 'landscape',
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: tx_testcropvariants_image[0].crop: variant "default": cropArea does not '
            . 'have the pixel ratio of "landscape" (1.5) on this 1868x1261 image. The largest matching area around '
            . 'the same center is {"x":0,"y":0.0062,"width":1,"height":0.9876}',
            $result->content[0]->text
        );
    }

    #[Test]
    public function unknownAndDisabledVariantsAreRejected(): void
    {
        $result = $this->writeContentWithCrop([
            'desktop' => ['cropArea' => self::LANDSCAPE_AREA],
            'retired' => ['cropArea' => self::LANDSCAPE_AREA],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: '
            . 'tx_testcropvariants_image[0].crop: unknown crop variant "desktop", available: default, mobile; '
            . 'tx_testcropvariants_image[0].crop: unknown crop variant "retired", available: default, mobile',
            $result->content[0]->text
        );
    }

    #[Test]
    public function ratioOutsideTheVariantIsRejected(): void
    {
        $result = $this->writeContentWithCrop([
            'default' => ['cropArea' => self::LANDSCAPE_AREA, 'selectedRatio' => 'square'],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: tx_testcropvariants_image[0].crop: variant "default": '
            . 'selectedRatio "square" is not allowed, available: landscape',
            $result->content[0]->text
        );
    }

    #[Test]
    public function pixelCoordinatesAreRejected(): void
    {
        $result = $this->writeContentWithCrop([
            'mobile' => [
                'cropArea' => ['x' => 100, 'y' => 0, 'width' => 800, 'height' => 800],
                'selectedRatio' => 'NaN',
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: tx_testcropvariants_image[0].crop: variant "mobile": cropArea exceeds '
            . 'the image: x + width and y + height must not be above 1 (relative 0-1, not pixels)',
            $result->content[0]->text
        );
    }

    #[Test]
    public function updatingOneVariantKeepsTheOthers(): void
    {
        $defaultVariant = ['cropArea' => self::LANDSCAPE_AREA, 'selectedRatio' => 'landscape', 'focusArea' => []];
        $contentUid = $this->createContentWithCrop([
            'default' => $defaultVariant,
            'mobile' => [
                'cropArea' => ['x' => 0.0, 'y' => 0.0, 'width' => 0.5, 'height' => 0.5],
                'selectedRatio' => 'NaN',
                'focusArea' => [],
            ],
        ]);
        $referenceUid = $this->readImageReferences($contentUid)[0]['uid'];
        $mobileVariant = [
            'cropArea' => ['x' => 0.25, 'y' => 0.25, 'width' => 0.5, 'height' => 0.5],
            'selectedRatio' => 'NaN',
            'focusArea' => [],
        ];

        $result = $this->writeTool()->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_testcropvariants_image' => [['uid' => $referenceUid, 'crop' => ['mobile' => $mobileVariant]]],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(
            ['default' => $defaultVariant, 'mobile' => $mobileVariant],
            $this->readImageReferences($contentUid)[0]['crop']
        );
    }

    #[Test]
    public function directReferenceWriteUsesTheVariantsOfItsField(): void
    {
        $contentUid = $this->createContentWithCrop([
            'default' => ['cropArea' => self::LANDSCAPE_AREA, 'selectedRatio' => 'landscape'],
        ]);
        $referenceUid = $this->readImageReferences($contentUid)[0]['uid'];

        $result = $this->writeTool()->execute([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => $referenceUid,
            'data' => [
                'crop' => ['teaser' => ['cropArea' => self::LANDSCAPE_AREA]],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: sys_file_reference[0].crop: unknown crop variant "teaser", '
            . 'available: default, mobile',
            $result->content[0]->text
        );
    }

    #[Test]
    public function cropOnAFileOfAnEmbeddedChildIsChecked(): void
    {
        $result = $this->writeTool()->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Element with nested files',
                'tx_testnestedfiles_items' => [
                    [
                        'title' => 'Item',
                        'file' => [[
                            'uid_local' => 1,
                            'crop' => ['default' => ['cropArea' => ['x' => 0.5, 'y' => 0.0, 'width' => 0.8, 'height' => 1.0]]],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: tx_testnestedfiles_items[0].file[0].crop: variant "default": cropArea '
            . 'exceeds the image: x + width and y + height must not be above 1 (relative 0-1, not pixels)',
            $result->content[0]->text
        );
    }

    #[Test]
    public function newIndependentChildUsesTheVariantsItsParentElementSets(): void
    {
        $itemUid = $this->createTeaserElementWithItem(['uid_local' => 1, 'crop' => self::TEASER_CROP]);

        $this->assertSame(['teaser', 'tile'], array_keys($this->readItemImageReference($itemUid)['crop']));
    }

    #[Test]
    public function linkedIndependentChildWrittenOnItsOwnUsesTheVariantsOfItsParentElement(): void
    {
        $itemUid = $this->createTeaserElementWithItem(['uid_local' => 1]);

        $result = $this->writeTool()->execute([
            'table' => 'tx_testuntrackedforeignfield_item',
            'action' => 'update',
            'uid' => $itemUid,
            'data' => [
                'image' => [['uid_local' => 1, 'crop' => self::TEASER_CROP]],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(['teaser', 'tile'], array_keys($this->readItemImageReference($itemUid)['crop']));
    }

    #[Test]
    public function directReferenceWriteOnALinkedChildUsesTheVariantsOfItsParentElement(): void
    {
        $itemUid = $this->createTeaserElementWithItem(['uid_local' => 1]);
        $referenceUid = $this->readItemImageReference($itemUid)['uid'];

        $result = $this->writeTool()->execute([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => $referenceUid,
            'data' => [
                'crop' => ['tile' => ['cropArea' => self::SQUARE_AREA, 'selectedRatio' => '1:1']],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(
            ['cropArea' => self::SQUARE_AREA, 'selectedRatio' => '1:1'],
            array_intersect_key($this->readItemImageReference($itemUid)['crop']['tile'], ['cropArea' => 1, 'selectedRatio' => 1])
        );
    }

    #[Test]
    public function linkedChildOfAnElementWithoutOverridesKeepsTheDefaultVariants(): void
    {
        $result = $this->writeTool()->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Element without teaser variants',
                'tx_testuntrackedforeignfield_items' => [
                    ['title' => 'Item', 'image' => [['uid_local' => 1, 'crop' => self::TEASER_CROP]]],
                ],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString(
            'Invalid crop: image[0].crop: unknown crop variant "teaser", available: default; '
            . 'image[0].crop: unknown crop variant "tile", available: default',
            $result->content[0]->text
        );
        $this->assertStringNotContainsString('not linked', $result->content[0]->text);
    }

    #[Test]
    public function unlinkedChildIsToldToLinkItsParentElementFirst(): void
    {
        $result = $this->writeTool()->execute([
            'table' => 'tx_testuntrackedforeignfield_item',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Item without element',
                'image' => [['uid_local' => 1, 'crop' => ['teaser' => self::TEASER_CROP['teaser']]]],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertSame(
            'Validation error: Invalid crop: image[0].crop: unknown crop variant "teaser", available: default. '
            . 'The crop variants of this record can depend on the element it belongs to, and it is not linked to '
            . 'one yet. Create it through the inline field of its parent element, or link it there first (by uid), '
            . 'then set the crop.',
            $result->content[0]->text
        );
    }

    private function writeTool(): WriteTableTool
    {
        return GeneralUtility::makeInstance(WriteTableTool::class);
    }

    private function writeContentWithCrop(array $crop): \Mcp\Types\CallToolResult
    {
        return $this->writeTool()->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Cropped image',
                'tx_testcropvariants_image' => [['uid_local' => 1, 'crop' => $crop]],
            ],
        ]);
    }

    private function createContentWithCrop(array $crop): int
    {
        $result = $this->writeContentWithCrop($crop);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readImageReferences(int $contentUid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true)['records'][0]['tx_testcropvariants_image'];
    }

    /**
     * Create a teaser element with one item and return the item's uid.
     */
    private function createTeaserElementWithItem(array $imageReference): int
    {
        $result = $this->writeTool()->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'testuntrackedforeignfield_teaser',
                'header' => 'Teaser element',
                'tx_testuntrackedforeignfield_items' => [
                    ['title' => 'Item', 'image' => [$imageReference]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = (int)json_decode($result->content[0]->text, true)['uid'];

        $read = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($read->isError, json_encode($read->jsonSerialize()));
        $itemUids = json_decode($read->content[0]->text, true)['records'][0]['tx_testuntrackedforeignfield_items'];
        $this->assertCount(1, $itemUids);
        return (int)$itemUids[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function readItemImageReference(int $itemUid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tx_testuntrackedforeignfield_item',
            'uid' => $itemUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $references = json_decode($result->content[0]->text, true)['records'][0]['image'];
        $this->assertCount(1, $references);
        return $references[0];
    }

    private function schemaFor(string $type): string
    {
        $result = GeneralUtility::makeInstance(GetTableSchemaTool::class)->execute([
            'table' => 'tt_content',
            'type' => $type,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return $result->content[0]->text;
    }

    private function lineOf(string $fieldName, string $schema): string
    {
        foreach (explode("\n", $schema) as $line) {
            if (preg_match('/\b' . preg_quote($fieldName, '/') . ' \(/', $line) === 1) {
                return $line;
            }
        }
        $this->fail('Field ' . $fieldName . ' not found in schema:' . "\n" . $schema);
    }
}

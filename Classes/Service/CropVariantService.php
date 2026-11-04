<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Ratio;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Crop variants of imageManipulation fields (e.g. sys_file_reference.crop) and
 * validation of crop values written through the MCP.
 *
 * The variants an image may be cropped to are rarely defined on sys_file_reference
 * itself. They come from the field the reference belongs to: its
 * `overrideChildTca` (Content Blocks writes `cropVariants` there), possibly
 * changed again by the parent record type's `columnsOverrides`. This service
 * resolves them the way the backend crop editor does, so the MCP offers and
 * accepts exactly the variants an editor sees.
 *
 * Validation matters because TYPO3 itself never rejects a crop value:
 * CropVariantCollection::create() swallows any malformed value into "no crop",
 * and maps an unknown variant id onto whichever configured variant comes next.
 * A wrong value is stored, reported as success, and renders differently from
 * what the caller asked for.
 */
class CropVariantService
{
    /**
     * The variant the backend crop editor offers when the TCA configures none,
     * mirroring ImageManipulationElement::$defaultConfig (which is protected).
     */
    private const DEFAULT_CROP_VARIANTS = [
        'default' => [
            'title' => 'Default',
            'allowedAspectRatios' => [
                '16:9' => ['title' => '16:9', 'value' => 16 / 9],
                '3:2' => ['title' => '3:2', 'value' => 3 / 2],
                '4:3' => ['title' => '4:3', 'value' => 4 / 3],
                '1:1' => ['title' => '1:1', 'value' => 1.0],
                'NaN' => ['title' => 'Free', 'value' => 0.0],
            ],
            'selectedRatio' => 'NaN',
        ],
    ];

    /**
     * Rounding slack for comparing relative coordinates against the 0–1 bounds.
     */
    private const BOUNDS_TOLERANCE = 0.0001;

    /**
     * Allowed relative deviation of a crop area's pixel proportion from its ratio.
     */
    private const RATIO_TOLERANCE = 0.005;

    /**
     * Crop variants that apply to the children of a file or inline field.
     *
     * @param array $parentFieldConfig the parent field's `config` as it applies to the
     *        parent record: the parent record type's columnsOverrides applied (as a
     *        TcaSchema sub-schema returns it), and for a parent that is an inline child
     *        itself, what its own parents pass down (InlineFieldConfigService)
     * @return array<string, array<string, array>> imageManipulation field of the
     *         child table => variant id => variant configuration; empty when the
     *         child table has no imageManipulation field
     */
    public function resolveForChildren(array $parentFieldConfig): array
    {
        $childTable = (string)($parentFieldConfig['foreign_table'] ?? '');
        $childColumns = $GLOBALS['TCA'][$childTable]['columns'] ?? [];
        // Cropping only exists for images, so the image type's overrides are the
        // ones the crop editor works with.
        $imageType = (string)FileType::IMAGE->value;
        $inlineFieldConfigService = GeneralUtility::makeInstance(InlineFieldConfigService::class);

        $result = [];
        foreach ($childColumns as $fieldName => $column) {
            if (($column['config']['type'] ?? '') !== 'imageManipulation') {
                continue;
            }
            $config = $inlineFieldConfigService->applyParentOverrides($childTable, (string)$fieldName, $imageType, $parentFieldConfig);
            $result[$fieldName] = $this->normalizeVariants($config);
        }
        return $result;
    }

    /**
     * Whether an inline parent may set the crop variants of files on records of this
     * table: some inline field to the table, in its base configuration or in a record
     * type's columnsOverrides, carries cropVariants in its overrideChildTca.
     */
    public function canComeFromInlineParent(string $table): bool
    {
        foreach ($GLOBALS['TCA'] ?? [] as $tableConfig) {
            $fieldConfigs = array_column($tableConfig['columns'] ?? [], 'config');
            foreach ($tableConfig['types'] ?? [] as $type) {
                foreach ($type['columnsOverrides'] ?? [] as $fieldName => $override) {
                    // An override usually leaves foreign_table to the base column.
                    $fieldConfigs[] = array_replace_recursive(
                        $tableConfig['columns'][$fieldName]['config'] ?? [],
                        $override['config'] ?? []
                    );
                }
            }
            foreach ($fieldConfigs as $config) {
                if (($config['foreign_table'] ?? '') === $table
                    && $this->containsCropVariants($config['overrideChildTca'] ?? [])
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Schema hint for a field whose children can be cropped, e.g.
     * ` [crop variants: default (3:2 = 1.5), mobile (1:1 = 1, free)]`.
     *
     * @param array<string, array<string, array>> $variantsByField as returned by resolveForChildren()
     */
    public function describe(array $variantsByField): string
    {
        $parts = [];
        foreach ($variantsByField as $fieldName => $variants) {
            $variantDescriptions = [];
            foreach ($variants as $variantId => $variant) {
                $ratios = [];
                foreach ($variant['allowedAspectRatios'] as $ratioId => $ratio) {
                    $value = (float)$ratio['value'];
                    $ratios[] = $value === 0.0
                        ? $ratioId . ' = free'
                        : $ratioId . ' = ' . round($value, 4);
                }
                $variantDescriptions[] = $variantId . ' (' . implode(', ', $ratios) . ')';
            }
            $parts[] = sprintf(
                '[%s variants: %s - write as {"<variant>": {"cropArea": {"x", "y", "width", "height"}, '
                . '"selectedRatio": "<ratio id>"}}, coordinates relative (0-1) to the original image, '
                . 'the crop area\'s pixel ratio must match the selected ratio. '
                . 'Variants not sent keep their current crop.]',
                $fieldName,
                implode(', ', $variantDescriptions)
            );
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * Check a crop value against the variants it may use.
     *
     * @param mixed $crop the value the caller sent for the imageManipulation field
     * @param array<string, array> $variants variant id => configuration, from resolveForChildren()
     * @param FileInterface|null $file the cropped file; without it the pixel ratio
     *        cannot be checked
     * @return list<string> one message per problem, empty when the value is valid
     */
    public function validate(mixed $crop, array $variants, ?FileInterface $file): array
    {
        if (!is_array($crop) || ($crop !== [] && array_is_list($crop))) {
            return ['must be an object keyed by crop variant, e.g. {"' . array_key_first($variants) . '": {"cropArea": {...}}}'];
        }

        $errors = [];
        foreach ($crop as $variantId => $variantValue) {
            if (!isset($variants[$variantId])) {
                $errors[] = sprintf(
                    'unknown crop variant "%s", available: %s',
                    $variantId,
                    implode(', ', array_keys($variants))
                );
                continue;
            }
            $variant = $variants[$variantId];
            if (!is_array($variantValue)) {
                $errors[] = sprintf('variant "%s" must be an object with "cropArea" and "selectedRatio"', $variantId);
                continue;
            }

            $selectedRatioId = $variantValue['selectedRatio'] ?? $variant['selectedRatio'] ?? null;
            if ($selectedRatioId !== null && !isset($variant['allowedAspectRatios'][$selectedRatioId])) {
                $errors[] = sprintf(
                    'variant "%s": selectedRatio "%s" is not allowed, available: %s',
                    $variantId,
                    (string)$selectedRatioId,
                    implode(', ', array_keys($variant['allowedAspectRatios']))
                );
                continue;
            }

            if (!array_key_exists('cropArea', $variantValue)) {
                $errors[] = sprintf('variant "%s": cropArea is required', $variantId);
                continue;
            }
            $areaError = $this->validateArea($variantValue['cropArea']);
            if ($areaError !== null) {
                $errors[] = sprintf('variant "%s": cropArea %s', $variantId, $areaError);
                continue;
            }

            // The backend sends an empty focusArea when none is set.
            $focusArea = $variantValue['focusArea'] ?? [];
            if ($focusArea !== []) {
                $focusError = $this->validateArea($focusArea);
                if ($focusError !== null) {
                    $errors[] = sprintf('variant "%s": focusArea %s', $variantId, $focusError);
                    continue;
                }
            }

            if ($selectedRatioId === null || $file === null) {
                continue;
            }
            $ratioConfig = $variant['allowedAspectRatios'][$selectedRatioId];
            $ratio = new Ratio((string)$selectedRatioId, (string)($ratioConfig['title'] ?? ''), (float)$ratioConfig['value']);
            $cropArea = Area::createFromConfiguration($variantValue['cropArea']);
            if ($ratio->isFree() || $this->cropAreaMatchesRatio($cropArea, $ratio, $file)) {
                continue;
            }
            $errors[] = sprintf(
                'variant "%s": cropArea does not have the pixel ratio of "%s" (%s) on this %dx%d image. '
                . 'The largest matching area around the same center is %s',
                $variantId,
                (string)$selectedRatioId,
                round($ratio->getRatioValue(), 4),
                (int)$file->getProperty('width'),
                (int)$file->getProperty('height'),
                json_encode(
                    array_map(
                        static fn (float $value): float => round($value, 4),
                        $cropArea->makeAbsoluteBasedOnFile($file)
                            ->applyRatioRestriction($ratio)
                            ->makeRelativeBasedOnFile($file)
                            ->asArray()
                    )
                )
            );
        }
        return $errors;
    }

    /**
     * Whether the crop area, measured in pixels of the file, has the ratio's
     * width-to-height proportion.
     */
    private function cropAreaMatchesRatio(Area $cropArea, Ratio $ratio, FileInterface $file): bool
    {
        // Without extracted metadata the proportion cannot be measured. The caller
        // cannot fix that, so the value is not held against them.
        if (empty($file->getProperty('width')) || empty($file->getProperty('height'))) {
            return true;
        }
        $pixelArea = $cropArea->makeAbsoluteBasedOnFile($file);
        $proportion = $pixelArea->getWidth() / $pixelArea->getHeight();
        // Relative coordinates rounded to four decimals deviate by a few thousandths
        // of a percent; a visibly wrong crop (e.g. the full image instead of 3:2)
        // deviates by more than one percent.
        return abs($proportion / $ratio->getRatioValue() - 1) <= self::RATIO_TOLERANCE;
    }

    /**
     * @return string|null what is wrong with the area, null when it is valid
     */
    private function validateArea(mixed $area): ?string
    {
        if (!is_array($area)) {
            return 'must be an object with x, y, width and height';
        }
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($area[$key]) || !is_numeric($area[$key])) {
                return sprintf('needs numeric "%s"', $key);
            }
        }
        $x = (float)$area['x'];
        $y = (float)$area['y'];
        $width = (float)$area['width'];
        $height = (float)$area['height'];
        if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0) {
            return 'needs x and y of at least 0 and a width and height above 0 (relative 0-1, not pixels)';
        }
        if ($x + $width > 1 + self::BOUNDS_TOLERANCE || $y + $height > 1 + self::BOUNDS_TOLERANCE) {
            return 'exceeds the image: x + width and y + height must not be above 1 (relative 0-1, not pixels)';
        }
        return null;
    }

    /**
     * Whether an overrideChildTca sets crop variants, on its own children or further down.
     */
    private function containsCropVariants(array $tca): bool
    {
        foreach ($tca as $key => $value) {
            if ($key === 'cropVariants' || (is_array($value) && $this->containsCropVariants($value))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Variant configuration as ImageManipulationElement::populateConfiguration()
     * prepares it: core defaults when none are configured, disabled variants and
     * ratios dropped, ratio ids with "." replaced the way Ratio stores them.
     *
     * @return array<string, array>
     */
    private function normalizeVariants(array $config): array
    {
        $variants = [];
        foreach ($config['cropVariants'] ?? self::DEFAULT_CROP_VARIANTS as $variantId => $variant) {
            if (!empty($variant['disabled'])) {
                continue;
            }
            $ratios = [];
            foreach ($variant['allowedAspectRatios'] ?? [] as $ratioId => $ratio) {
                if (!empty($ratio['disabled'])) {
                    continue;
                }
                $ratios[Ratio::prepareAspectRatioId((string)$ratioId)] = $ratio;
            }
            $variant['allowedAspectRatios'] = $ratios;
            $variants[(string)$variantId] = $variant;
        }
        return $variants;
    }
}

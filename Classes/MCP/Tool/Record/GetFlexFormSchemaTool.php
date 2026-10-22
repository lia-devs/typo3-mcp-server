<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Utility\TcaFormattingUtility;
use Hn\McpServer\Service\FlexFormStructureService;
use Hn\McpServer\Service\TableAccessService;

/**
 * Tool for getting FlexForm schema information
 */
class GetFlexFormSchemaTool extends AbstractRecordTool
{
    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Inspect the FlexForm DataStructure of a plugin field: which fields it declares, their types, and their allowed values. '
                . 'Call this BEFORE writing a FlexForm field with WriteTable — WriteTable rejects every field the resolved DataStructure does not declare. '
                . 'The identifier is the record type; TYPO3 14 keys DataStructures directly by CType (e.g. "news_pi1", "form_formframework"), and GetTableSchema lists the identifiers available per table. '
                . 'Field names come back in the nested shape WriteTable and ReadTable use, so a declared path settings.orderBy is written as {"settings": {"orderBy": "..."}}.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name containing the FlexForm field (default: tt_content)',
                        'default' => 'tt_content',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name containing the FlexForm data (default: pi_flexform)',
                        'default' => 'pi_flexform',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'The FlexForm identifier. TYPO3 14 keys DataStructures directly by CType (e.g. "news_pi1", "form_formframework").',
                    ],
                    'recordUid' => [
                        'type' => 'integer',
                        'description' => 'Optional record UID. When given, the record is loaded and used to resolve the DataStructure, so record-dependent schemas (e.g. EXT:form finisher override sheets) are included.',
                    ],
                ],
                'required' => ['identifier'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true
            ]
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Get parameters
        $table = $params['table'] ?? 'tt_content';
        $field = $params['field'] ?? 'pi_flexform';
        $identifier = $params['identifier'] ?? '';
        $recordUid = isset($params['recordUid']) ? (int)$params['recordUid'] : null;

        // Validate parameters
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Identifier parameter is required');
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, 'read');

        // Check if the table and field exist
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            throw new \InvalidArgumentException("Field '$field' not found in table '$table'");
        }

        // Check if the field is a FlexForm field
        if (($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '') !== 'flex') {
            throw new \InvalidArgumentException("Field '$field' in table '$table' is not a FlexForm field");
        }

        // Build the header
        $header = "FLEXFORM SCHEMA: $identifier\n";
        $header .= "=======================================\n\n";
        $header .= "Table: $table\n";
        $header .= "Field: $field\n\n";

        // Resolve the DataStructure through FlexFormStructureService (which
        // uses TYPO3's FlexFormTools) so the DataStructure identifier events
        // run. This is essential for dynamic DataStructures such as EXT:form
        // (form_formframework): the `settings.persistenceIdentifier` select
        // items (the list of available forms) and the per-finisher override
        // sheets are injected at runtime and are absent from the static
        // FlexForm XML file. Reading the raw file — as this tool did
        // previously — would hide them from the LLM.
        $structure = GeneralUtility::makeInstance(FlexFormStructureService::class)
            ->resolveDataStructure($table, $field, $identifier, $recordUid);

        if ($structure !== null) {
            $processedData = $this->processFlexFormXml($structure);
            if (!empty($processedData['fields']) || !empty($processedData['sheets'])) {
                $result = $this->formatFlexFormSchema($processedData, $header);
                return $this->createSuccessResult($result);
            }
        }

        // If we get here, the identifier could not be resolved to a DataStructure
        throw new \InvalidArgumentException("FlexForm schema not found for identifier: $identifier");
    }

    /**
     * Convert dot notation field name to JSON path
     * e.g., "settings.orderBy" -> "pi_flexform.settings.orderBy"
     */
    protected function getJsonPath(string $fieldName): string
    {
        if (strpos($fieldName, '.') === false) {
            return 'pi_flexform.' . $fieldName;
        }

        $parts = explode('.', $fieldName);
        return 'pi_flexform.' . implode('.', $parts);
    }

    /**
     * Process a single field configuration
     *
     * @param string $fieldName The field name
     * @param array $field The field configuration
     * @return array Processed field data with type, label, description, etc.
     */
    protected function processField(string $fieldName, array $field): array
    {
        $fieldData = [
            'name' => $fieldName,
            'type' => 'unknown',
            'label' => $fieldName,
            'description' => '',
            'config' => [],
            'jsonPath' => $this->getJsonPath($fieldName)
        ];

        // Check if field uses TCEforms structure (older format) or direct configuration (newer format)
        $fieldConfig = isset($field['TCEforms']) ? $field['TCEforms'] : $field;

        // Get field label — sections and their containers carry a `title`
        $label = $fieldConfig['label'] ?? $fieldConfig['title'] ?? null;
        if ($label !== null) {
            $fieldData['label'] = TableAccessService::translateLabel($label);
        }

        // Get field type and config
        if (isset($fieldConfig['config']['type'])) {
            $fieldData['type'] = $fieldConfig['config']['type'];
            $fieldData['config'] = $fieldConfig['config'];
        }

        // A section is a repeatable item list without a `config`: its
        // containers declare the subfields of an item
        $structureService = GeneralUtility::makeInstance(FlexFormStructureService::class);
        if ($structureService->isSection($fieldConfig)) {
            $fieldData['type'] = 'section';
            $fieldData['containers'] = [];
            foreach (($fieldConfig['el'] ?? []) as $containerName => $container) {
                $fieldData['containers'][(string)$containerName] = [
                    'label' => TableAccessService::translateLabel($container['title'] ?? (string)$containerName),
                    'subfields' => $this->processFields($container['el'] ?? []),
                ];
            }
            $fieldData['omitsContainerName'] = $structureService->itemsOmitTheContainerName($fieldData['containers']);
        }

        // Get field description
        if (isset($fieldConfig['description'])) {
            $fieldData['description'] = TableAccessService::translateLabel($fieldConfig['description']);
        }

        return $fieldData;
    }

    /**
     * Process a collection of fields
     *
     * @param array $fields The fields to process
     * @return array Array of processed field data
     */
    protected function processFields(array $fields): array
    {
        $processedFields = [];

        foreach ($fields as $fieldName => $field) {
            $processedFields[] = $this->processField($fieldName, $field);
        }

        return $processedFields;
    }

    /**
     * Process FlexForm sheets
     *
     * @param array $sheets The sheets to process
     * @return array Processed sheets data
     */
    protected function processSheets(array $sheets): array
    {
        $processedSheets = [];

        foreach ($sheets as $sheetName => $sheet) {
            $sheetData = [
                'name' => $sheetName,
                'fields' => []
            ];

            if (isset($sheet['ROOT']['el'])) {
                $sheetData['fields'] = $this->processFields($sheet['ROOT']['el']);
            }

            $processedSheets[] = $sheetData;
        }

        return $processedSheets;
    }

    /**
     * Process FlexForm XML structure
     *
     * @param array $xmlArray The parsed XML array
     * @return array Processed FlexForm data
     */
    protected function processFlexFormXml(array $xmlArray): array
    {
        $data = [
            'sheets' => [],
            'fields' => [],
            'hasSheets' => false
        ];

        if (isset($xmlArray['sheets'])) {
            // Multi-sheet FlexForm
            $data['hasSheets'] = true;
            $data['sheets'] = $this->processSheets($xmlArray['sheets']);

        } elseif (isset($xmlArray['ROOT']['el'])) {
            // Single sheet FlexForm, stored as single unnamed sheet for consistency
            $data['sheets'][] = [
                'name' => null,
                'fields' => $this->processFields($xmlArray['ROOT']['el'])
            ];
        }

        // Collect all fields for the JSON example
        foreach ($data['sheets'] as $sheet) {
            foreach ($sheet['fields'] as $field) {
                $data['fields'][$field['name']] = $field;
            }
        }

        return $data;
    }

    /**
     * Format processed FlexForm data as text
     *
     * @param array $data Processed FlexForm data
     * @param string $prefix Additional prefix text
     * @return string Formatted text output
     */
    protected function formatFlexFormSchema(array $data, string $prefix = ''): string
    {
        $result = $prefix;

        if ($data['hasSheets']) {
            $result .= "SHEETS:\n";
            $result .= "-------\n";

            foreach ($data['sheets'] as $sheet) {
                $result .= "Sheet: {$sheet['name']}\n";
                $result .= "  Fields:\n";

                foreach ($sheet['fields'] as $field) {
                    $result .= $this->formatField($field, '  ');
                }

                $result .= "\n";
            }
        } else {
            $result .= "FIELDS:\n";
            $result .= "------\n";

            if (!empty($data['sheets'][0]['fields'])) {
                foreach ($data['sheets'][0]['fields'] as $field) {
                    $result .= $this->formatField($field, '');
                }
            }

            $result .= "\n";
        }

        // Add JSON structure example
        $result .= "JSON STRUCTURE:\n";
        $result .= "==============\n";
        $result .= "When reading or writing FlexForm data, use nested objects/arrays:\n\n";

        if (!empty($data['fields'])) {
            $jsonExample = $this->buildJsonExample($data['fields']);
            $result .= json_encode($jsonExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $result .= json_encode(['pi_flexform' => ['example' => 'This is an example of the FlexForm data structure']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $result .= "\n\nNote: Field names with dots (e.g., \"settings.orderBy\") are automatically\n";
        $result .= "converted to nested structures by TYPO3.";

        return $result;
    }

    /**
     * Format a single field for text output
     *
     * @param array $field The field data
     * @param string $indent Indentation prefix
     * @return string Formatted field text
     */
    protected function formatField(array $field, string $indent): string
    {
        $result = $indent . "- {$field['name']}";

        if ($field['label'] !== $field['name']) {
            $result .= " ({$field['label']})";
        }

        $result .= ": {$field['type']}";

        // Add field details based on type
        if (!empty($field['config'])) {
            TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
        }

        if (!empty($field['description'])) {
            $result .= " - {$field['description']}";
        }

        $result .= "\n";
        $result .= $indent . "  JSON Path: {$field['jsonPath']}\n";

        if ($field['type'] === 'section') {
            $result .= $this->formatSectionItems($field, $indent . '  ');
        }

        return $result;
    }

    /**
     * Describe the item shape of a section: WriteTable takes the section as a
     * JSON list that replaces all stored items, in the same shape ReadTable
     * returns it.
     *
     * @param array $field Processed section field data
     */
    protected function formatSectionItems(array $field, string $indent): string
    {
        if ($field['omitsContainerName']) {
            $result = $indent . 'Value: JSON list of items, each {"<subfield>": "<value>"}; '
                . "the list replaces all stored items\n";
            $result .= $indent . "Item subfields:\n";
            foreach ($field['containers'][FlexFormStructureService::ANONYMOUS_CONTAINER]['subfields'] as $subfield) {
                $result .= $this->formatSubfield($subfield, $indent . '  ');
            }
            return $result;
        }

        $result = $indent . 'Value: JSON list of items, each {"<container>": {"<subfield>": "<value>"}} '
            . "with exactly one container; the list replaces all stored items\n";
        foreach ($field['containers'] as $containerName => $container) {
            $result .= $indent . "Container: $containerName";
            if ($container['label'] !== $containerName) {
                $result .= " ({$container['label']})";
            }
            $result .= "\n";
            foreach ($container['subfields'] as $subfield) {
                $result .= $this->formatSubfield($subfield, $indent . '  ');
            }
        }

        return $result;
    }

    /**
     * Format a subfield of a section item — it has no JSON path of its own
     */
    protected function formatSubfield(array $subfield, string $indent): string
    {
        $result = $indent . "- {$subfield['name']}";
        if ($subfield['label'] !== $subfield['name']) {
            $result .= " ({$subfield['label']})";
        }
        $result .= ": {$subfield['type']}";
        if (!empty($subfield['config'])) {
            TcaFormattingUtility::addFieldDetailsInline($result, $subfield['config']);
        }

        return $result . "\n";
    }

    /**
     * Build example JSON structure from the processed fields
     *
     * @param array<string, array> $fields processed field data keyed by field name
     */
    protected function buildJsonExample(array $fields): array
    {
        $example = ['pi_flexform' => []];

        foreach ($fields as $fieldName => $field) {
            $parts = explode('.', (string)$fieldName);
            $current = &$example['pi_flexform'];

            // Navigate/create the nested structure
            for ($i = 0; $i < count($parts) - 1; $i++) {
                if (!isset($current[$parts[$i]])) {
                    $current[$parts[$i]] = [];
                }
                $current = &$current[$parts[$i]];
            }

            // Set the final value
            $lastPart = $parts[count($parts) - 1];
            $current[$lastPart] = $field['type'] === 'section'
                ? [$this->buildSectionItemExample($field)]
                : '<' . $lastPart . ' value>';
            unset($current);
        }

        return $example;
    }

    /**
     * One example item of a section, built from its first container
     */
    protected function buildSectionItemExample(array $field): array
    {
        $containerName = (string)array_key_first($field['containers']);
        $item = [];
        foreach ($field['containers'][$containerName]['subfields'] as $subfield) {
            $item[$subfield['name']] = '<' . $subfield['name'] . ' value>';
        }

        return $field['omitsContainerName'] ? $item : [$containerName => $item];
    }
}

<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\LanguageService as McpLanguageService;

/**
 * Utility for formatting TCA and FlexForm information
 */
class TcaFormattingUtility
{

    /**
     * Add field details inline for TCA or FlexForm configuration
     *
     * @param string &$result The result string to append to
     * @param array $config The field configuration
     * @param string $fieldName Optional field name for special handling
     * @param string $table Optional table name for authMode filtering
     * @param int|null $pid Optional page id for TSconfig context (filters select items via FormDataCompiler)
     */
    public static function addFieldDetailsInline(string &$result, $config, string $fieldName = '', string $table = '', ?int $pid = null): void
    {
        // Get the field type
        $type = $config['type'] ?? '';
        
        // Add field details based on type
        switch ($type) {
            case 'input':
                if (isset($config['size'])) {
                    $result .= " [size: " . $config['size'] . "]";
                }
                if (isset($config['max'])) {
                    $result .= " [max: " . $config['max'] . "]";
                }
                
                // Check for typolink support via softref
                if (isset($config['softref']) && strpos($config['softref'], 'typolink_tag') !== false) {
                    $result .= " [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]";
                }
                break;
                
            case 'text':
                if (isset($config['cols'])) {
                    $result .= " [cols: " . $config['cols'] . "]";
                }
                if (isset($config['rows'])) {
                    $result .= " [rows: " . $config['rows'] . "]";
                }
                
                // Check for richtext enabled
                if (isset($config['enableRichtext']) && $config['enableRichtext']) {
                    $result .= " [Richtext/HTML]";
                }
                
                // Check for typolink support via softref
                if (isset($config['softref']) && strpos($config['softref'], 'typolink_tag') !== false) {
                    $result .= " [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]";
                }
                break;
                
            case 'check':
                if (isset($config['default'])) {
                    $result .= " [Default: " . $config['default'] . "]";
                }
                break;
                
            case 'select':
                // Add renderType if available
                if (isset($config['renderType'])) {
                    $result .= " [renderType: " . $config['renderType'] . "]";
                }

                // Add foreign table and MM information
                if (isset($config['foreign_table'])) {
                    $result .= " [foreign table: " . $config['foreign_table'] . "]";
                }
                if (isset($config['MM'])) {
                    $result .= " [MM table: " . $config['MM'] . "]";
                }

                // Resolve select options using FormDataCompiler (handles static items, foreign_table, itemsProcFunc, TSconfig).
                // Pass the pid context so TSconfig defined on real pages (rootline) is applied — vanillaUid=0 misses it.
                $resolved = null;
                if (!empty($table) && !empty($fieldName)) {
                    $resolver = GeneralUtility::makeInstance(SelectItemResolver::class);
                    $resolverPid = GeneralUtility::makeInstance(TableAccessService::class)->resolveTSconfigPid($pid);
                    $resolved = $resolver->resolveSelectItems($table, $fieldName, ['pid' => $resolverPid]);
                }

                if ($resolved !== null && !empty($resolved['values'])) {
                    // Use dynamically resolved items
                    $hasAuthMode = !empty($config['authMode']);
                    $beUser = $GLOBALS['BE_USER'] ?? null;
                    $isAdmin = $beUser && $beUser->isAdmin();

                    // tt_content.CType also honours TCEMAIN.disableCTypes, which
                    // FormDataCompiler doesn't apply.
                    $disabledCTypes = self::getDisabledCTypes($table, $fieldName, $resolverPid ?? 0);

                    $options = [];
                    foreach ($resolved['values'] as $value) {
                        // Filter by authMode for non-admin users
                        if ($hasAuthMode && !$isAdmin && $beUser) {
                            if (!$beUser->checkAuthMode($table, $fieldName, $value)) {
                                continue;
                            }
                        }

                        if (in_array((string)$value, $disabledCTypes, true)) {
                            continue;
                        }

                        $label = $resolved['labels'][$value] ?? '';
                        if ($label) {
                            $translatedLabel = TableAccessService::translateLabel($label);
                            $options[] = $value . " (" . $translatedLabel . ")";
                        }
                    }

                    if (!empty($options)) {
                        $result .= " [Options: " . implode(', ', $options) . "]";
                    }
                } elseif (isset($config['items']) && is_array($config['items'])) {
                    // Fallback: use static TCA items
                    $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
                    $parsed = $tableAccessService->parseSelectItems($config['items'], false);

                    $hasAuthMode = !empty($config['authMode']);
                    $beUser = $GLOBALS['BE_USER'] ?? null;
                    $isAdmin = $beUser && $beUser->isAdmin();
                    $disabledCTypes = self::getDisabledCTypes($table, $fieldName, $tableAccessService->resolveTSconfigPid($pid));

                    $options = [];
                    foreach ($parsed['values'] as $value) {
                        if ($value === '--div--') {
                            continue;
                        }
                        if ($hasAuthMode && !$isAdmin && $beUser && !empty($table) && !empty($fieldName)) {
                            if (!$beUser->checkAuthMode($table, $fieldName, $value)) {
                                continue;
                            }
                        }
                        if (in_array((string)$value, $disabledCTypes, true)) {
                            continue;
                        }
                        $label = $parsed['labels'][$value] ?? '';
                        if ($label) {
                            $translatedLabel = TableAccessService::translateLabel($label);
                            $options[] = $value . " (" . $translatedLabel . ")";
                        }
                    }

                    if (!empty($options)) {
                        $result .= " [Options: " . implode(', ', $options) . "]";
                    }
                }
                
                // Special handling for sys_language_uid field
                if ($fieldName === 'sys_language_uid') {
                    // Add note about ISO code support
                    $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                    $isoCodes = $languageService->getAvailableIsoCodes();
                    
                    if (!empty($isoCodes)) {
                        $result .= " [ISO codes accepted: " . implode(', ', $isoCodes) . "]";
                        $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
                    }
                }
                break;
                
            case 'group':
                // Add allowed table if available
                if (isset($config['allowed'])) {
                    $result .= " [allowed: " . $config['allowed'] . "]";
                }
                break;
                
            case 'inline':
            case 'file':
                $foreignTable = $config['foreign_table'] ?? '';
                if (empty($foreignTable)) {
                    break;
                }

                $isHiddenTable = GeneralUtility::makeInstance(TableAccessService::class)
                    ->isEmbeddedChildTable($foreignTable);

                if ($isHiddenTable) {
                    // Embedded relation: LLM writes array of record objects
                    $result .= " [embedded records from " . $foreignTable . " - write as: [";
                    $result .= self::generateMiniExample($foreignTable);
                    $result .= "]. Use GetTableSchema on " . $foreignTable . " for all fields.]";
                } else {
                    // Independent relation: LLM writes array of UIDs
                    $result .= " [relation to " . $foreignTable . " - write as array of UIDs, e.g. [12, 34]]";
                }
                break;
                
            case 'flex':
                // TYPO3 13: surface ds_pointerField configuration if present.
                // Removed in TYPO3 14; schemas use columnsOverrides instead.
                if (isset($config['ds_pointerField'])) {
                    $result .= " [ds_pointerField: " . $config['ds_pointerField'] . "]";
                }
                break;


            case 'language':
                // Special handling for language type fields (TYPO3 11.2+)
                // Add note about ISO code support
                $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                $isoCodes = $languageService->getAvailableIsoCodes();
                
                if (!empty($isoCodes)) {
                    $result .= " [ISO codes accepted: " . implode(', ', $isoCodes) . "]";
                    $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
                }
                break;
        }
        
        // --- Common constraints (apply to all field types) ---

        // Required: explicit flag, legacy eval, or a minimum number of items (#186)
        $minItems = (int)($config['minitems'] ?? 0);
        $maxItems = (int)($config['maxitems'] ?? 0);
        $isRequired = !empty($config['required'])
            || $minItems > 0
            || in_array('required', GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true), true);
        if ($isRequired) {
            $result .= " [Required]";
        }

        // Read-only
        if (!empty($config['readOnly'])) {
            $result .= " [Read-only]";
        }

        // Nullable
        if (!empty($config['nullable'])) {
            $result .= " [Nullable]";
        }

        // Min/max items, only where a field holds items
        if (in_array($type, ['inline', 'file', 'group', 'select', 'category', 'folder'], true)) {
            if ($minItems > 0) {
                $result .= " [minitems: " . $minItems . "]";
            }
            if ($maxItems > 0) {
                $result .= " [maxitems: " . $maxItems . "]";
            }
        }

        // Range constraints (number, datetime)
        if (isset($config['range'])) {
            $range = $config['range'];
            if (isset($range['lower'])) {
                $result .= " [min: " . $range['lower'] . "]";
            }
            if (isset($range['upper'])) {
                $result .= " [max: " . $range['upper'] . "]";
            }
        }

        // Min/max for number type (TYPO3 12+)
        if (isset($config['min']) && $config['min'] !== '') {
            $result .= " [min: " . $config['min'] . "]";
        }
        if (isset($config['max']) && $config['max'] !== '' && $type !== 'input') {
            // Skip 'max' for input type — already handled above as max length
            $result .= " [max: " . $config['max'] . "]";
        }

        // Eval constraints (trim, unique, email, int, etc.)
        if (isset($config['eval'])) {
            $evalRules = GeneralUtility::trimExplode(',', $config['eval'], true);
            $relevantEvals = array_diff($evalRules, ['required', 'trim', 'null']);
            if (!empty($relevantEvals)) {
                $result .= " [eval: " . implode(', ', $relevantEvals) . "]";
            }
        }

        // Default value
        if (array_key_exists('default', $config) && $type !== 'check') {
            $default = $config['default'];
            if (is_bool($default)) {
                $result .= " [Default: " . ($default ? 'true' : 'false') . "]";
            } elseif ($default !== '' && $default !== null) {
                $result .= " [Default: " . $default . "]";
            }
        }

        // Placeholder
        if (!empty($config['placeholder'])) {
            $result .= " [Placeholder: " . TableAccessService::translateLabel((string)$config['placeholder']) . "]";
        }
    }

    /**
     * Resolve TCEMAIN.table.tt_content.disableCTypes for tt_content.CType.
     * FormDataCompiler doesn't apply this — it's read by the New Content Element
     * Wizard — so callers must filter manually to match what TYPO3 would show.
     */
    protected static function getDisabledCTypes(string $table, string $fieldName, int $pid): array
    {
        if ($table !== 'tt_content' || $fieldName !== 'CType') {
            return [];
        }
        $TSconfig = BackendUtility::getPagesTSconfig($pid);
        $disableCTypes = $TSconfig['TCEMAIN.']['table.']['tt_content.']['disableCTypes'] ?? '';
        if (empty($disableCTypes)) {
            return [];
        }
        return GeneralUtility::trimExplode(',', $disableCTypes, true);
    }

    /**
     * Generate a mini example object from a table's accessible fields.
     * Shows a few key user-editable fields to give the LLM a sense of the record structure.
     */
    protected static function generateMiniExample(string $foreignTable): string
    {
        $foreignTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
        $columns = $foreignTCA['columns'] ?? [];

        // System/auto-managed fields to skip in the example
        $skipFields = [
            'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting', 'sorting_foreign',
            'uid_foreign', 'tablenames', 'fieldname',
            'sys_language_uid', 'l10n_parent', 'l10n_diffsource', 'l10n_state',
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3_origuid',
        ];

        $exampleFields = [];
        $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);

        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, $skipFields, true)) {
                continue;
            }

            if (!$tableAccessService->canAccessField($foreignTable, $fieldName)) {
                continue;
            }

            $fieldType = $fieldConfig['config']['type'] ?? '';

            // Generate a placeholder value based on field type
            switch ($fieldType) {
                case 'input':
                case 'text':
                case 'link':
                case 'email':
                case 'slug':
                    $exampleFields[$fieldName] = '...';
                    break;
                case 'number':
                case 'group':
                    $exampleFields[$fieldName] = '0';
                    break;
                case 'check':
                    $exampleFields[$fieldName] = '0';
                    break;
                default:
                    // Skip complex types (imageManipulation, flex, etc.) in the mini example
                    continue 2;
            }

            // Limit to a few fields to keep it concise
            if (count($exampleFields) >= 4) {
                break;
            }
        }

        if (empty($exampleFields)) {
            return '{}';
        }

        $parts = [];
        foreach ($exampleFields as $name => $placeholder) {
            if ($placeholder === '0') {
                $parts[] = '"' . $name . '": ' . $placeholder;
            } else {
                $parts[] = '"' . $name . '": "' . $placeholder . '"';
            }
        }

        return '{' . implode(', ', $parts) . '}';
    }
}

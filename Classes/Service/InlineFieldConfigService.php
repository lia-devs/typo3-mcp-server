<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Field configuration of a record as FormEngine builds it inside its inline parent.
 *
 * An inline parent field may change the TCA of its children through
 * `overrideChildTca`, and the parent record type may change that field through
 * `columnsOverrides`. FormEngine hands the result down level by level, so a setting
 * can live two or more levels above the field it affects. Example (lia_ctypes'
 * static content teaser): the crop variants of a teaser image are set in
 * tt_content.types[<CType>].columnsOverrides.tx_liactypes_ctypes.config
 * .overrideChildTca.columns.image.config.overrideChildTca.columns.crop.
 *
 * The MCP writes independent children (and file references) on their own, outside
 * the parent's form, so it has to rebuild that chain: from a write context that
 * names the parent field, or from the stored foreign field of the record.
 *
 * See Documentation/Architecture/InlineRelations.md.
 */
class InlineFieldConfigService
{
    /**
     * Inline chains deeper than this are corrupt data (a record its own ancestor).
     */
    private const MAX_DEPTH = 20;

    private ?InlineParentService $inlineParentService = null;

    /**
     * A child field's configuration with what the inline parent field passes down.
     *
     * Same order as FormEngine: the parent's overrideChildTca is merged into the child
     * TCA first (InlineOverrideChildTca), then the child record type's columnsOverrides
     * are applied (TcaColumnsOverrides), which include the types overrideChildTca set.
     *
     * @param array $parentFieldConfig the parent field's `config` as it applies to the
     *        parent record, empty for a record without inline parent
     * @return array the child field's `config`
     */
    public function applyParentOverrides(string $childTable, string $fieldName, string $childRecordType, array $parentFieldConfig): array
    {
        $overrideChildTca = $parentFieldConfig['overrideChildTca'] ?? [];
        $types = array_replace_recursive(
            $GLOBALS['TCA'][$childTable]['types'] ?? [],
            $overrideChildTca['types'] ?? []
        );
        $type = $this->resolveTypeKey($types, $childRecordType);
        return array_replace_recursive(
            $GLOBALS['TCA'][$childTable]['columns'][$fieldName]['config'] ?? [],
            $overrideChildTca['columns'][$fieldName]['config'] ?? [],
            $types[$type]['columnsOverrides'][$fieldName]['config'] ?? []
        );
    }

    /**
     * A stored record's field configuration, with everything its inline parents up
     * the chain pass down.
     *
     * @param array $row the record as the workspace sees it
     * @return array the field's `config`
     */
    public function resolveFieldConfig(string $table, array $row, string $fieldName): array
    {
        return $this->resolveFieldConfigAtDepth($table, $row, $fieldName, 0);
    }

    /**
     * The value of the record type field, '' for tables without one. Foreign type
     * notation (sys_file_reference: "uid_local:type") is resolved from the file.
     */
    public function recordTypeOf(string $table, array $row): string
    {
        $typeField = (string)($GLOBALS['TCA'][$table]['ctrl']['type'] ?? '');
        if ($typeField === '') {
            return '';
        }
        if (!str_contains($typeField, ':')) {
            return (string)($row[$typeField] ?? '');
        }
        [$pointerField, $foreignTypeField] = explode(':', $typeField, 2);
        $pointerConfig = $GLOBALS['TCA'][$table]['columns'][$pointerField]['config'] ?? [];
        $foreignTable = (string)($pointerConfig['foreign_table'] ?? $pointerConfig['allowed'] ?? '');
        $foreignUid = (int)($row[$pointerField] ?? 0);
        if ($foreignTable === '' || $foreignUid <= 0) {
            return '';
        }
        return (string)(BackendUtility::getRecord($foreignTable, $foreignUid, $foreignTypeField)[$foreignTypeField] ?? '');
    }

    /**
     * The inline field a record belongs to: the parent record and the parent field
     * whose foreign_field, foreign_table_field and foreign_match_fields point at it.
     *
     * The parent field is looked up with the parent's record type applied: a type
     * may turn a column into an inline relation or give it match fields through
     * columnsOverrides (e.g. two inline fields to the same child table that tell
     * their children apart by a "type" match field).
     *
     * @param array $row the record as the workspace sees it
     * @return array{table: string, row: array<string, mixed>, field: string}|null null
     *         when the record belongs to no parent, or to more than one candidate field
     */
    public function findInlineParent(string $table, array $row): ?array
    {
        $candidates = [];
        foreach ($this->getInlineParentService()->getRelations() as $relation) {
            if ($relation['childTable'] !== $table) {
                continue;
            }
            $parentTable = $relation['foreignTableField'] === null
                ? $relation['parentTable']
                : (string)($row[$relation['foreignTableField']] ?? '');
            $parentUid = (int)($row[$relation['foreignField']] ?? 0);
            if ($parentTable === '' || $parentUid <= 0 || !isset($GLOBALS['TCA'][$parentTable])) {
                continue;
            }
            $candidates[$parentTable . ':' . $parentUid . ':' . $relation['foreignField']] = [
                'table' => $parentTable,
                'uid' => $parentUid,
                'foreignField' => $relation['foreignField'],
                'foreignTableField' => $relation['foreignTableField'],
            ];
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            $parentRow = BackendUtility::getRecordWSOL($candidate['table'], $candidate['uid']);
            if (!is_array($parentRow)) {
                continue;
            }
            $parentType = $this->recordTypeOf($candidate['table'], $parentRow);
            foreach (array_keys($GLOBALS['TCA'][$candidate['table']]['columns'] ?? []) as $parentField) {
                $config = $this->applyParentOverrides($candidate['table'], (string)$parentField, $parentType, []);
                if ($this->pointsAt($config, $table, $row, $candidate['foreignField'], $candidate['foreignTableField'], $candidate['table'])) {
                    $matches[] = ['table' => $candidate['table'], 'row' => $parentRow, 'field' => (string)$parentField];
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function resolveFieldConfigAtDepth(string $table, array $row, string $fieldName, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException(
                sprintf('Inline parent chain of %s:%d is deeper than %d levels', $table, (int)($row['uid'] ?? 0), self::MAX_DEPTH),
                1762250001
            );
        }
        $parent = $this->findInlineParent($table, $row);
        $parentFieldConfig = $parent === null
            ? []
            : $this->resolveFieldConfigAtDepth($parent['table'], $parent['row'], $parent['field'], $depth + 1);
        return $this->applyParentOverrides($table, $fieldName, $this->recordTypeOf($table, $row), $parentFieldConfig);
    }

    /**
     * Whether an inline or file field holds the given child row.
     */
    private function pointsAt(array $config, string $childTable, array $childRow, string $foreignField, ?string $foreignTableField, string $parentTable): bool
    {
        if (!in_array($config['type'] ?? '', ['inline', 'file'], true)
            || ($config['foreign_table'] ?? '') !== $childTable
            || ($config['foreign_field'] ?? '') !== $foreignField
        ) {
            return false;
        }
        $configuredTableField = !empty($config['foreign_table_field']) ? (string)$config['foreign_table_field'] : null;
        if ($configuredTableField !== $foreignTableField) {
            return false;
        }
        if ($foreignTableField !== null && (string)($childRow[$foreignTableField] ?? '') !== $parentTable) {
            return false;
        }
        foreach ($config['foreign_match_fields'] ?? [] as $matchField => $matchValue) {
            if ((string)($childRow[$matchField] ?? '') !== (string)$matchValue) {
                return false;
            }
        }
        return true;
    }

    private function getInlineParentService(): InlineParentService
    {
        return $this->inlineParentService ??= GeneralUtility::makeInstance(InlineParentService::class);
    }

    /**
     * The types key FormEngine uses for a record type value: the value itself when
     * configured, else "0", else "1" (DatabaseRecordTypeValue).
     */
    private function resolveTypeKey(array $types, string $recordType): string
    {
        if (!empty($types[$recordType])) {
            return $recordType;
        }
        return !empty($types['0']) ? '0' : '1';
    }
}

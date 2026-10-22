<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves FlexForm DataStructures through TYPO3's FlexFormTools so the
 * DataStructure identifier events run (BeforeFlexFormDataStructureIdentifierInitializedEvent
 * / AfterFlexFormDataStructureParsedEvent). This is essential for dynamic
 * DataStructures such as EXT:form (form_formframework), whose select items
 * and per-finisher override sheets are injected at runtime and absent from
 * the static FlexForm XML file.
 *
 * Shared by GetFlexFormSchemaTool (schema output) and WriteTableTool
 * (DS-aware sheet mapping on write).
 *
 * TYPO3 13 and 14 resolve a DataStructure by opposite mechanisms, so every
 * FlexFormTools call here is version-aware (see coreResolvesFlexFormBySchema):
 *
 * - TYPO3 13 reads the `ds` map off the passed field TCA and picks an entry by
 *   the record's `ds_pointerField` values (tt_content: `list_type,CType`).
 *   Overriding `ds` via columnsOverrides is explicitly forbidden there.
 * - TYPO3 14 removed `ds_pointerField`. `ds` is a single string, and a record
 *   type gets its own DataStructure precisely by overriding that string in
 *   `types.{type}.columnsOverrides.{field}.config.ds` — the shape
 *   ExtensionUtility::registerPlugin() writes. FlexFormTools resolves it from
 *   the table's TcaSchema, which it only has when callers pass it: without the
 *   schema argument it throws InvalidTcaSchemaException (1753182123) before
 *   looking at any `ds` at all.
 */
class FlexFormStructureService implements SingletonInterface
{
    /**
     * Container name FlexFormService skips when converting a section item
     */
    public const ANONYMOUS_CONTAINER = '_arrayContainer';

    protected TcaSchemaFactory $tcaSchemaFactory;

    public function __construct()
    {
        $this->tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
    }

    /**
     * Resolve a FlexForm DataStructure to its normalized array form via
     * TYPO3's FlexFormTools, running the DataStructure identifier events.
     *
     * Returns null when no candidate row yields a DataStructure that actually
     * contains fields (e.g. the identifier is unknown or its extension is not
     * installed).
     */
    public function resolveDataStructure(string $table, string $field, string $identifier, ?int $recordUid): ?array
    {
        return $this->resolveFromCandidates(
            $table,
            $field,
            $this->buildCandidateRows($table, $field, $identifier, $recordUid)
        );
    }

    /**
     * Resolve the FlexForm DataStructure for an actual record row. The row
     * must carry the pointer field values (for tt_content: CType/list_type).
     *
     * The row itself wins when it resolves to a specific (non-default)
     * DataStructure — it carries the real pointer values and, for EXT:form,
     * the selected persistenceIdentifier the DS events need. When the row
     * only reaches FlexFormTools' `default` fallback (e.g. legacy
     * `CType=list` rows after a plugin switched to CType registration, whose
     * ds key `*,<plugin>` is never tried for the legacy pointer values),
     * synthesized rows derived from the row's plugin identifier are tried
     * next. The row's `default` resolution is accepted only as the last
     * resort — for records whose DataStructure legitimately IS the `default`
     * ds entry.
     *
     * Returns null when the row does not resolve to a DataStructure with
     * fields.
     */
    public function resolveDataStructureForRecord(string $table, string $field, array $row): ?array
    {
        $listType = trim((string)($row['list_type'] ?? ''));
        $cType = trim((string)($row['CType'] ?? ''));
        $identifier = $listType !== '' ? $listType : $cType;

        $candidates = [];
        $candidates[] = ['row' => $row, 'allowDefault' => false];
        if ($identifier !== '' && $identifier !== 'list') {
            $candidates[] = ['row' => [$field => '', 'CType' => $identifier, 'list_type' => ''], 'allowDefault' => false];
            $candidates[] = ['row' => [$field => '', 'CType' => 'list', 'list_type' => $identifier], 'allowDefault' => false];
        }
        $candidates[] = ['row' => $row, 'allowDefault' => true];

        return $this->resolveFromCandidates($table, $field, $candidates);
    }

    /**
     * Map every FlexForm field of a parsed DataStructure to the sheet(s)
     * declaring it, keyed by the dotted field name (e.g. "settings.orderBy").
     *
     * A field name normally appears in exactly one sheet; a multi-entry list
     * marks an ambiguous field that callers must treat as an explicit error
     * when writing.
     *
     * @return array<string, list<string>> dotted field name => sheet keys
     */
    public function getFieldSheetMap(array $structure): array
    {
        $map = [];

        foreach (($structure['sheets'] ?? []) as $sheetKey => $sheet) {
            foreach (array_keys($sheet['ROOT']['el'] ?? []) as $fieldName) {
                $map[(string)$fieldName][] = (string)$sheetKey;
            }
        }

        return $map;
    }

    /**
     * Map every section of a parsed DataStructure to its containers, keyed by
     * the dotted field name.
     *
     * A section is a repeatable list. Each stored item holds exactly one of
     * the section's containers, and the container declares the item's
     * subfields.
     *
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     *         dotted field name => container name => subfield name => subfield config
     */
    public function getSectionContainers(array $structure): array
    {
        $sections = [];

        foreach (($structure['sheets'] ?? []) as $sheet) {
            foreach (($sheet['ROOT']['el'] ?? []) as $fieldName => $fieldConfig) {
                if (!$this->isSection($fieldConfig)) {
                    continue;
                }
                foreach (($fieldConfig['el'] ?? []) as $containerName => $container) {
                    $sections[(string)$fieldName][(string)$containerName] = $container['el'] ?? [];
                }
            }
        }

        return $sections;
    }

    /**
     * Whether a DataStructure field is a section — the same test FormEngine
     * and DataHandler apply. ext:form declares `section: true`, FlexForm XML
     * files `<section>1</section>`.
     *
     * @param array<string, mixed> $fieldConfig
     */
    public function isSection(array $fieldConfig): bool
    {
        return (int)($fieldConfig['section'] ?? 0) === 1;
    }

    /**
     * Whether the items of a section carry their subfields directly instead
     * of under a container name.
     *
     * That is the case for a section whose only container is
     * `_arrayContainer` (ext:form's finisher overrides): FlexFormService
     * leaves that container level out when reading, so the client sees and
     * writes {"email": ..., "name": ...} per item.
     *
     * @param array<string, mixed> $containers container name => subfield configs
     */
    public function itemsOmitTheContainerName(array $containers): bool
    {
        return array_keys($containers) === [self::ANONYMOUS_CONTAINER];
    }

    /**
     * Walk candidate rows and return the first DataStructure that resolves,
     * parses, and declares fields.
     *
     * A candidate row must resolve to a specific DataStructure. When the row's
     * type carries no DataStructure of its own, FlexFormTools silently falls
     * back to the `default` ds key (TYPO3 13:
     * getDataStructureIdentifierFromTcaArray, TYPO3 14: the base-field branch
     * of getDataStructureIdentifierFromTcaSchema) — accepting that
     * unconditionally would make every unknown identifier "resolve" to the
     * default DS, so `default` resolutions only count for candidates flagged
     * `allowDefault`.
     *
     * @param array<int, array{row: array<string, mixed>, allowDefault: bool}> $candidates
     */
    private function resolveFromCandidates(string $table, string $field, array $candidates): ?array
    {
        $baseFieldTca = $this->getFlexFieldTca($table, $field);
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        $schema = $this->coreResolvesFlexFormBySchema() ? $this->tcaSchemaFactory->get($table) : null;

        foreach ($candidates as $candidate) {
            // The field TCA is only what the DataStructure events receive; on
            // TYPO3 14 the DataStructure itself comes from the schema. Hand the
            // events the same record-type-resolved TCA that FormEngine hands
            // its own listeners (TcaColumnsOverrides runs before
            // TcaFlexPrepare), so a listener reading `config.ds` sees what the
            // backend form would show it.
            $fieldTca = $schema === null
                ? $baseFieldTca
                : $this->applyRecordTypeOverrides($table, $field, $baseFieldTca, $candidate['row']);

            try {
                $dsIdentifier = $schema === null
                    ? $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $candidate['row'])
                    : $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $candidate['row'], $schema);
            } catch (\Throwable) {
                // Pointer fields did not match a DataStructure for this row —
                // try the next candidate.
                continue;
            }

            if (!$candidate['allowDefault']) {
                $parsed = json_decode($dsIdentifier, true);
                if (is_array($parsed) && ($parsed['dataStructureKey'] ?? null) === 'default') {
                    continue;
                }
            }

            try {
                $structure = $schema === null
                    ? $flexFormTools->parseDataStructureByIdentifier($dsIdentifier)
                    : $flexFormTools->parseDataStructureByIdentifier($dsIdentifier, $schema);
            } catch (\Throwable) {
                continue;
            }

            if ($this->dataStructureHasFields($structure)) {
                return $structure;
            }
        }

        return null;
    }

    /**
     * Build the list of record rows to try when resolving the DataStructure.
     *
     * FlexFormTools derives the DataStructure from a record's pointer fields
     * (for tt_content: `list_type,CType`). Since callers pass an identifier
     * rather than a full record, we synthesize plausible rows — or load the
     * real record when a recordUid is supplied.
     *
     * Each entry is `['row' => array, 'allowDefault' => bool]`. `allowDefault`
     * marks rows whose `default` ds resolution is legitimate — only when the
     * caller explicitly asked for the "default" identifier.
     *
     * @return array<int, array{row: array<string, mixed>, allowDefault: bool}>
     */
    private function buildCandidateRows(string $table, string $field, string $identifier, ?int $recordUid): array
    {
        $candidates = [];
        $allowDefault = ($identifier === 'default');

        // Best case: an actual record. It carries the real pointer values and,
        // for EXT:form, the selected persistenceIdentifier — which the DS event
        // needs to add the finisher-override sheets.
        if ($recordUid !== null) {
            $record = BackendUtility::getRecord($table, $recordUid);
            if (is_array($record)) {
                $candidates[] = ['row' => $record, 'allowDefault' => $allowDefault];
            }
        }

        // The identifier may be a CType (TYPO3 14 / own-CType plugins such as
        // form_formframework), a list_type (legacy plugins such as news_pi1),
        // or a combined ds array key ("*,news_pi1", "news_pi1,list"). Combined
        // keys follow the ds_pointerField order (tt_content: "list_type,CType");
        // "*" is a wildcard placeholder.
        $cType = $identifier;
        $listType = $identifier;
        if (str_contains($identifier, ',')) {
            [$first, $second] = array_map('trim', explode(',', $identifier, 2));
            if ($first !== '*' && $first !== '') {
                $listType = $first;
            }
            if ($second !== '*' && $second !== '') {
                $cType = $second;
            }
        }

        // Candidate 1: identifier is a CType.
        $candidates[] = ['row' => [$field => '', 'CType' => $cType, 'list_type' => ''], 'allowDefault' => $allowDefault];
        // Candidate 2: identifier is a list_type under the generic "list" CType.
        $candidates[] = ['row' => [$field => '', 'CType' => 'list', 'list_type' => $listType], 'allowDefault' => $allowDefault];

        return $candidates;
    }

    /**
     * Whether a parsed DataStructure declares at least one field in any sheet.
     */
    private function dataStructureHasFields(array $structure): bool
    {
        foreach (($structure['sheets'] ?? []) as $sheet) {
            if (!empty($sheet['ROOT']['el'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * TCA column configuration of a FlexForm field. Precondition: the field
     * exists and is of type `flex` — callers validate table/field access
     * before resolving structures.
     *
     * @return array<string, mixed>
     */
    private function getFlexFieldTca(string $table, string $field): array
    {
        $fieldTca = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;
        if (!is_array($fieldTca) || (($fieldTca['config']['type'] ?? '') !== 'flex')) {
            throw new \InvalidArgumentException(
                "Field '$field' in table '$table' is not a FlexForm field",
                1752489601
            );
        }

        return $fieldTca;
    }

    /**
     * Field TCA with the record type's `columnsOverrides` applied, the way
     * TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsOverrides does it for
     * FormEngine. TYPO3 14 only.
     *
     * @param array<string, mixed> $fieldTca
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function applyRecordTypeOverrides(string $table, string $field, array $fieldTca, array $row): array
    {
        $recordType = BackendUtility::getTCAtypeValue($table, $row);
        $overrides = $GLOBALS['TCA'][$table]['types'][$recordType]['columnsOverrides'][$field] ?? [];

        return array_replace_recursive($fieldTca, $overrides);
    }

    /**
     * Whether the running TYPO3 resolves FlexForm DataStructures from the
     * table's TcaSchema instead of from the `ds` map on the field TCA. Changed
     * in TYPO3 14, which added the schema argument to
     * FlexFormTools::getDataStructureIdentifier() and
     * ::parseDataStructureByIdentifier() and made it mandatory in practice —
     * both throw without it.
     */
    private function coreResolvesFlexFormBySchema(): bool
    {
        return GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 14;
    }
}

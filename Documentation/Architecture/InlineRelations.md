# Inline Relations in TYPO3 MCP Tools

## Overview

TYPO3 inline relations (IRRE - Inline Relational Record Editing) allow records to be related to each other in parent-child relationships. The MCP tools support both reading and writing inline relations with some limitations.

## Types of Inline Relations

### 1. Independent Inline Relations
- Tables that exist independently (e.g., `tt_content`)
- Have `hideTable = false` in TCA
- Displayed as UIDs in read results
- Can be managed through foreign field updates

Example:
```php
// News with content elements
$result = $readTool->execute([
    'table' => 'tx_news_domain_model_news',
    'uid' => 123
]);
// Returns: 
// ['content_elements' => [456, 789, 1011]]  // Array of UIDs
```

### 2. Embedded/Dependent Inline Relations
- Tables that only exist as children (e.g., `sys_file_reference`, `tx_news_domain_model_link`)
- Have `hideTable = true` in TCA **AND** are not listed in the `additionalStandaloneTables` extension setting
- Displayed as full embedded records in read results
- Should be created together with parent record

### 3. Standalone Exposure of `hideTable` Children

Some `hideTable=true` tables have inline structure that's too complex to be safely
edited through the parent — for example translations independent of the parent's
language, or rows whose visibility depends on permissions of a different table.
For these, embedding makes the parent's update path either lossy (drops
translations the LLM didn't echo back) or unsafe (replaces rows the LLM never
intended to touch).

The extension setting `additionalStandaloneTables` opts a `hideTable` table out
of embedding. Such tables stay hidden in the TYPO3 backend list module (TCA is
not modified), but the MCP tools treat them as ordinary independent tables:

- `ListTables` shows them
- `ReadTable` / `WriteTable` allow direct CRUD
- The parent's inline field collapses to a list of child UIDs, just like for
  any independent relation

`sys_file_metadata` is on this list by default. It has `hideTable=true` plus
its own `languageField`, while its parent `sys_file` is read-only and not
language-aware — embedding made translations invisible and edits dangerous.
Exposing it standalone gives the LLM normal `update` and `translate` semantics
on title/alternative/description, while the read path on `sys_file` still
yields `metadata: [<uid>, ...]` as a discovery hint.

Mount restrictions on `sys_file` propagate transitively to
`sys_file_metadata` via a subquery in `SysFileMetadataRestrictionListener` —
non-admin users only see metadata for files they could read on `sys_file`.
The same subquery filters orphaned metadata pointing at non-existent files.

Example:
```php
// Page with file references
$result = $readTool->execute([
    'table' => 'pages',
    'uid' => 123
]);
// Returns:
// ['media' => [
//     ['uid' => 1, 'title' => 'Image 1', 'uid_local' => 5, ...],
//     ['uid' => 2, 'title' => 'Image 2', 'uid_local' => 6, ...]
// ]]  // Full record data
```

## Writing Inline Relations

### Method 1: Foreign Field Update (Currently Working)
For independent relations, update the foreign field on child records:

```php
// Create content element related to news
$writeTool->execute([
    'table' => 'tt_content',
    'action' => 'create',
    'pid' => 1,
    'data' => [
        'header' => 'Related content',
        'CType' => 'text',
        'tx_news_related_news' => 789  // Foreign field
    ]
]);
```

### Method 2: Parent Record Write with UIDs or Record Data (Implemented)
For independent relations, each item of the array is one of:

- a UID: link that record,
- `{"uid": N, ...}`: link that record and patch the fields given (through `updateRecord()`),
- record data without a uid: create a new record and link it (through `createRecord()`, so it
  is validated like any other write and may carry inline fields of its own, e.g. `assets`).

On update the list replaces the linked records: records left out are unlinked, not deleted.

The foreign field is often no TCA column of the child table: TYPO3 sets it through
RelationHandler, never through the child's form, and DataHandler silently drops a field it has
no column for. `writeIndependentForeignField()` therefore uses DataHandler only when the field
is a column. Otherwise it writes the field directly on the row the workspace sees: the record
itself if it was created in the workspace, else its workspace version, created first with the
same `version/new` command a backend form save issues. Live stays untouched until publishing.

Linking a live child to another parent changes its foreign field in the workspace version only.
`WorkspaceRestriction` selects no plain versions, so ReadTable additionally looks up the
versions whose foreign field points at the parent and adds their live rows before the workspace
overlay (`fetchChildrenLinkedInWorkspaceOnly()`).

```php
// Keep 456, patch 789, create a new content element
$writeTool->execute([
    'table' => 'tx_news_domain_model_news',
    'action' => 'update',
    'uid' => 123,
    'data' => [
        'content_elements' => [
            456,
            ['uid' => 789, 'header' => 'Updated header'],
            ['CType' => 'text', 'header' => 'New element'],
        ]
    ]
]);
```

### Method 3: Embedded Record Creation (Partially Working)
For dependent relations, pass record data when creating parent:

```php
// Create news with embedded links
$writeTool->execute([
    'table' => 'tx_news_domain_model_news',
    'action' => 'create',
    'pid' => 1,
    'data' => [
        'title' => 'News with links',
        'related_links' => [
            ['title' => 'Link 1', 'uri' => 'https://example.com'],
            ['title' => 'Link 2', 'uri' => 't3://page?uid=42']
        ]
    ]
]);
```

## Current Implementation

### 1. Embedded Relations with Direct Database Updates
- Creating embedded relations through parent record works with a two-step process:
  1. DataHandler creates the child records with placeholder IDs
  2. Direct database UPDATE sets the foreign field after creation
- The foreign field (e.g., `parent` in `tx_news_domain_model_link`) is NOT defined in the child table's TCA columns by design
- DataHandler only processes fields that are defined in TCA columns, so it ignores the foreign field
- **Solution**: After DataHandler creates child records, we directly update the foreign field in the database
- **Working Example**: Creating news with embedded links now works correctly

### 2. File References (sys_file_reference)
- `sys_file_reference` is fully supported as an embedded inline relation (`hideTable=true`)
- It supports workspaces natively (`versioningWS=true` in TCA)
- TCA `type=file` fields are expanded by `TcaPreparation` into inline relations with `foreign_table=sys_file_reference`
- `foreign_match_fields` (`tablenames`, `fieldname`) ensure references are scoped to the correct parent field
- File references are enriched with metadata from `sys_file` (filename, identifier, mime type, public URL)
- `sys_file` itself is read-only - files are managed through the filesystem, not direct DB edits
- New files are created with the `UploadFile` tool (from a URL, a YouTube/Vimeo link, text content,
  or a pre-signed upload); the resulting `sys_file` uid is then referenced via `uid_local`
- A file field of the written record also takes plain `sys_file` uids (`'media' => [3, 4]`),
  short for `[['uid_local' => 3], ['uid_local' => 4]]`. In any other embedded relation of the
  written record an integer is the uid of an existing child, short for `['uid' => N]`.
  Relations nested inside an embedded child take objects only
- A new file reference without `uid_local` is rejected instead of being written without a file
- Crops (`crop`, TCA `type=imageManipulation`) are read and written as an object keyed by crop
  variant: `{"default": {"cropArea": {"x": 0, "y": 0.0062, "width": 1, "height": 0.9876},
  "selectedRatio": "landscape"}}`, coordinates relative (0–1) to the original image.
  `CropVariantService` resolves the variants the way the backend crop editor does: from the
  parent field's `overrideChildTca` (where Content Blocks puts `cropVariants`) with the parent
  record type's `columnsOverrides` applied, falling back to the core default variant.
  `GetTableSchema` lists them on the parent's file field.
- The variants may also be set further up the inline chain. lia_ctypes' static content teaser
  sets them in `tt_content.types[<CType>].columnsOverrides.tx_liactypes_ctypes.config
  .overrideChildTca.columns.image.config.overrideChildTca.columns.crop`: the file field
  belongs to an independent child (`tx_liactypes_domain_model_ctypes`), its variants to the
  element that child is linked to. `InlineFieldConfigService` rebuilds that chain the way
  FormEngine hands it down (`InlineOverrideChildTca`, then `TcaColumnsOverrides`), level by
  level: while `WriteTable` writes children through the parent's inline field, from the
  parent field passed along; for a stored record (or a file reference on it), from its
  foreign field, matching the parent field of the parent's record type (`foreign_field`,
  `foreign_table_field`, `foreign_match_fields`). A record not linked to a parent yet only has
  its own variants. When an inline parent could set them (some inline field to the table has
  `cropVariants` in its `overrideChildTca`), the crop error tells the client to create the
  record through the parent, or link it first. `GetTableSchema` of the child table still
  lists only the child's own variants: the schema has no parent context.
- `WriteTable` rejects a crop before writing anything when it names an unknown or disabled
  variant or ratio, leaves the 0–1 range, or does not have the selected ratio's pixel
  proportion (0.5 % tolerance, skipped when the file has no extracted dimensions); the message
  proposes the largest matching area around the same center. TYPO3 itself never rejects a
  crop: `CropVariantCollection::create()` turns a malformed value into "no crop" and maps an
  unknown variant onto the next configured one.
- Updating a crop replaces only the variants sent; the others keep their stored crop

### 3. Automatic Workspace Handling
- Both ReadTableTool and WriteTableTool automatically initialize workspace context via `WorkspaceContextService`
- The service finds the first writable workspace or creates a new "MCP Workspace" if needed
- All operations (read/write) happen in the same workspace automatically
- No manual workspace management is needed in tests or usage
- Workspace transparency is maintained - tools work consistently across workspaces

### 4. Inline Parents Are Marked as Modified
In the TYPO3 backend an inline child is edited through its parent's form, so saving
it versions the parent as well and the workspace module lists the parent as modified
(e.g. a news record when one of its content elements changed). WriteTable writes
children on their own and only sends changed fields, so `InlineParentService` does
the same explicitly after every write in a workspace:

- The written record and every inline ancestor get a workspace version if they
  don't have one yet (`version` / `new` cmdmap, like DataHandler on a form save).
  Records created in the workspace, or already versioned, deleted or moved in it,
  are left alone.
- Parents are resolved from the child's `foreign_field` (respecting
  `foreign_table_field` and `foreign_match_fields`), on the workspace state of the
  child, recursively up the chain (sys_file_reference → tt_content → news).
  Where a `foreign_table_field` exists (file references: `tablenames`), it and the
  foreign field identify the parent on their own; `fieldname` is not matched, since
  a FlexForm file field is no TCA column of the parent, whose table may not even
  have a file field in TCA.
- Parents the record belonged to before the write count too: deleting a content
  element or pointing it at another news marks the old news as well. So do the
  former parents of independent children a write lists (`content_elements: [300]`
  on another news takes the element away from its news).
- Updating a parent where only its inline children change (embedded links, a
  `content_elements` uid list) marks the parent itself.
- Parents that are not workspace-enabled (e.g. `sys_file` above `sys_file_metadata`)
  or not modifiable by the user are skipped. Relations without `foreign_field`
  (uid list on the parent) can't be resolved from the child and are not covered.

The parent version is an unchanged copy, so reads stay transparent. As on a backend
form save, DataHandler versions the parent's workspace-aware inline children along
with it (`copyRecord_processRelation()`), so the first change below a news record
stages unchanged versions of its other content elements too.

While doing so, `insertNewCopyVersion()` passes the children's live uids through
`RelationHandler::writeForeignField()`, which renumbers their `sorting` (1, 2, 3…, in
workspace order) and bumps `tstamp` directly on the live rows. A backend form save
does the same, but live must not change before publishing (and after a staged
reorder the live order would flip). `InlineParentService` therefore fetches the
parent's inline descendants (live rows and rows created in the workspace) before
versioning and writes back every column that changed afterwards, so only new
version rows remain.

### 5. Sorting and Positioning
- Sorting is handled through `sorting` or `sorting_foreign` fields
- The array order of an inline field is the children's order. Embedded children get
  `foreign_sortby` 256, 512, …; independent children are numbered 1, 2, 3 … like
  `RelationHandler::writeForeignField()` does on a backend form save, written on the row
  the workspace sees (also when the sortby field is `ctrl.sortby` only, not a column).
  `ReadTable` orders inline children by the workspace values after the overlay, so a
  reorder shows before publishing.
- Position management (before/after) not fully implemented for inline relations

## Best Practices

1. **Use Foreign Field Method**: Most reliable method for creating inline relations
2. **Check Table Configuration**: Verify if table has `hideTable` to determine relation type
3. **Validate Data**: Ensure proper validation for inline relation data
4. **Handle Errors**: Check DataHandler error log for detailed error messages
5. **Test Thoroughly**: Inline relations can behave differently in various contexts

## Technical Details

### TCA Configuration
Inline relations are defined in TCA with type 'inline':

```php
'content_elements' => [
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tt_content',
        'foreign_field' => 'tx_news_related_news',
        'foreign_sortby' => 'sorting',
    ]
]
```

### DataHandler Multi-Table Format
DataHandler supports multi-table operations with placeholders:

```php
$dataMap = [
    'parent_table' => [
        'NEW123' => ['field' => 'value']
    ],
    'child_table' => [
        'NEW456' => ['other_field' => 'value']  // Note: foreign field NOT included
    ]
];
// After DataHandler processing, foreign fields are updated directly:
$connection->update('child_table', ['parent_field' => $parentUid], ['uid' => $childUid]);
```

## Future Improvements

1. **Batch Operations**: Support for bulk inline relation updates
2. **Position Management**: Full support for positioning inline records (before/after specific records)
3. **Validation Enhancement**: More comprehensive validation for embedded record data
4. **Performance Optimization**: Batch foreign field updates instead of individual UPDATE queries
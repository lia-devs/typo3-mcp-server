# Inline Relations

How `ReadTable` and `WriteTable` handle TCA fields of type `inline` and `file`, and
why writing them takes more than a single DataHandler run.

## Two kinds of child tables

Every inline field points at a child table (`foreign_table`). The MCP treats the
children in one of two ways, decided by `TableAccessService::isEmbeddedChildTable()`:

| | Embedded children | Independent children |
|---|---|---|
| Child table | `hideTable = true` in TCA | everything else |
| Examples | `sys_file_reference`, `tx_news_domain_model_link`, Content Blocks collections | `tt_content` below a news record |
| Read result | full child records inside the parent | list of child uids |
| Write input | list of child records | list of child uids |
| Own CRUD | no, only through the parent | yes, like any table |

```php
// Read: embedded file references, independent content elements
['media' => [['uid' => 1, 'uid_local' => 5, 'alternative' => '…'], …],
 'content_elements' => [456, 789]]
```

### Standalone `hideTable` tables

Some `hideTable` tables are too complex to edit through their parent, e.g. because
they carry their own translations. The extension setting `additionalStandaloneTables`
lists tables that are treated as independent tables despite `hideTable`: `ListTables`
shows them, `ReadTable`/`WriteTable` allow direct CRUD, and the parent's field becomes
a uid list. The TCA is not changed, so they stay hidden in the backend list module.

`sys_file_metadata` is on the list by default: it has its own `languageField`, while
its parent `sys_file` is read-only and not language-aware. Embedding it made
translations invisible and edits through `sys_file` impossible. Mount restrictions of
`sys_file` apply to it through `SysFileMetadataRestrictionListener`, which also hides
metadata of files that no longer exist.

## Writing embedded children

The value is the complete list of children; the array order is the display order.

```php
'related_links' => [
    ['uid' => 12, 'title' => 'Changed title'],   // existing child: patched
    ['title' => 'New link', 'uri' => 'https://example.com'], // no uid: created
]
// Existing children missing from the list are deleted.
```

- A `uid` must belong to the current parent. Children cannot be moved between parents;
  a foreign uid is rejected instead of silently deleting the real children.
- When a workspace is active, an existing child is written on its workspace version, never on the live row.
- Children can have embedded children themselves (a file inside a collection item).
  Nested relations to independent tables are rejected: those records need their own
  write so the client gets their uids back.
- A file field of the written record also takes plain `sys_file` uids (`'media' => [3, 4]`),
  short for `[['uid_local' => 3], ['uid_local' => 4]]`. In any other embedded relation of the
  written record an integer is the uid of an existing child, short for `['uid' => N]`.
  Relations nested inside an embedded child take objects only.
- A new file reference without `uid_local` is rejected instead of being written without a file.

### Why it takes several steps

DataHandler only writes columns that are defined in the child table's TCA. The
columns that tie a child to its parent usually are not:

- the foreign field (`foreign_field`, e.g. `parent`),
- `foreign_table_field` (e.g. `tablenames`) and the `foreign_match_fields`
  (e.g. `fieldname`) of tables that don't declare them as columns.

DataHandler drops these values from the datamap, and TYPO3 can't find the child
again: the backend form, the page module and the Record API show an empty relation.
`WriteTable` therefore writes the parent and children in one DataHandler run and then
finishes the job itself (`finalizeEmbeddedInlineRelations()`):

1. **Link new children**: one direct `UPDATE` per new child sets the foreign field
   (the parent's live uid), `foreign_table_field` and `foreign_match_fields`. Nested
   children are linked the same way (`writePendingNestedForeignFields()`).
2. **Enforce the order**: DataHandler numbers the sortby field itself (new records at
   the top, so several new children end up reversed), so the array order is applied
   afterwards with a chain of `move after` commands, as the backend does.
3. **Parent bookkeeping**: because the children were linked after the parent was
   written, the parent field's counter column is still 0 and `sys_refindex` has no
   rows for the relation. `syncInlineParentBookkeeping()` sets both, on the parent's
   workspace version only. Without the `sys_refindex` rows the workspace dependency
   resolver doesn't see the children, and publishing the parent would leave them in
   the workspace.

`ReferenceIndex::updateRefIndexTable()` is `@internal`, but it is what the
`referenceindex:update` command uses; core has no public per-record API.

### Crops of file references

- Crops (`crop`, TCA `type=imageManipulation`) are read and written as an object keyed by crop
  variant: `{"default": {"cropArea": {"x": 0, "y": 0.0062, "width": 1, "height": 0.9876},
  "selectedRatio": "landscape"}}`, coordinates relative (0–1) to the original image.
  `CropVariantService` resolves the variants the way the backend crop editor does: from the
  parent field's `overrideChildTca` (where Content Blocks puts `cropVariants`) with the parent
  record type's `columnsOverrides` applied, falling back to the core default variant.
  `GetTableSchema` lists them on the parent's file field.
- The variants may also be set further up the inline chain. A teaser element whose items are
  independent inline children sets them in `tt_content.types[<CType>].columnsOverrides.<items field>.config
  .overrideChildTca.columns.image.config.overrideChildTca.columns.crop`: the file field
  belongs to an independent child (the item record), its variants to the
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

## Writing independent children

Each item of the list is one of:

- a UID: link that record,
- `{"uid": N, ...}`: link that record and patch the fields given (through `updateRecord()`),
- record data without a uid: create a new record and link it (through `createRecord()`, so it
  is validated like any other write and may carry inline fields of its own, e.g. `assets`).

```php
// Keep 456, patch 789, create a new content element
'content_elements' => [
    456,
    ['uid' => 789, 'header' => 'Updated header'],
    ['CType' => 'text', 'header' => 'New element'],
]
```

A child can also be attached from its own side by writing its foreign field, e.g.
`tt_content.tx_news_related_news`.

On update the list replaces the linked records. A record left out that is linked live is deleted
(in a workspace: a delete placeholder), as when it is removed in the backend form: TYPO3 has no
detached state for an independent child that survives publishing - publishing the parent points
every child whose live row names it back at it, so a child only unlinked in the workspace would be
linked again live. A record left out that was linked in the workspace only gets that link taken
back and stays the record of its own it was before; if its workspace version held nothing but the
link (pointer, parent table, match fields, position), the version is discarded, so nothing is left
to publish.

### How a child is linked

`writeIndependentForeignField()` writes what `RelationHandler::writeForeignField()` writes when
the backend saves the parent's form: the parent pointer (`foreign_field`), the parent's table
(`foreign_table_field`, for a child table shared by several parent tables),
`foreign_match_fields`, the position and the change time, and it updates the child's own
`sys_refindex` rows. These fields belong to the parent's relation, not to the child's form, so
they never go through DataHandler: it silently drops a field that is no TCA column of the child
(often the case, TYPO3 only ever sets it through the relation) and an exclude field the editor
lacks permission for, and the write would report success with every child detached (#137).

Saving the parent's field through DataHandler instead is no alternative: `RelationHandler` writes
the uids it is given, and for an existing live child those are live rows - in a workspace, linking
or reordering would go live at once. The backend never links existing records to another parent,
it creates children in the form, so the core path does not cover this case.

The parent's field itself is left unchanged by this, so it gets the number of its children after
linking (`writeInlineChildCounts()`, #136). Extbase skips a property whose column is NULL.

### Permissions

The child is still a record the editor changes. Before the first row of a relation field is
written, every child to link, unlink or delete is checked (for a page to delete: the permission to
delete it), so a refused child leaves that field's relation as it was - DataHandler may still refuse
a deletion the checks cannot see, such as a hook; the children are deleted first, so then nothing
is linked: it must exist as a live record and must not be deleted in the workspace; on the row the
workspace sees (a child moved in the workspace lies on its new page) it must lie in the editor's
page tree, on a page where they may edit content and that is not locked for editing, and it must
pass the record checks (edit lock, language: `checkRecordEditAccess()`, on TYPO3 13
`recordEditAccessInternals()`). The child's exclude fields do not matter. A child table without
workspace support is refused in a workspace: its rows are live rows. The check covers one relation
field; with several in one write, an earlier field may already be written when a later one is
refused.

### In a workspace

The fields are written on the row the workspace sees: the record itself if it was created in the
workspace, else its workspace version, created first with the same `version/new` command a
backend form save issues. Live stays untouched until publishing.

Which children a parent has is decided on the row the workspace sees, by `foreign_match_fields`
and the parent's table: a child linked in the workspace only counts (and is unlinked again by the
next list without it), one whose workspace version points at another parent does not. A page and a
content element with the same uid neither list nor delete each other's children. ReadTable adds
the live rows of children linked in the workspace only (`fetchChildrenLinkedInWorkspaceOnly()`,
as `WorkspaceRestriction` selects no plain versions), keeps after the workspace overlay only the
children whose workspace row points at the parent, and orders them by their workspace values.

**Publishing.** Linking or reordering an existing child changes the child's own workspace
version. TYPO3's workspace dependencies follow `sys_refindex`, which lists a parent's children by
their live pointer: a child linked in the workspace only is not among them, so publishing only the
parent leaves its link in the workspace (children still linked live, and deleted ones, go along).
The write result names the children it changed (`changed_inline_children`) so they can be
published together with the parent.

### Order

The array order of an inline field is the children's order. Embedded children get
`foreign_sortby` 256, 512, …; independent children are numbered 1, 2, 3 … like
`RelationHandler::writeForeignField()` does on a backend form save (`foreign_sortby`, else the
child table's `sortby`), written on the row the workspace sees (also when the sortby field is
`ctrl.sortby` only, not a column). `ReadTable` orders inline children by the workspace values
after the overlay, so a reorder shows before publishing.

## Workspaces

Like everything else, inline relations are written to the workspace and read back as
if there were none: children carry their live uids, records created in the workspace
their own uid.

### Parents are versioned with their children

In the backend a child is edited through its parent's form, so saving it versions the
parent too and the workspace module lists the parent as modified. `WriteTable` writes
children on their own, so `InlineParentService::markModified()` does the same after
every write in a workspace:

- The written record and every inline ancestor get a workspace version if they don't
  have one (`version` / `new` cmdmap). Records created, versioned, deleted or moved in
  the workspace are left alone.
- Parents are resolved from the child's foreign field on the child's workspace state,
  recursively (`sys_file_reference` → `tt_content` → news). Where a
  `foreign_table_field` exists, it and the foreign field identify the parent;
  `fieldname` is not matched, since a FlexForm file field is no TCA column.
- Former parents count too: deleting a content element, pointing it at another news or
  listing it in another news' `content_elements` marks the old news as well.
- Parents that are not workspace-aware (e.g. `sys_file`) or not modifiable by the user
  are skipped.

The parent version is an unchanged copy, so reads stay transparent. As on a form save,
DataHandler versions the parent's workspace-aware children along with it, so the first
change below a news record stages unchanged versions of its other content elements.

While doing so, core renumbers the children's `sorting` and bumps their `tstamp`
directly on the live rows (`RelationHandler::writeForeignField()`). Live must not
change before publishing, so `InlineParentService` reads the descendants before
versioning and writes back every changed column afterwards.

### Grouping in the workspace module

The workspace module groups a parent with its children by the parent's `sys_refindex`
rows in the workspace. DataHandler only reindexes the record it writes, so
`InlineParentService` also recomputes the rows of the written record and every
ancestor.

## Known gaps

- **Relations without `foreign_field`** (uid list stored on the parent) are not
  written and can't be traced from child to parent.
- **Reassigned children in the workspace module**: core resolves foreign field
  children from the live rows, so a child pointed at another parent in the workspace
  stays grouped below its old parent until it is published. Publishing itself is
  correct.

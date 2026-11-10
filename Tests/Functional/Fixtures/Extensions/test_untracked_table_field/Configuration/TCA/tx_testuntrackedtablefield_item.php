<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'List item',
        'label' => 'title',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Embedded: the items exist only inside their content element.
        'hideTable' => true,
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    // The fields this fixture exists for: parent_uid and parent_table are no columns.
    // TYPO3 sets both through the parent's inline relation; DataHandler drops a value
    // for a field without a column (#158). Unlike Content Blocks, which declares them
    // as passthrough columns, some extensions leave them out.
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        // Nested level: an item's own items, linked the same way.
        'subitems' => [
            'label' => 'Items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testuntrackedtablefield_item',
                'foreign_field' => 'parent_uid',
                'foreign_table_field' => 'parent_table',
                'foreign_sortby' => 'sorting',
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title, subitems'],
    ],
];

<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Shared item',
        'label' => 'title',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // No hideTable: the records are independent and readable on their own.
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    // The two fields this fixture exists for: the parent pointer parent_uid is an
    // exclude field an editor may lack permission for, and parent_table, which names
    // the parent's table, is no column at all. Both are only ever set through the
    // parent's inline relation.
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        'parent_uid' => [
            'exclude' => true,
            'label' => 'Parent',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title'],
    ],
];

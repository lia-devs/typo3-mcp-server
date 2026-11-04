<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Teaser',
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
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    // The field this fixture exists for: the parent pointer content_element is no
    // column here, it is only ever set through the parent's inline relation.
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        'image' => [
            'label' => 'Image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title, image'],
    ],
];

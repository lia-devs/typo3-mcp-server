<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Test FlexForm',
    'description' => 'Test fixture extension providing a content type with a multi-sheet FlexForm for functional tests',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];

<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Crop variants on the parent field, the way Content Blocks writes them: sys_file_reference
// itself keeps its default crop configuration.
$GLOBALS['TCA']['tt_content']['columns']['tx_testcropvariants_image'] = [
    'label' => 'Cropped image',
    'config' => [
        'type' => 'file',
        'allowed' => 'common-image-types',
        'overrideChildTca' => [
            'columns' => [
                'crop' => [
                    'config' => [
                        'cropVariants' => [
                            'default' => [
                                'title' => 'Default',
                                'allowedAspectRatios' => [
                                    'landscape' => ['title' => '3:2', 'value' => 1.5],
                                ],
                                'selectedRatio' => 'landscape',
                            ],
                            'mobile' => [
                                'title' => 'Mobile',
                                'allowedAspectRatios' => [
                                    'square' => ['title' => '1:1', 'value' => 1.0],
                                    'NaN' => ['title' => 'Free', 'value' => 0.0],
                                ],
                            ],
                            'retired' => [
                                'title' => 'Retired',
                                'disabled' => true,
                                'allowedAspectRatios' => [
                                    'square' => ['title' => '1:1', 'value' => 1.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] =
    ($GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] ?? '') . ',tx_testcropvariants_image';

// The image type adds a variant to the same field through columnsOverrides.
$GLOBALS['TCA']['tt_content']['types']['image']['showitem'] =
    ($GLOBALS['TCA']['tt_content']['types']['image']['showitem'] ?? '') . ',tx_testcropvariants_image';
$GLOBALS['TCA']['tt_content']['types']['image']['columnsOverrides']['tx_testcropvariants_image']['config']['overrideChildTca']['columns']['crop']['config']['cropVariants']['teaser'] = [
    'title' => 'Teaser',
    'allowedAspectRatios' => [
        'wide' => ['title' => '16:9', 'value' => 16 / 9],
    ],
];

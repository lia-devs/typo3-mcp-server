<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['tx_testuntrackedforeignfield_items'] = [
    'label' => 'Teasers',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testuntrackedforeignfield_item',
        'foreign_field' => 'content_element',
        'foreign_sortby' => 'sorting',
    ],
];

$GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] =
    ($GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] ?? '') . ',tx_testuntrackedforeignfield_items';

// A teaser type that sets the crop variants of the children's image the way content
// element extensions with teaser items do: two overrideChildTca levels deep, so the
// variants exist only for a child linked to an element of this type.
$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = [
    'label' => 'Teaser with cropped images',
    'value' => 'testuntrackedforeignfield_teaser',
];
$GLOBALS['TCA']['tt_content']['types']['testuntrackedforeignfield_teaser'] = [
    'showitem' => 'CType, header, tx_testuntrackedforeignfield_items',
    'columnsOverrides' => [
        'tx_testuntrackedforeignfield_items' => [
            'config' => [
                'overrideChildTca' => [
                    'columns' => [
                        'image' => [
                            'config' => [
                                'overrideChildTca' => [
                                    'columns' => [
                                        'crop' => [
                                            'config' => [
                                                'cropVariants' => [
                                                    'teaser' => [
                                                        'title' => 'Teaser',
                                                        'allowedAspectRatios' => [
                                                            '3:2' => ['title' => '3:2', 'value' => 3 / 2],
                                                        ],
                                                        'selectedRatio' => '3:2',
                                                    ],
                                                    'tile' => [
                                                        'title' => 'Tile',
                                                        'allowedAspectRatios' => [
                                                            '1:1' => ['title' => '1:1', 'value' => 1.0],
                                                        ],
                                                        'selectedRatio' => '1:1',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

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
